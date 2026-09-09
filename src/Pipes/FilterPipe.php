<?php

declare(strict_types=1);

namespace Warrior\RestProcessor\Pipes;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Str;
use Warrior\RestProcessor\Constants\LogicalOperator;
use Warrior\RestProcessor\Constants\QueryParameter;
use Warrior\RestProcessor\Context\ProcessorContext;
use Warrior\RestProcessor\Enums\Operator;
use Warrior\RestProcessor\Exceptions\ProcessorValidationException;

/**
 * Pipe responsable de analizar, validar y aplicar el árbol de filtros sobre la consulta Eloquent.
 *
 * Capacidades principales:
 * - Filtros planos por columna directa (igualdad implícita o mapa explícito de operadores).
 * - Tolerancia de listas CSV y arrays en operadores 'in', 'not_in' y rangos 'between'.
 * - Inyección no destructiva de filtros por defecto (defaultFilters) si el cliente omite el campo.
 * - Descarte automático de cadenas vacías o valores nulos (?filter[status]=).
 * - Filtros personalizados ad-hoc mediante Closures (Filter::custom()).
 * - Local Scopes del modelo Eloquent invocados automáticamente por convención (scopeActive, scopeTrashed).
 * - Filtros relacionales mediante subconsultas seguras (whereHas / orWhereHas).
 * - Filtros sobre atributos anidados en columnas JSON (extra_data->client->code).
 * - Árboles jerárquicos anidados con operadores lógicos AND / OR (SPEC-007).
 */
final class FilterPipe
{
    /**
     * Procesa la etapa de filtros en el Pipeline de ejecución.
     *
     * @param ProcessorContext $context Contexto de ejecución activo
     * @param Closure(ProcessorContext): ProcessorContext $next Siguiente Pipe en la cadena
     * @return ProcessorContext Contexto enriquecido tras aplicar los filtros
     * @throws ProcessorValidationException Si la estructura de filtros o algún operador es inválido
     */
    public function handle(ProcessorContext $context, Closure $next): ProcessorContext
    {
        // Paso 1: Extraer el parámetro de filtros configurado (por defecto 'filter')
        $filterParam = $context->config->param(QueryParameter::FILTER);
        $rawFilters = $context->request->query($filterParam, []);

        // Paso 2: Validar el tipo de dato recibido. Si no es un array, normalizar o rechazar con 422
        if (!is_array($rawFilters)) {
            if ($rawFilters === '' || $rawFilters === null) {
                $rawFilters = [];
            } else {
                throw ProcessorValidationException::forField(
                    QueryParameter::FILTER,
                    'La estructura de filtros debe ser un objeto o mapa asociativo.'
                );
            }
        }

        // Paso 3: Inyectar filtros por defecto configurados en el endpoint (defaultFilters)
        // Solo se aplican si la clave fue omitida por completo en la petición del cliente
        foreach ($context->config->defaultFilters as $defaultKey => $defaultValue) {
            if (!array_key_exists($defaultKey, $rawFilters)) {
                $rawFilters[$defaultKey] = $defaultValue;
            }
        }

        // Paso 4: Si tras la combinación no existen filtros activos, delegar al siguiente pipe
        if (empty($rawFilters)) {
            return $next($context);
        }

        // Paso 5: Separar Local Scopes del modelo a nivel raíz.
        // Ciertos scopes mutan el estado global del Builder (por ejemplo, Soft Deletes con withTrashed).
        // Para que estos métodos surtan efecto en toda la consulta principal, deben ejecutarse
        // directamente sobre $context->builder y no confinados dentro de una subconsulta agrupada.
        $model = $context->builder->getModel();
        $rootScopes = [];
        $otherFilters = [];

        foreach ($rawFilters as $key => $value) {
            $keyStr = (string) $key;

            // Si es un operador lógico ('and' / 'or') o un campo relacional ('roles.name'), va a filtros agrupados
            if (!LogicalOperator::isLogical($keyStr) && !str_contains($keyStr, '.')) {
                $scopeMethod = 'scope' . Str::studly($keyStr);
                if (method_exists($model, $scopeMethod)) {
                    $rootScopes[$keyStr] = $value;
                    continue;
                }
            }

            $otherFilters[$key] = $value;
        }

        // Paso 6: Aplicar y validar los Local Scopes raíz identificados
        foreach ($rootScopes as $field => $val) {
            // Descartar valores vacíos
            if ($val === '' || $val === null) {
                continue;
            }

            // Verificar si el scope está autorizado en la lista blanca de filtros
            if (!$context->config->isFilterAllowed($field)) {
                throw ProcessorValidationException::forField(
                    QueryParameter::FILTER . ".{$field}",
                    "El filtro '{$field}' no está autorizado para este recurso."
                );
            }

            // Normalizar valores booleanos string ('true' / 'false' / '1' / '0') a boolean puro
            $parsedVal = $this->parseBooleanIfApplicable($val);
            $context->builder->{$field}($parsedVal);
        }

        // Paso 7: Aplicar el resto de filtros dentro de una cláusula WHERE agrupada
        // Esto aísla las condiciones y preserva la integridad de cualquier scope global preexistente
        if (!empty($otherFilters)) {
            $context->builder->where(function (Builder $query) use ($otherFilters, $context): void {
                $this->applyFilterNode($query, $otherFilters, LogicalOperator::AND, $context);
            });
        }

        return $next($context);
    }

