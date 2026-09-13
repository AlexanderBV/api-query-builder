<?php

declare(strict_types=1);

namespace Warrior\ApiQueryBuilder\Pipes;

use Closure;
use Warrior\ApiQueryBuilder\Constants\QueryParameter;
use Warrior\ApiQueryBuilder\Context\ProcessorContext;
use Warrior\ApiQueryBuilder\Enums\PaginationMode;
use Warrior\ApiQueryBuilder\Exceptions\ProcessorValidationException;

/**
 * Pipe responsable de resolver la paginación o la recuperación total sin paginar (?all=true).
 *
 * Aplica validación booleana estricta para ?all, verifica la autorización de allowAll(),
 * ejecuta una salvaguarda anti-OOM (Out Of Memory) mediante COUNT preventivo,
 * y enruta dinámicamente entre los modos de paginación de Laravel (page, simple, cursor).
 */
final class PaginationPipe
{
    /**
     * Procesa la paginación o la colección completa en el Pipeline.
     *
     * @param  ProcessorContext  $context  Contexto de ejecución activo
     * @param  Closure(ProcessorContext): ProcessorContext  $next  Siguiente Pipe en la cadena
     * @return ProcessorContext Contexto con el resultado inyectado (Paginator o Collection)
     *
     * @throws ProcessorValidationException Si all=true no está autorizado, supera el límite o per_page es inválido
     */
    public function handle(ProcessorContext $context, Closure $next): ProcessorContext
    {
        // -------------------------------------------------------------------------
        // Fase 1: Evaluación del Flag de Recuperación Total (?all=true)
        // -------------------------------------------------------------------------
        $allParam = $context->config->param(QueryParameter::ALL);
        $rawAll = $context->request->query($allParam);

        if ($rawAll !== null) {
            // Validar estrictamente que el valor sea un booleano admisible ('true', 'false', '1', '0')
            $isAll = filter_var($rawAll, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

            if ($isAll === null) {
                throw ProcessorValidationException::forField(
                    QueryParameter::ALL,
                    "El parámetro '{$allParam}' debe ser un valor booleano válido (true/false/1/0)."
                );
            }

            if ($isAll === true) {
                // Verificación de Autorización: allowAll() debe haber sido habilitado explícitamente en el endpoint
                if (! $context->config->allowAll) {
                    throw ProcessorValidationException::forField(
                        QueryParameter::ALL,
                        'La recuperación total de registros (all=true) no está permitida para este recurso.'
                    );
                }

                // Salvaguarda Anti-OOM: ejecutar COUNT(*) previo antes de hidratar los modelos en memoria
                $count = (clone $context->builder)->count();
                if ($count > $context->config->maxAllLimit) {
                    throw ProcessorValidationException::forField(
                        QueryParameter::ALL,
                        "El total de registros ({$count}) excede el límite máximo permitido para all=true ({$context->config->maxAllLimit})."
                    );
                }

                // Hidratar la colección completa de Eloquent y continuar el pipeline
                $collection = $context->builder->get();

                return $next($context->withResult($collection));
            }
        }

        // -------------------------------------------------------------------------
        // Fase 2: Resolución del Tamaño de Página (?per_page=25)
        // -------------------------------------------------------------------------
        $perPageParam = $context->config->param(QueryParameter::PER_PAGE);
        $rawPerPage = $context->request->query($perPageParam);

        $perPage = $context->config->defaultPageSize;

        if ($rawPerPage !== null && $rawPerPage !== '') {
            if (! is_numeric($rawPerPage)) {
                throw ProcessorValidationException::forField(
                    QueryParameter::PER_PAGE,
                    "El parámetro '{$perPageParam}' debe ser un número entero."
                );
            }

            $perPage = (int) $rawPerPage;

            // Validar que el tamaño solicitado respete los límites de seguridad del endpoint
            if ($perPage < 1 || $perPage > $context->config->maxPageSize) {
                throw ProcessorValidationException::forField(
                    QueryParameter::PER_PAGE,
                    "El parámetro '{$perPageParam}' debe ser un entero entre 1 y {$context->config->maxPageSize}."
                );
            }
        }

        // -------------------------------------------------------------------------
        // Fase 3: Selección del Modo de Paginación (page, simple, cursor)
        // -------------------------------------------------------------------------
        $paginationModeParam = $context->config->param(QueryParameter::PAGINATION);
        $rawMode = $context->request->query($paginationModeParam);
        $modeEnum = PaginationMode::fromString($rawMode !== null ? (string) $rawMode : null);

        // Extraer número de página explícito si fue provisto
        $pageParam = $context->config->param(QueryParameter::PAGE);
        $rawPage = $context->request->query($pageParam);
        $pageNumber = ($rawPage !== null && is_numeric($rawPage)) ? (int) $rawPage : null;

        // Ejecutar la estrategia de paginación de Eloquent seleccionada
        $paginator = match ($modeEnum) {
            PaginationMode::SIMPLE => $context->builder->simplePaginate($perPage, ['*'], $pageParam, $pageNumber),
            PaginationMode::CURSOR => $context->builder->cursorPaginate($perPage),
            PaginationMode::PAGE => $context->builder->paginate($perPage, ['*'], $pageParam, $pageNumber),
        };

        return $next($context->withResult($paginator));
    }
}
