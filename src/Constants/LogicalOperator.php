<?php

declare(strict_types=1);

namespace Warrior\ApiQueryBuilder\Constants;

/**
 * Define los operadores lógicos para la agrupación jerárquica de condiciones.
 *
 * Utilizados en la construcción de árboles booleanos complejos (SPEC-007).
 */
final class LogicalOperator
{
    /** Conjunción lógica copulativa: exige que todos los hijos cumplan (AND) */
    public const AND = 'and';

    /** Conjunción lógica disyuntiva: basta que al menos uno de los hijos cumpla (OR) */
    public const OR = 'or';

    /**
     * Determina si una clave corresponde a un operador lógico de grupo.
     */
    public static function isLogical(string $key): bool
    {
        $lower = strtolower($key);

        return $lower === self::AND || $lower === self::OR;
    }
}