    /**
     * Aplica de forma recursiva un nodo del árbol de filtros (condiciones individuales o grupos lógicos AND/OR).
     *
     * @param Builder $query Consulta o subconsulta Eloquent activa
     * @param array<string, mixed> $node Mapa asociativo de condiciones o subgrupos
     * @param string $boolean Conector lógico actual ('and' u 'or')
     * @param ProcessorContext $context Contexto de ejecución
     * @return void
     * @throws ProcessorValidationException Si la sintaxis de un grupo lógico es incorrecta
     */
    private function applyFilterNode(Builder $query, array $node, string $boolean, ProcessorContext $context): void
    {
        foreach ($node as $key => $value) {
            // Paso 1: Ignorar valores vacíos explícitos (ej: ?filter[status]=)
            if ($value === '' || $value === null) {
                continue;
            }

            $keyStr = (string) $key;
            $lowerKey = strtolower($keyStr);

            // Paso 2: Evaluar si la clave es un grupo lógico ('and' u 'or') según SPEC-007
            if (LogicalOperator::isLogical($lowerKey)) {
                if (!is_array($value)) {
                    throw ProcessorValidationException::forField(
                        QueryParameter::FILTER . ".{$lowerKey}",
                        "El grupo lógico '{$lowerKey}' debe ser una lista de condiciones."
                    );
                }

                // El tipo de conector para los hijos es el propio operador ('and' u 'or')
                $childBoolean = $lowerKey;
                $method = $boolean === LogicalOperator::OR ? 'orWhere' : 'where';

                // Agrupar en una subconsulta anidada para respetar la precedencia de operadores booleanos en SQL
                $query->{$method}(function (Builder $subQuery) use ($value, $childBoolean, $context): void {
                    foreach ($value as $childItem) {
                        if (is_array($childItem)) {
                            $this->applyFilterNode($subQuery, $childItem, $childBoolean, $context);
                        }
                    }
                });

                continue;
            }

            // Paso 3: Aplicar como condición individual sobre columna, relación o CustomFilter
            $this->applySingleFilter($query, $keyStr, $value, $boolean, $context);
        }
    }

