<?php

declare(strict_types=1);

namespace Warrior\RestProcessor;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;

/**
 * ServiceProvider del paquete RestProcessor para Laravel.
 *
 * Registra la configuración predeterminada y las Macros directas sobre
 * Eloquent Builder y Relation para ofrecer sintaxis ultraligera (Model::processRest()).
 */
final class RestProcessorServiceProvider extends ServiceProvider
{
    /**
     * Registra los servicios y fusiones de configuración del paquete.
     *
     * @return void
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/rest-processor.php',
            'rest-processor'
        );
    }

    /**
     * Ejecuta el arranque de servicios y el registro de Macros de Eloquent.
     *
     * @return void
     */
    public function boot(): void
    {
        // Publicar archivo de configuración
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/rest-processor.php' => config_path('rest-processor.php'),
            ], 'rest-processor-config');
        }

        // Registrar Macro sobre Eloquent Builder: Model::processRest() o Model::where(...)->processRest()
        Builder::macro('processRest', function (?Request $request = null): RestProcessor {
            /** @var Builder $this */
            return RestProcessor::for($this, $request);
        });

        // Registrar Macro sobre Eloquent Relation: $user->posts()->processRest()
        Relation::macro('processRest', function (?Request $request = null): RestProcessor {
            /** @var Relation $this */
            return RestProcessor::for($this, $request);
        });
    }
}
