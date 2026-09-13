<?php

declare(strict_types=1);

namespace Warrior\ApiQueryBuilder\Enums;

/**
 * Catálogo completo de operadores autorizados soportados por el procesador (SPEC-003).
 *
 * Utilizar un Backed Enum tipado garantiza que cualquier operador no reconocido
 * sea detectado de inmediato sin dispersar cadenas de texto mágicas.
 */
enum Operator: string
{
    // Comparación básica
    case EQUALS = 'eq';
    case NOT_EQUALS = 'neq';
    case GREATER_THAN = 'gt';
    case GREATER_THAN_OR_EQUAL = 'gte';
    case LESS_THAN = 'lt';
    case LESS_THAN_OR_EQUAL = 'lte';

    // Listas y conjuntos
    case IN = 'in';
    case NOT_IN = 'not_in';

    // Rangos numéricos y temporales
    case BETWEEN = 'between';
    case NOT_BETWEEN = 'not_between';

    // Operadores textuales
    case CONTAINS = 'contains';
    case NOT_CONTAINS = 'not_contains';
    case STARTS_WITH = 'starts_with';
    case NOT_STARTS_WITH = 'not_starts_with';
    case ENDS_WITH = 'ends_with';
    case NOT_ENDS_WITH = 'not_ends_with';

    // Nulos y cadenas vacías
    case IS_NULL = 'is_null';
    case IS_NOT_NULL = 'is_not_null';
    case IS_EMPTY = 'is_empty';
    case IS_NOT_EMPTY = 'is_not_empty';

    // Fechas y calendario
    case DATE_EQUALS = 'date_eq';
    case DATE_BETWEEN = 'date_between';
    case YEAR = 'year';

    /**
     * Intenta resolver una cadena a un operador de forma case-insensitive.
     */
    public static function tryFromString(string $operator): ?self
    {
        return self::tryFrom(strtolower(trim($operator)));
    }
}