    /**
     * Valida la lista blanca y aplica una condición individual sobre un campo o relación.
     *
     * @param Builder $query
     * @param string $field Nombre del campo (ej: 'name', 'status', 'roles.name')
     * @param mixed $value Valor simple o mapa de operadores (ej: 'active', ['gte' => 100])
     * @param string $boolean 'and' u 'or'
     * @param ProcessorContext $context
     * @return void
     * @throws ProcessorValidationException Si el campo no está en la lista blanca
     */
    private function applySingleFilter(
        Builder $query,
        string $field,
        mixed $value,
        string $boolean,
        ProcessorContext $context
    ): void {
        // Paso 1: Verificación estricta de Lista Blanca
        if (!$context->config->isFilterAllowed($field)) {
            throw ProcessorValidationException::forField(
                QueryParameter::FILTER . ".{$field}",
                "El filtro '{$field}' no está autorizado para este recurso."
            );
        }

        // Paso 2: Si existe un CustomFilter registrado con este nombre, delegar la consulta al Closure
        $customFilter = $context->config->getCustomFilter($field);
        if ($customFilter !== null) {
            $method = $boolean === LogicalOperator::OR ? 'orWhere' : 'where';
            $query->{$method}(function (Builder $subQuery) use ($customFilter, $value): void {
                $customFilter->apply($subQuery, $value);
            });
            return;
        }

        // Paso 3: Normalizar a mapa asociativo de operadores.
        // Si el cliente envió un valor escalar (?filter[status]=active), se asume igualdad (eq) por defecto.
        $operators = is_array($value) && !array_is_list($value)
            ? $value
            : [Operator::EQUALS->value => $value];

        // Paso 4: Iterar y aplicar cada operador declarado sobre el campo
        foreach ($operators as $operator => $operand) {
            // Ignorar operandos nulos o vacíos
            if ($operand === '' || $operand === null) {
                continue;
            }

            $this->applyOperatorToField($query, $field, (string) $operator, $operand, $boolean, $context);
        }
    }

    /**
     * Enruta la aplicación de un operador hacia un Local Scope, una Relación, un campo JSON o columna directa.
     *
     * @param Builder $query
     * @param string $field
     * @param string $operator
     * @param mixed $value
     * @param string $boolean
     * @param ProcessorContext $context
     * @return void
     */
    private function applyOperatorToField(
        Builder $query,
        string $field,
        string $operator,
        mixed $value,
        string $boolean,
        ProcessorContext $context
    ): void {
        $model = $query->getModel();

        // Caso A: Verificar si corresponde a un Local Scope del Modelo Eloquent (solo si no contiene punto)
        if (!str_contains($field, '.')) {
            $scopeMethod = 'scope' . Str::studly($field);
            if (method_exists($model, $scopeMethod)) {
                $method = $boolean === LogicalOperator::OR ? 'orWhere' : 'where';
                $query->{$method}(function (Builder $subQuery) use ($field, $value): void {
                    $parsedValue = $this->parseBooleanIfApplicable($value);
                    $subQuery->{$field}($parsedValue);
                });
                return;
            }
        }

        // Caso B: Campo con notación de punto (Relación o atributo JSON)
        if (str_contains($field, '.')) {
            $segments = explode('.', $field);
            $relationName = $segments[0];
            $nestedField = implode('.', array_slice($segments, 1));

            // Comprobar si el primer segmento es un método de relación válido en el modelo
            if (method_exists($model, $relationName)) {
                $relationInstance = null;
                try {
                    $relationInstance = $model->{$relationName}();
                } catch (\Throwable) {
                    // Si falla la invocación, no es una relación
                }

                if ($relationInstance instanceof Relation) {
                    // Aplicar subconsulta con whereHas / orWhereHas para aislamiento seguro
                    $hasMethod = $boolean === LogicalOperator::OR ? 'orWhereHas' : 'whereHas';
                    $query->{$hasMethod}($relationName, function (Builder $relQuery) use ($nestedField, $operator, $value, $context): void {
                        $this->applyOperatorToField($relQuery, $nestedField, $operator, $value, LogicalOperator::AND, $context);
                    });
                    return;
                }
            }

            // Caso C: Atributo anidado en columna JSON: transformar 'extra_data.client.code' a 'extra_data->client->code'
            $jsonField = str_replace('.', '->', $field);
            $this->applySqlPredicate($query, $jsonField, $operator, $value, $boolean);
            return;
        }

        // Caso D: Columna directa de la tabla base
        $this->applySqlPredicate($query, $field, $operator, $value, $boolean);
    }

