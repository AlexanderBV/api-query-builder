<?php

declare(strict_types=1);

namespace Warrior\ApiQueryBuilder\Pipes;

use Closure;
use Warrior\ApiQueryBuilder\Constants\QueryParameter;
use Warrior\ApiQueryBuilder\Context\ProcessorContext;
use Warrior\ApiQueryBuilder\Enums\SortDirection;
use Warrior\ApiQueryBuilder\Exceptions\ProcessorValidationException;

/**
 * Pipe responsable del ordenamiento seguro, controlado y determinista de la consulta (?sort=-created_at,name).
 *
 * En V1, el ordenamiento es exclusivo para columnas directas de la tabla base (allowedSorts),
 * previniendo el producto cartesiano y la duplicación de filas en la paginación que causan los JOINs relacionales.
 * Aplica automáticamente la clave primaria del modelo como criterio final de desempate determinista.
 */
final class SortPipe
{
    /**
     * Procesa la etapa de ordenamiento en el Pipeline de ejecución.
     *
     * @param ProcessorContext $context Contexto de ejecución activo
     * @param Closure(ProcessorContext): ProcessorContext $next Siguiente Pipe en la cadena
     * @return ProcessorContext
     * @throws ProcessorValidationException Si se solicita una columna no permitida o un ordenamiento relacional
     */
    public function handle(ProcessorContext $context, Closure $next): ProcessorContext
    {
        // Paso 1: Obtener el nombre del parámetro HTTP configurado (por defecto 'sort')
        $sortParam = $context->config->param(QueryParameter::SORT);
        $rawSort = $context->request->query($sortParam);

        $appliedColumns = [];

        // Paso 2: Si el cliente envió parámetros de ordenamiento, parsear lista (CSV o array)
        if ($rawSort !== null && $rawSort !== '') {
            $sortFields = is_array($rawSort) ? $rawSort : explode(',', (string) $rawSort);

            foreach ($sortFields as $sortField) {
                $sortField = trim((string) $sortField);
                if ($sortField === '') {
                    continue;
                }

                // Paso 3: Detectar dirección de ordenamiento: prefijo '-' indica descendente, sin prefijo es ascendente
                $isDesc = str_starts_with($sortField, '-');
                $direction = $isDesc ? SortDirection::DESC->value : SortDirection::ASC->value;
                $column = ltrim($sortField, '-');

                // Paso 4: En V1, rechazo estricto de notación de punto (ordenamiento a través de relaciones).
                // Ordenar por relaciones requiere JOINs que multiplican registros y corrompen el conteo del paginador.
                if (str_contains($column, '.')) {
                    throw ProcessorValidationException::forField(
                        QueryParameter::SORT,
                        "El ordenamiento relacional ('{$column}') no está permitido en V1 para preservar la integridad de la paginación."
                    );
                }

                // Paso 5: Verificación de Lista Blanca: el campo debe estar explícitamente en allowedSorts
                if (!in_array($column, $context->config->allowedSorts, true)) {
                    throw ProcessorValidationException::forField(
                        QueryParameter::SORT,
                        "El campo '{$column}' no está autorizado para ordenamiento."
                    );
                }

                // Paso 6: Aplicar cláusula ORDER BY sobre el query builder
                $context->builder->orderBy($column, $direction);
                $appliedColumns[] = $column;
            }
        }

        // Paso 7: Si el cliente no especificó ningún orden válido, aplicar el orden por defecto configurado
        if (empty($appliedColumns)) {
            $context->builder->orderBy(
                $context->config->defaultSortColumn,
                $context->config->defaultSortDirection
            );
            $appliedColumns[] = $context->config->defaultSortColumn;
        }

        // Paso 8: Desempate determinista automático con la clave primaria del modelo (ej: 'id').
        // Garantiza que dos registros con idéntico valor en el campo ordenado siempre aparezcan
        // en la misma página de resultados independientemente del motor SQL subyacente.
        $primaryKey = $context->builder->getModel()->getKeyName();
        if ($primaryKey && !in_array($primaryKey, $appliedColumns, true)) {
            $context->builder->orderBy($primaryKey, SortDirection::DESC->value);
        }

        return $next($context);
    }
}
