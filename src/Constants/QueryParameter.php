<?php

declare(strict_types=1);

namespace Warrior\ApiQueryBuilder\Constants;

/**
 * Define las claves estándar para los parámetros de consulta (query string) HTTP.
 *
 * Centralizar estos nombres evita cadenas mágicas (magic strings) duplicadas en el código
 * y facilita su personalización mediante el archivo de configuración.
 */
final class QueryParameter
{
    /** Parámetro para el árbol o mapa de filtros (ej: ?filter[status]=active) */
    public const FILTER = 'filter';

    /** Parámetro para el término de búsqueda global multi-columna (ej: ?search=john) */
    public const SEARCH = 'search';

    /** Parámetro para el ordenamiento de registros (ej: ?sort=-created_at,name) */
    public const SORT = 'sort';

    /** Parámetro para la carga anticipada de relaciones sin N+1 (ej: ?include=roles,department) */
    public const INCLUDE = 'include';

    /** Parámetro para conteos relacionales con withCount (ej: ?count=comments,orders) */
    public const COUNT = 'count';

    /** Flag booleano para solicitar todos los registros sin paginar (ej: ?all=true) */
    public const ALL = 'all';

    /** Número de página a recuperar en paginación estándar (ej: ?page=2) */
    public const PAGE = 'page';

    /** Cantidad de registros por página solicitados por el cliente (ej: ?per_page=25) */
    public const PER_PAGE = 'per_page';

    /** Token opaco para navegación en modo cursor (ej: ?cursor=eyJpZCI6MTB9) */
    public const CURSOR = 'cursor';

    /** Modo de paginación activo: 'page', 'simple' o 'cursor' (ej: ?pagination=simple) */
    public const PAGINATION = 'pagination';

    /**
     * Retorna el mapa completo de parámetros predeterminados.
     *
     * @return array<string, string>
     */
    public static function defaults(): array
    {
        return [
            self::FILTER => self::FILTER,
            self::SEARCH => self::SEARCH,
            self::SORT => self::SORT,
            self::INCLUDE => self::INCLUDE,
            self::COUNT => self::COUNT,
            self::ALL => self::ALL,
            self::PAGE => self::PAGE,
            self::PER_PAGE => self::PER_PAGE,
            self::CURSOR => self::CURSOR,
            self::PAGINATION => self::PAGINATION,
        ];
    }
}
