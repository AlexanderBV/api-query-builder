<?php

declare(strict_types=1);

namespace Warrior\RestProcessor\Filters;

use Closure;

/**
 * Fábrica estática para la creación ergonómica de filtros especializados.
 */
final class Filter
{
    /**
     * Define un filtro personalizado ad-hoc respaldado por una función Closure.
     *
     * Ejemplo de uso en el controlador:
     * ```php
     * Filter::custom('has_active_subscription', function ($query, $value) {
     *     $query->whereHas('subscriptions', fn($q) => $q->where('ends_at', '>', now()));
     * })
     * ```
     *
     * @param string $name Nombre del filtro público en la URL (ej: 'has_active_subscription')
     * @param Closure $callback Función que recibe ($query, $value, $name)
     * @return CustomFilter
     */
    public static function custom(string $name, Closure $callback): CustomFilter
    {
        return new CustomFilter($name, $callback);
    }
}
