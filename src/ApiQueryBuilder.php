<?php

declare(strict_types=1);

namespace Warrior\ApiQueryBuilder;

use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pipeline\Pipeline;
use InvalidArgumentException;
use Warrior\ApiQueryBuilder\Constants\QueryParameter;
use Warrior\ApiQueryBuilder\Context\ProcessorConfig;
use Warrior\ApiQueryBuilder\Context\ProcessorContext;
use Warrior\ApiQueryBuilder\Enums\SortDirection;
use Warrior\ApiQueryBuilder\Filters\CustomFilter;
use Warrior\ApiQueryBuilder\Pipes\FilterPipe;
use Warrior\ApiQueryBuilder\Pipes\IncludePipe;
use Warrior\ApiQueryBuilder\Pipes\PaginationPipe;
use Warrior\ApiQueryBuilder\Pipes\SearchPipe;
use Warrior\ApiQueryBuilder\Pipes\SortPipe;

/**
 * Fluent Builder principal para el procesamiento declarativo y seguro de consultas REST en Laravel.
 *
 * Facilita la creación de APIs y endpoints CRUD conectables a interfaces frontend modernas,
 * resolviendo de manera centralizada y segura:
 * - Filtrado multidimensional (columnas, scopes locales, relaciones y campos JSON).
 * - Búsqueda global concurrente multi-columna.
 * - Ordenamiento determinista con clave primaria de desempate.
 * - Carga anticipada de relaciones (Eager Loading con with()) sin problemas de N+1.
 * - Conteos agregados relacionales (withCount()).
 * - Paginación estándar, rápida (simple), por cursor o volcado total (?all=true) con protección anti-OOM.
 */
final class ApiQueryBuilder
{
    /** Instancia activa del Query Builder de Eloquent */
    private Builder $builder;

    /** Petición HTTP actual que contiene los parámetros de consulta (query string) */
    private Request $request;

    /** Objeto de configuración y reglas de listas blancas autorizadas */
    private ProcessorConfig $config;

    /**
     * Inicializa una nueva instancia del procesador con la consulta y petición dadas.
     *
     * @param  Builder  $builder  Consulta Eloquent base (con restricciones de seguridad/tenencia preaplicadas)
     * @param  Request|null  $request  Petición HTTP actual (si se omite, se resuelve mediante helper request())
     */
    public function __construct(Builder $builder, ?Request $request = null)
    {
        $this->builder = $builder;
        $this->request = $request ?? request();

        // Paso 1: Cargar la configuración global de la aplicación o valores por defecto
        /** @var array<string, mixed> $appConfig */
        $appConfig = config('api-query-builder', []);

        // Paso 2: Instanciar ProcessorConfig consolidando parámetros, límites y direcciones
        $this->config = new ProcessorConfig(
            defaultSortColumn: $appConfig['sort']['default_column'] ?? 'id',
            defaultSortDirection: $appConfig['sort']['default_direction'] ?? SortDirection::DESC->value,
            allowAll: $appConfig['all']['enabled_by_default'] ?? false,
            maxAllLimit: $appConfig['all']['max_limit'] ?? 5000,
            defaultPageSize: $appConfig['pagination']['default_size'] ?? 15,
            maxPageSize: $appConfig['pagination']['max_size'] ?? 100,
            parameterNames: $appConfig['parameters'] ?? QueryParameter::defaults(),
        );
    }

    /**
     * Punto de entrada principal y ergonómico para crear una nueva instancia del procesador.
     *
     * Acepta:
     * - El class-string de un Modelo Eloquent (ej: User::class)
     * - Una relación activa de Eloquent (ej: $user->posts())
     * - Un Builder de Eloquent existente (ej: User::where('company_id', $tenantId))
     *
     * @param  class-string<Model>|Builder|Relation  $subject  Origen de datos para la consulta
     * @param  Request|null  $request  Petición HTTP opcional
     *
     * @throws InvalidArgumentException Si el argumento proporcionado no es compatible
     */
    public static function for(mixed $subject, ?Request $request = null): self
    {
        // Caso 1: Class-string de un Modelo Eloquent
        if (is_string($subject) && is_subclass_of($subject, Model::class)) {
            return new self($subject::query(), $request);
        }

        // Caso 2: Instancia de una Relación de Eloquent
        if ($subject instanceof Relation) {
            return new self($subject->getQuery(), $request);
        }

        // Caso 3: Instancia ya configurada de un Query Builder de Eloquent
        if ($subject instanceof Builder) {
            return new self($subject, $request);
        }

        throw new InvalidArgumentException(
            'ApiQueryBuilder::for() solo acepta un class-string de Eloquent Model, un Builder o una Relation.'
        );
    }

