<?php

declare(strict_types=1);

namespace Warrior\RestProcessor\Enums;

/**
 * Representa los modos de paginación disponibles en Laravel Eloquent.
 */
enum PaginationMode: string
{
    /** Paginación completa con LengthAwarePaginator (ejecuta COUNT para calcular páginas totales) */
    case PAGE = 'page';

    /** Paginación rápida con SimplePaginator (no ejecuta COUNT, óptimo para grandes volúmenes) */
    case SIMPLE = 'simple';

    /** Paginación basada en cursor para feeds continuos o APIs de alto rendimiento */
    case CURSOR = 'cursor';

    /**
     * Parsea un string al modo correspondiente, retornando PAGE por defecto.
     *
     * @param string|null $mode
     * @return self
     */
    public static function fromString(?string $mode): self
    {
        if ($mode === null) {
            return self::PAGE;
        }

        return self::tryFrom(strtolower($mode)) ?? self::PAGE;
    }
}
