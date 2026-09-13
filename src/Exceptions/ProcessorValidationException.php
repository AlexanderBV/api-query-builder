<?php

declare(strict_types=1);

namespace Warrior\ApiQueryBuilder\Exceptions;

use Illuminate\Validation\ValidationException;

/**
 * Excepción estándar para errores de validación en parámetros de consulta REST.
 *
 * Dispara una respuesta HTTP 422 Unprocessable Entity estructurada con el formato
 * universal de errores de Laravel ('errors' => [campo => [mensajes]]), consumible
 * de forma directa y transparente por componentes frontend (Vue, React, Axios, Inertia).
 */
final class ProcessorValidationException extends ValidationException
{
    /**
     * Crea una instancia de excepción a partir de un mensaje específico para un campo.
     *
     * @param  string  $field  Clave o parámetro infractor (ej: 'filter.status', 'sort', 'all')
     * @param  string  $message  Descripción amigable del error
     */
    public static function forField(string $field, string $message): self
    {
        return self::withMessages([
            $field => [$message],
        ]);
    }
}