    /**
     * Define la lista blanca de filtros autorizados para este recurso.
     *
     * Soporta:
     * - Columnas directas de la tabla (ej: 'name', 'status')
     * - Comodín global ('*') para todas las columnas de la tabla base
     * - Comodines de relación ('roles.*') o campos JSON ('extra_data.*')
     * - Filtros personalizados creados mediante Filter::custom('alias', fn($q, $v) => ...)
     *
     * @param  array<int|string, string|CustomFilter>  $filters
     */
    public function allowedFilters(array $filters): self
    {
        $this->config->allowedFilters = $filters;

        return $this;
    }

    /**
     * Define filtros de respaldo por defecto a nivel de petición HTTP.
     *
     * Estos valores se aplican únicamente si el cliente omite el parámetro correspondiente en la URL.
     * A diferencia de modificar el builder base directamente, los defaultFilters permiten que el frontend
     * sobrescriba o relaje el filtro explícitamente cuando sea necesario.
     *
     * @param  array<string, mixed>  $defaults  Mapa de campo => valor por defecto
     */
    public function defaultFilters(array $defaults): self
    {
        $this->config->defaultFilters = $defaults;

        return $this;
    }

    /**
     * Define la lista blanca de relaciones autorizadas para carga anticipada (Eager Loading con with()).
     *
     * Previene estrictamente el problema de N+1 consultas en la base de datos y garantiza que solo
     * se puedan hidratar relaciones expresamente autorizadas por el desarrollador.
     *
     * @param  array<int, string>  $includes  Nombres de las relaciones autorizadas (ej: ['roles', 'profile'])
     */
    public function allowedIncludes(array $includes): self
    {
        $this->config->allowedIncludes = $includes;

        return $this;
    }

    /**
     * Define la lista blanca de relaciones autorizadas para conteos agregados (withCount()).
     *
     * Genera columnas virtuales (ej: comments_count) optimizadas en la misma consulta SQL.
     *
     * @param  array<int, string>  $counts  Nombres de las relaciones (ej: ['comments', 'orders'])
     */
    public function allowedCounts(array $counts): self
    {
        $this->config->allowedCounts = $counts;

        return $this;
    }

    /**
     * Define las columnas sobre las cuales se ejecutará la búsqueda global concurrente (?search=termino).
     *
     * Soporta columnas directas de la tabla y campos relacionales autorizados (ej: 'roles.name').
     *
     * @param  array<int, string>  $columns
     */
    public function allowedSearch(array $columns): self
    {
        $this->config->allowedSearch = $columns;

        return $this;
    }

    /**
     * Define las columnas de la tabla base autorizadas para ordenamiento (?sort=columna).
     *
     * @param  array<int, string>  $sorts
     */
    public function allowedSorts(array $sorts): self
    {
        $this->config->allowedSorts = $sorts;

        return $this;
    }

    /**
     * Sobrescribe la columna y dirección de ordenamiento por defecto para este endpoint específico.
     *
     * @param  string  $column  Columna base o columna con prefijo '-' para descendente (ej: '-created_at')
     * @param  string  $direction  Dirección opcional ('asc' o 'desc', ignorada si la columna tiene prefijo '-')
     */
    public function defaultSort(string $column, string $direction = 'asc'): self
    {
        // Paso 1: Si la columna comienza con '-', interpretamos orden descendente implícito
        if (str_starts_with($column, '-')) {
            $this->config->defaultSortColumn = ltrim($column, '-');
            $this->config->defaultSortDirection = SortDirection::DESC->value;
        } else {
            $this->config->defaultSortColumn = $column;
            $this->config->defaultSortDirection = strtolower($direction) === SortDirection::DESC->value
                ? SortDirection::DESC->value
                : SortDirection::ASC->value;
        }

        return $this;
    }

