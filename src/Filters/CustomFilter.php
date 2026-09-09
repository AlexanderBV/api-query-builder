<?php

declare(strict_types=1);

namespace Warrior\RestProcessor\Filters;

use Closure;
use Illuminate\Database\Eloquent\Builder;

/**
 * Representa un filtro personalizado ad-hoc registrado mediante un Closure.
 *
 * Permite implementar reglas de negocio o consultas especializadas en el controlador
 * sin necesidad de contaminar el Modelo Eloquent con Local Scopes efímeros de UI.
 */
final class CustomFilter
{
    /**
     * @param string $name Nombre público del filtro (ej: 'has_active_subscription')
     * @param Closure $callback Función que recibe ($query, $value, $name)
     */
    public function __construct(
        public readonly string $name,
        public readonly Closure $callback,
    ) {}

    /**
     * Aplica la lógica del filtro personalizado sobre el query builder.
     *
     * @param Builder $query
     * @param mixed $value
     * @return void
     */
    public function apply(Builder $query, mixed $value): void
    {
        ($this->callback)($query, $value, $this->name);
    }
}
