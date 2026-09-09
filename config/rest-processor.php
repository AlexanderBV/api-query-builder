<?php

declare(strict_types=1);

use Warrior\RestProcessor\Constants\QueryParameter;
use Warrior\RestProcessor\Enums\SortDirection;

return [

    /*
    |--------------------------------------------------------------------------
    | Nombres de Parámetros HTTP
    |--------------------------------------------------------------------------
    |
    | Define las claves de query string que el procesador escucha en las
    | peticiones entrantes. Si tu API utiliza nombres personalizados,
    | puedes modificarlos aquí globalmente sin alterar tu código.
    |
    */
    'parameters' => QueryParameter::defaults(),

    /*
    |--------------------------------------------------------------------------
    | Ordenamiento por Defecto y Desempate
    |--------------------------------------------------------------------------
    |
    | Cuando el cliente no envía el parámetro 'sort', el procesador aplicará
    | este orden base. Además, para garantizar páginas deterministas, la clave
    | primaria del modelo siempre se agrega como criterio final de desempate.
    |
    */
    'sort' => [
        'default_column'    => 'id',
        'default_direction' => SortDirection::DESC->value,
    ],

    /*
    |--------------------------------------------------------------------------
    | Paginación
    |--------------------------------------------------------------------------
    |
    | Límites aplicables a la paginación estándar de Laravel. El cliente puede
    | solicitar hasta 'max_size' elementos por página mediante 'per_page'.
    |
    */
    'pagination' => [
        'default_size' => 15,
        'max_size'     => 100,
    ],

    /*
    |--------------------------------------------------------------------------
    | Recuperación Total (all=true) y Salvaguarda de Memoria
    |--------------------------------------------------------------------------
    |
    | Para evitar desbordamientos de memoria (Out Of Memory / DoS), la
    | recuperación completa sin paginar requiere autorización explícita
    | en el endpoint (->allowAll()) y está acotada por 'max_limit'.
    |
    */
    'all' => [
        'enabled_by_default' => false,
        'max_limit'          => 5000,
    ],

];
