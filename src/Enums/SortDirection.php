<?php

declare(strict_types=1);

namespace Warrior\RestProcessor\Enums;

/**
 * Representa las direcciones válidas de ordenamiento en consultas SQL.
 */
enum SortDirection: string
{
    /** Orden ascendente (A-Z, 0-9, más antiguo primero) */
    case ASC = 'asc';

    /** Orden descendente (Z-A, 9-0, más reciente primero) */
    case DESC = 'desc';

    /**
     * Parsea un valor de cadena a una instancia de SortDirection válida con fallback.
     *
     * @param string|null $value
     * @param SortDirection $default
     * @return self
     */
    public static function fromString(?string $value, self $default = self::ASC): self
    {
        if ($value === null) {
            return $default;
        }

        return self::tryFrom(strtolower($value)) ?? $default;
    }
}
