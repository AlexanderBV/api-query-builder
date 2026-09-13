<?php

declare(strict_types=1);

namespace Warrior\ApiQueryBuilder;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;

/**
 * ServiceProvider del paquete ApiQueryBuilder para Laravel.
 *
 * Registra la configuración predeterminada y las Macros directas sobre
 * Eloquent Builder y Relation para ofrecer sintaxis ultraligera (Model::apiQuery()).
 */
final class ApiQueryBuilderServiceProvider extends ServiceProvider
{
    /**
     * Registra los servicios y fusiones de configuración del paquete.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/api-query-builder.php',
            'api-query-builder'
        );
    }

    /**
     * Ejecuta el arranque de servicios y el registro de Macros de Eloquent.
     */
    public function boot(): void
    {
        // Publicar archivo de configuración
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/api-query-builder.php' => config_path('api-query-builder.php'),
            ], 'api-query-builder-config');
        }

        // Registrar Macro sobre Eloquent Builder: Model::apiQuery() o Model::where(...)->apiQuery()
        Builder::macro('apiQuery', function (?Request $request = null): ApiQueryBuilder {
            /** @var Builder $this */
            return ApiQueryBuilder::for($this, $request);
        });

        Builder::macro('apiQueryBuilder', function (?Request $request = null): ApiQueryBuilder {
            /** @var Builder $this */
            return ApiQueryBuilder::for($this, $request);
        });

        // Registrar Macro sobre Eloquent Relation: $user->posts()->apiQuery()
        Relation::macro('apiQuery', function (?Request $request = null): ApiQueryBuilder {
            /** @var Relation $this */
            return ApiQueryBuilder::for($this, $request);
        });

        Relation::macro('apiQueryBuilder', function (?Request $request = null): ApiQueryBuilder {
            /** @var Relation $this */
            return ApiQueryBuilder::for($this, $request);
        });
    }
}