    /**
     * Habilita explícitamente la recuperación total sin paginar (?all=true) para este endpoint.
     *
     * Por motivos de seguridad y estabilidad de la memoria (prevención de Out Of Memory / DoS),
     * este flag está desactivado por defecto en todos los endpoints.
     *
     * @param  int|bool  $maxLimitOrUnlimited  Límite máximo de seguridad o true para ilimitado
     */
    public function allowAll(int|bool $maxLimitOrUnlimited = 5000): self
    {
        $this->config->allowAll = true;

        if (is_int($maxLimitOrUnlimited)) {
            $this->config->maxAllLimit = $maxLimitOrUnlimited;
        } elseif ($maxLimitOrUnlimited === true) {
            $this->config->maxAllLimit = PHP_INT_MAX;
        }

        return $this;
    }

    /**
     * Ejecuta el pipeline completo y retorna la respuesta HTTP formateada en JSON.
     *
     * Casos de retorno según SPEC-002:
     * - Si se solicitó ?all=true y se pasó un Resource: JsonResponse con array envuelto en el Resource.
     * - Si se solicitó ?all=true sin Resource: JsonResponse con Array Plano directo [ {...}, {...} ].
     * - Si es paginación con Resource: JsonResource::collection($paginator)->response().
     * - Si es paginación sin Resource: JsonResponse con el objeto paginador estándar de Laravel.
     *
     * @param  string|null  $resourceClass  Clase JsonResource opcional (ej: UserResource::class)
     * @return JsonResponse Respuesta JSON lista para retornar en el controlador
     */
    public function response(?string $resourceClass = null): JsonResponse
    {
        // Paso 1: Resolver la consulta a través de la cadena de Pipes
        $result = $this->get();

        // Paso 2: Caso A - Recuperación total sin paginar (?all=true retornó Collection)
        if ($result instanceof Collection) {
            if ($resourceClass !== null) {
                return response()->json($resourceClass::collection($result));
            }

            // Retorno directo en Array Plano según decisión de arquitectura en SPEC-002
            return response()->json($result->values());
        }

        // Paso 3: Caso B - Paginador nativo de Laravel (LengthAwarePaginator, SimplePaginator, CursorPaginator)
        if ($resourceClass !== null) {
            return $resourceClass::collection($result)->response();
        }

        return response()->json($result);
    }

    /**
     * Ejecuta el pipeline completo y devuelve el resultado nativo (Paginator o Collection) para manipularlo en PHP.
     *
     * Útil cuando se requiere lógica adicional posterior a la consulta antes de construir la respuesta.
     */
    public function get(): LengthAwarePaginator|Paginator|CursorPaginator|Collection
    {
        // Paso 1: Empaquetar el estado inicial en un ProcessorContext
        $context = new ProcessorContext(
            builder: $this->builder,
            request: $this->request,
            config: $this->config,
        );

        // Paso 2: Ejecutar el flujo secuencial de Pipes mediante el Pipeline de Laravel
        $resolvedContext = (new Pipeline(app()))
            ->send($context)
            ->through([
                FilterPipe::class,
                SearchPipe::class,
                SortPipe::class,
                IncludePipe::class,
                PaginationPipe::class,
            ])
            ->then(static fn (ProcessorContext $ctx): ProcessorContext => $ctx);

        /** @var LengthAwarePaginator|Paginator|CursorPaginator|Collection $finalResult */
        $finalResult = $resolvedContext->result;

        return $finalResult;
    }

    /**
     * Alias semántico de get() enfocado en paginación estándar, permitiendo sobrescribir perPage inline.
     *
     * @param  int|null  $perPage  Cantidad de registros por página por defecto
     */
    public function paginate(?int $perPage = null): LengthAwarePaginator|Paginator|CursorPaginator|Collection
    {
        if ($perPage !== null) {
            $this->config->defaultPageSize = $perPage;
        }

        return $this->get();
    }
}
