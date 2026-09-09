<?php

declare(strict_types=1);

namespace Warrior\RestProcessor\Context;

use Warrior\RestProcessor\Constants\QueryParameter;
use Warrior\RestProcessor\Enums\SortDirection;
use Warrior\RestProcessor\Filters\CustomFilter;

/**
 * Encapsula la configuración, opciones operativas y listas blancas autorizadas para un endpoint.
 *
 * Contiene los metadatos necesarios para que los Pipes del Pipeline procesen la consulta
 * de forma segura, garantizando que solo los campos y relaciones explícitamente permitidos
 * puedan ser alterados por los parámetros de consulta HTTP enviados por el cliente.
 */
final class ProcessorConfig
{
    /**
     * @param array<int|string, string|CustomFilter> $allowedFilters Filtros autorizados (columnas, comodines, CustomFilter)
     * @param array<string, mixed> $defaultFilters Filtros por defecto a nivel HTTP si el cliente no envía valor
     * @param array<int, string> $allowedIncludes Relaciones autorizadas para Eager Loading (with)
     * @param array<int, string> $allowedCounts Relaciones autorizadas para conteos agregados (withCount)
     * @param array<int, string> $allowedSearch Columnas de texto habilitadas para búsqueda global concurrente
     * @param array<int, string> $allowedSorts Columnas de la tabla base autorizadas para ordenar
     * @param string $defaultSortColumn Columna de ordenamiento por defecto cuando se omite ?sort
     * @param string $defaultSortDirection Dirección por defecto ('asc' o 'desc')
     * @param bool $allowAll Si la recuperación total sin paginar (?all=true) está habilitada en el endpoint
     * @param int $maxAllLimit Límite de seguridad anti-OOM para la recuperación total
     * @param int $defaultPageSize Tamaño de página por defecto en paginación estándar
     * @param int $maxPageSize Tamaño máximo permitido por página (?per_page)
     * @param array<string, string> $parameterNames Mapeo de nombres de query string (QueryParameter::defaults())
     */
    public function __construct(
        public array $allowedFilters = [],
        public array $defaultFilters = [],
        public array $allowedIncludes = [],
        public array $allowedCounts = [],
        public array $allowedSearch = [],
        public array $allowedSorts = [],
        public string $defaultSortColumn = 'id',
        public string $defaultSortDirection = SortDirection::DESC->value,
        public bool $allowAll = false,
        public int $maxAllLimit = 5000,
        public int $defaultPageSize = 15,
        public int $maxPageSize = 100,
        public array $parameterNames = [],
    ) {
        // Si no se proporcionó un mapa de nombres de parámetros personalizado, inicializar con los defaults estándar
        if (empty($this->parameterNames)) {
            $this->parameterNames = QueryParameter::defaults();
        }
    }

    /**
     * Obtiene el nombre real del parámetro HTTP en la URL configurado para una clave interna.
     *
     * Permite desacoplar los nombres en el código interno (ej: QueryParameter::FILTER)
     * de las claves públicas configuradas en el archivo de configuración o a nivel de API.
     *
     * @param string $key Clave interna (ej: 'filter', 'sort', 'all')
     * @return string Nombre real esperado en la URL
     */
    public function param(string $key): string
    {
        return $this->parameterNames[$key] ?? $key;
    }

    /**
     * Determina si un nombre de filtro solicitado por el cliente está autorizado según las reglas y comodines.
     *
     * Reglas de evaluación en orden:
     * 1. Comodín global '*': Autoriza cualquier columna directa de la tabla base (sin punto).
     * 2. Coincidencia exacta: El nombre coincide literalmente con una entrada autorizada o con el alias de un CustomFilter.
     * 3. Comodín de prefijo ('prefijo.*'): Autoriza cualquier subcampo que comience con 'prefijo.',
     *    muy útil para relaciones ('roles.*') o campos JSON ('extra_data.*').
     *
     * @param string $filterName Nombre del filtro solicitado (ej: 'status', 'roles.name', 'extra_data.client.code')
     * @return bool True si el filtro está explícitamente en la lista blanca
     */
    public function isFilterAllowed(string $filterName): bool
    {
        // Iterar sobre cada regla definida en la lista blanca de filtros autorizados
        foreach ($this->allowedFilters as $allowed) {
            // Resolver el identificador textual si es una regla string o una instancia de CustomFilter
            $allowedName = $allowed instanceof CustomFilter ? $allowed->name : (string) $allowed;

            // Paso 1: Si hay un comodín global '*' y el filtro no contiene punto (columna directa), autorizar
            if ($allowedName === '*' && !str_contains($filterName, '.')) {
                return true;
            }

            // Paso 2: Coincidencia textual exacta entre la regla permitida y el filtro solicitado
            if ($allowedName === $filterName) {
                return true;
            }

            // Paso 3: Soporte para comodines jerárquicos con notación de punto (ej: 'roles.*' o 'metadata.*')
            if (str_ends_with($allowedName, '.*')) {
                $prefix = substr($allowedName, 0, -2);
                if (str_starts_with($filterName, $prefix . '.')) {
                    return true;
                }
            }
        }

        // Si ninguna regla autorizó el campo, se rechaza para prevenir filtrado no controlado o inyecciones
        return false;
    }

    /**
     * Busca y retorna una instancia de CustomFilter registrada para el nombre solicitado.
     *
     * Permite que el FilterPipe delegue la construcción de la subconsulta al callback
     * personalizado registrado por el desarrollador en el controlador.
     *
     * @param string $name Nombre del filtro personalizado
     * @return CustomFilter|null La instancia encontrada o null si es un filtro de columna normal
     */
    public function getCustomFilter(string $name): ?CustomFilter
    {
        // Buscar entre las reglas autorizadas aquellas que sean instancias de CustomFilter
        foreach ($this->allowedFilters as $filter) {
            if ($filter instanceof CustomFilter && $filter->name === $name) {
                return $filter;
            }
        }

        return null;
    }
}
