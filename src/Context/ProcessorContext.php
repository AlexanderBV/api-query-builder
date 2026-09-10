<?php

declare(strict_types=1);

namespace Warrior\ApiQueryBuilder\Context;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Encapsula el estado inmutable durante el ciclo de vida de la consulta en el Pipeline.
 */
final class ProcessorContext
{
    /**
     * @param Builder $builder Instancia activa del Query Builder de Eloquent
     * @param Request $request Petición HTTP que contiene los query params
     * @param ProcessorConfig $config Reglas y opciones autorizadas
     * @param mixed $result Resultado resuelto por el PaginationPipe (Paginator o Collection)
     */
    public function __construct(
        public Builder $builder,
        public Request $request,
        public ProcessorConfig $config,
        public mixed $result = null,
    ) {}

    /**
     * Clona el contexto con un nuevo resultado resuelto.
     *
     * @param mixed $result
     * @return self
     */
    public function withResult(mixed $result): self
    {
        return new self(
            $this->builder,
            $this->request,
            $this->config,
            $result,
        );
    }
}
