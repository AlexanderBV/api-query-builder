<?php

declare(strict_types=1);

namespace Warrior\RestProcessor\Pipes;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Warrior\RestProcessor\Constants\QueryParameter;
use Warrior\RestProcessor\Context\ProcessorContext;
use Warrior\RestProcessor\Exceptions\ProcessorValidationException;

/**
 * Pipe responsable de la búsqueda global concurrente multi-columna (?search=termino).
 *
 * Aplica una cláusula WHERE agrupada con condiciones OR internas para garantizar
 * que la búsqueda respete estrictamente los filtros previos y scopes de seguridad (como tenencia multi-tenant).
 * Detecta automáticamente el dialecto del motor de base de datos (ILIKE en PostgreSQL, LIKE en MySQL/SQLite)
 * y soporta búsqueda a través de relaciones autorizadas (ej: 'roles.name' mediante orWhereHas).
 */
final class SearchPipe
{
    /**
     * Longitud máxima permitida para el término de búsqueda con el fin de mitigar ataques de denegación de servicio (DoS).
     */
    public const MAX_TERM_LENGTH = 200;

    /**
     * Procesa el término de búsqueda global en el Pipeline.
     *
     * @param ProcessorContext $context Contexto de ejecución activo
     * @param Closure(ProcessorContext): ProcessorContext $next Siguiente Pipe en la cadena
     * @return ProcessorContext
     * @throws ProcessorValidationException Si la búsqueda no está configurada o excede los límites
     */
    public function handle(ProcessorContext $context, Closure $next): ProcessorContext
    {
        // Paso 1: Obtener el nombre del parámetro HTTP configurado (por defecto 'search')
        $searchParam = $context->config->param(QueryParameter::SEARCH);
        $searchTerm = $context->request->query($searchParam);

        // Paso 2: Cláusula de guarda: si no se envió el parámetro o es nulo/vacío, omitir esta etapa
        if ($searchTerm === null || $searchTerm === '') {
            return $next($context);
        }

        $term = trim((string) $searchTerm);
        if ($term === '') {
            return $next($context);
        }

        // Paso 3: Validación de seguridad: si el cliente solicita búsqueda pero el endpoint no autorizó
        // ninguna columna en allowedSearch(), se rechaza con error 422 para evitar escaneos no previstos
        if (empty($context->config->allowedSearch)) {
            throw ProcessorValidationException::forField(
                QueryParameter::SEARCH,
                'La búsqueda global no está configurada ni permitida para este recurso.'
            );
        }

        // Paso 4: Salvaguarda de rendimiento: validar la longitud máxima del término
        if (mb_strlen($term) > self::MAX_TERM_LENGTH) {
            throw ProcessorValidationException::forField(
                QueryParameter::SEARCH,
                sprintf('El término de búsqueda no puede exceder los %d caracteres.', self::MAX_TERM_LENGTH)
            );
        }

        // Paso 5: Determinar el operador de coincidencia insensible a mayúsculas según el driver de la BD
        $driver = $context->builder->getConnection()->getDriverName();
        $likeOp = $driver === 'pgsql' ? 'ILIKE' : 'LIKE';
        $columns = $context->config->allowedSearch;

        // Paso 6: Construir la cláusula WHERE agrupada.
        // Es crítico que todas las alternativas OR residan dentro de un único grupo paréntesis WHERE:
        // WHERE (col1 LIKE '%term%' OR col2 LIKE '%term%' OR EXISTS(...))
        // para no romper la precedencia lógica con otros filtros o scopes del modelo
        $context->builder->where(function (Builder $query) use ($columns, $term, $likeOp): void {
            foreach ($columns as $column) {
                if (str_contains($column, '.')) {
                    // Búsqueda en relación: 'roles.name' -> orWhereHas('roles', fn($q) => $q->where('name', ...))
                    $segments = explode('.', $column);
                    $relation = $segments[0];
                    $relCol = implode('.', array_slice($segments, 1));

                    $query->orWhereHas($relation, function (Builder $relQuery) use ($relCol, $likeOp, $term): void {
                        $relQuery->where($relCol, $likeOp, "%{$term}%");
                    });
                } else {
                    // Búsqueda sobre columna directa de la tabla base
                    $query->orWhere($column, $likeOp, "%{$term}%");
                }
            }
        });

        return $next($context);
    }
}