    /**
     * Aplica el predicado SQL específico sobre el Builder utilizando el catálogo tipado de operadores.
     *
     * @param Builder $query
     * @param string $column Nombre de la columna o ruta JSON
     * @param string $operator Nombre del operador textual (ej: 'eq', 'gte', 'in')
     * @param mixed $value Valor o lista de valores del filtro
     * @param string $boolean Conector lógico ('and' u 'or')
     * @return void
     * @throws ProcessorValidationException Si el operador no existe o no tiene los argumentos requeridos
     */
    private function applySqlPredicate(
        Builder $query,
        string $column,
        string $operator,
        mixed $value,
        string $boolean
    ): void {
        // Resolver el operador a una instancia tipada del Enum Operator
        $operatorEnum = Operator::tryFromString($operator);

        if ($operatorEnum === null) {
            throw ProcessorValidationException::forField(
                QueryParameter::FILTER . ".{$column}.{$operator}",
                "El operador '{$operator}' no es compatible o no está soportado."
            );
        }

        // Determinar el operador LIKE sensible o insensible según el driver de la base de datos
        $likeOp = $query->getConnection()->getDriverName() === 'pgsql' ? 'ILIKE' : 'LIKE';
        $isOr = $boolean === LogicalOperator::OR;

        match ($operatorEnum) {
            // Comparación de Igualdad
            Operator::EQUALS => $isOr
                ? $query->orWhere($column, '=', $value)
                : $query->where($column, '=', $value),

            // Comparación de Desigualdad
            Operator::NOT_EQUALS => $isOr
                ? $query->orWhere($column, '!=', $value)
                : $query->where($column, '!=', $value),

            // Mayor estricto
            Operator::GREATER_THAN => $isOr
                ? $query->orWhere($column, '>', $value)
                : $query->where($column, '>', $value),

            // Mayor o igual
            Operator::GREATER_THAN_OR_EQUAL => $isOr
                ? $query->orWhere($column, '>=', $value)
                : $query->where($column, '>=', $value),

            // Menor estricto
            Operator::LESS_THAN => $isOr
                ? $query->orWhere($column, '<', $value)
                : $query->where($column, '<', $value),

            // Menor o igual
            Operator::LESS_THAN_OR_EQUAL => $isOr
                ? $query->orWhere($column, '<=', $value)
                : $query->where($column, '<=', $value),

            // Pertenencia a conjunto (IN) con tolerancia a CSV y arrays
            Operator::IN => (function () use ($query, $column, $value, $isOr): void {
                $list = $this->normalizeList($value);
                $isOr ? $query->orWhereIn($column, $list) : $query->whereIn($column, $list);
            })(),

            // Exclusión de conjunto (NOT IN) con tolerancia a CSV y arrays
            Operator::NOT_IN => (function () use ($query, $column, $value, $isOr): void {
                $list = $this->normalizeList($value);
                $isOr ? $query->orWhereNotIn($column, $list) : $query->whereNotIn($column, $list);
            })(),

            // Rango cerrado inclusivo (BETWEEN)
            Operator::BETWEEN => (function () use ($query, $column, $value, $isOr): void {
                $range = $this->normalizeList($value);
                if (count($range) < 2) {
                    throw ProcessorValidationException::forField(
                        QueryParameter::FILTER . ".{$column}.between",
                        "El operador 'between' requiere exactamente dos valores."
                    );
                }
                $isOr
                    ? $query->orWhereBetween($column, [$range[0], $range[1]])
                    : $query->whereBetween($column, [$range[0], $range[1]]);
            })(),

            // Rango cerrado exclusivo (NOT BETWEEN)
            Operator::NOT_BETWEEN => (function () use ($query, $column, $value, $isOr): void {
                $range = $this->normalizeList($value);
                if (count($range) < 2) {
                    throw ProcessorValidationException::forField(
                        QueryParameter::FILTER . ".{$column}.not_between",
                        "El operador 'not_between' requiere dos valores."
                    );
                }
                $isOr
                    ? $query->orWhereNotBetween($column, [$range[0], $range[1]])
                    : $query->whereNotBetween($column, [$range[0], $range[1]]);
            })(),

            // Contiene texto (%valor%)
            Operator::CONTAINS => $isOr
                ? $query->orWhere($column, $likeOp, "%{$value}%")
                : $query->where($column, $likeOp, "%{$value}%"),

            // No contiene texto (NOT LIKE %valor%)
            Operator::NOT_CONTAINS => $isOr
                ? $query->orWhere($column, 'NOT ' . $likeOp, "%{$value}%")
                : $query->where($column, 'NOT ' . $likeOp, "%{$value}%"),

            // Comienza con texto (valor%)
            Operator::STARTS_WITH => $isOr
                ? $query->orWhere($column, $likeOp, "{$value}%")
                : $query->where($column, $likeOp, "{$value}%"),

            // No comienza con texto (NOT LIKE valor%)
            Operator::NOT_STARTS_WITH => $isOr
                ? $query->orWhere($column, 'NOT ' . $likeOp, "{$value}%")
                : $query->where($column, 'NOT ' . $likeOp, "{$value}%"),

            // Termina con texto (%valor)
            Operator::ENDS_WITH => $isOr
                ? $query->orWhere($column, $likeOp, "%{$value}")
                : $query->where($column, $likeOp, "%{$value}"),

            // No termina con texto (NOT LIKE %valor)
            Operator::NOT_ENDS_WITH => $isOr
                ? $query->orWhere($column, 'NOT ' . $likeOp, "%{$value}")
                : $query->where($column, 'NOT ' . $likeOp, "%{$value}"),

            // Comprobación de campo nulo (IS NULL)
            Operator::IS_NULL => $isOr
                ? $query->orWhereNull($column)
                : $query->whereNull($column),

            // Comprobación de campo no nulo (IS NOT NULL)
            Operator::IS_NOT_NULL => $isOr
                ? $query->orWhereNotNull($column)
                : $query->whereNotNull($column),

            // Comprobación de cadena vacía ('')
            Operator::IS_EMPTY => $isOr
                ? $query->orWhere($column, '=', '')
                : $query->where($column, '=', ''),

            // Comprobación de cadena no vacía (!= '')
            Operator::IS_NOT_EMPTY => $isOr
                ? $query->orWhere($column, '!=', '')
                : $query->where($column, '!=', ''),

            // Coincidencia de fecha exacta (whereDate)
            Operator::DATE_EQUALS => $isOr
                ? $query->orWhereDate($column, '=', $value)
                : $query->whereDate($column, '=', $value),

            // Rango de fechas inclusivo
            Operator::DATE_BETWEEN => (function () use ($query, $column, $value, $isOr): void {
                $dates = $this->normalizeList($value);
                if (count($dates) < 2) {
                    throw ProcessorValidationException::forField(
                        QueryParameter::FILTER . ".{$column}.date_between",
                        "El operador 'date_between' requiere dos fechas."
                    );
                }
                $query->where(function (Builder $q) use ($column, $dates, $isOr): void {
                    $isOr
                        ? $q->orWhereDate($column, '>=', $dates[0])->whereDate($column, '<=', $dates[1])
                        : $q->whereDate($column, '>=', $dates[0])->whereDate($column, '<=', $dates[1]);
                });
            })(),

            // Coincidencia de año (whereYear)
            Operator::YEAR => $isOr
                ? $query->orWhereYear($column, '=', $value)
                : $query->whereYear($column, '=', $value),
        };
    }

    /**
     * Normaliza una entrada de lista: si es un string separado por comas ('a,b'), lo transforma en array ['a', 'b'].
     *
     * @param mixed $value Valor enviado por el cliente
     * @return array<int, mixed> Lista indexada y limpia de elementos
     */
    private function normalizeList(mixed $value): array
    {
        // Si ya es un array nativo, reindexar los valores
        if (is_array($value)) {
            return array_values($value);
        }

        // Si es una cadena delimitada por comas, dividir y limpiar espacios en blanco
        if (is_string($value)) {
            return array_map('trim', explode(',', $value));
        }

        return [(string) $value];
    }

    /**
     * Convierte cadenas con literales booleanos ('true', 'false', '1', '0') a tipos booleanos nativos de PHP.
     *
     * @param mixed $value
     * @return mixed El booleano evaluado o el valor original si no corresponde
     */
    private function parseBooleanIfApplicable(mixed $value): mixed
    {
        if (is_string($value)) {
            $filtered = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($filtered !== null) {
                return $filtered;
            }
        }

        return $value;
    }
}
