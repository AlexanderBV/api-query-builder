<?php

declare(strict_types=1);

namespace Warrior\RestProcessor\Pipes;

use Closure;
use Warrior\RestProcessor\Constants\QueryParameter;
use Warrior\RestProcessor\Context\ProcessorContext;
use Warrior\RestProcessor\Exceptions\ProcessorValidationException;

/**
 * Pipe responsable de la carga anticipada de relaciones (Eager Loading con with())
 * para prevenir estrictamente problemas de N+1 queries, y de conteos relacionales (withCount()).
 *
 * Ambos parámetros (?include=roles,department y ?count=comments,orders) están gobernados
 * por listas blancas explícitas (allowedIncludes y allowedCounts) para evitar la filtración
 * accidental de relaciones sensibles o ejecuciones de subqueries costosas.
 */
final class IncludePipe
{
    /**
     * Procesa la inclusión y conteo de relaciones en el Pipeline.
     *
     * @param ProcessorContext $context Contexto de ejecución activo
     * @param Closure(ProcessorContext): ProcessorContext $next Siguiente Pipe en la cadena
     * @return ProcessorContext
     * @throws ProcessorValidationException Si se solicita una relación o conteo no autorizado
     */
    public function handle(ProcessorContext $context, Closure $next): ProcessorContext
    {
        // -------------------------------------------------------------------------
        // Fase 1: Eager Loading de Relaciones (?include=roles,department)
        // -------------------------------------------------------------------------
        $includeParam = $context->config->param(QueryParameter::INCLUDE);
        $rawIncludes = $context->request->query($includeParam);

        if ($rawIncludes !== null && $rawIncludes !== '') {
            // Soportar tanto formato CSV (?include=roles,department) como array (?include[]=roles)
            $includes = is_array($rawIncludes) ? $rawIncludes : explode(',', (string) $rawIncludes);
            $validIncludes = [];

            foreach ($includes as $relation) {
                $relation = trim((string) $relation);
                if ($relation === '') {
                    continue;
                }

                // Verificación de Lista Blanca: la relación debe estar explícitamente en allowedIncludes
                if (!in_array($relation, $context->config->allowedIncludes, true)) {
                    throw ProcessorValidationException::forField(
                        QueryParameter::INCLUDE,
                        "La relación '{$relation}' no está autorizada para inclusión."
                    );
                }

                $validIncludes[] = $relation;
            }

            // Aplicar eager loading para hidratar las relaciones en una sola consulta adicional sin N+1
            if (!empty($validIncludes)) {
                $context->builder->with($validIncludes);
            }
        }

        // -------------------------------------------------------------------------
        // Fase 2: Conteos Agregados de Relaciones (?count=comments,orders)
        // -------------------------------------------------------------------------
        $countParam = $context->config->param(QueryParameter::COUNT);
        $rawCounts = $context->request->query($countParam);

        if ($rawCounts !== null && $rawCounts !== '') {
            // Soportar tanto formato CSV (?count=comments,orders) como array
            $counts = is_array($rawCounts) ? $rawCounts : explode(',', (string) $rawCounts);
            $validCounts = [];

            foreach ($counts as $countRel) {
                $countRel = trim((string) $countRel);
                if ($countRel === '') {
                    continue;
                }

                // Verificación de Lista Blanca: la relación debe estar explícitamente en allowedCounts
                if (!in_array($countRel, $context->config->allowedCounts, true)) {
                    throw ProcessorValidationException::forField(
                        QueryParameter::COUNT,
                        "El conteo de la relación '{$countRel}' no está autorizado."
                    );
                }

                $validCounts[] = $countRel;
            }

            // Aplicar withCount() para inyectar los atributos virtuales (ej: comments_count)
            if (!empty($validCounts)) {
                $context->builder->withCount($validCounts);
            }
        }

        return $next($context);
    }
}
