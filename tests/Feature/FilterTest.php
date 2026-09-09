<?php

declare(strict_types=1);

namespace Warrior\RestProcessor\Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Warrior\RestProcessor\RestProcessor;
use Warrior\RestProcessor\Tests\Fixtures\Models\User;
use Warrior\RestProcessor\Tests\TestCase;

final class FilterTest extends TestCase
{
    /**
     * Escenario: El cliente filtra usuarios por igualdad simple en un campo permitido.
     * Expectativa: La consulta retorna únicamente los registros coincidentes.
     */
    #[Test]
    public function it_filters_records_by_simple_equality(): void
    {
        // given
        User::create(['name' => 'Alice', 'email' => 'alice@test.com', 'status' => 'active']);
        $noisyUser = User::create(['name' => 'Bob', 'email' => 'bob@test.com', 'status' => 'inactive']);

        $request = Request::create('/api/users', 'GET', [
            'filter' => ['status' => 'active'],
        ]);

        // when
        $results = RestProcessor::for(User::class, $request)
            ->allowedFilters(['name', 'status'])
            ->get();

        // then
        $this->assertCount(1, $results);
        $this->assertSame('Alice', $results->first()->name);
    }

    /**
     * Escenario: El cliente filtra registros utilizando operadores de comparación (gte, lte).
     * Expectativa: Se devuelven los registros cuyo valor numérico cumple el rango especificado.
     */
    #[Test]
    public function it_filters_records_using_comparison_operators(): void
    {
        // given
        User::create(['name' => 'Alice', 'email' => 'alice@test.com', 'score' => 95.50]);
        User::create(['name' => 'Bob', 'email' => 'bob@test.com', 'score' => 70.00]);
        $noisyUser = User::create(['name' => 'Charlie', 'email' => 'charlie@test.com', 'score' => 40.00]);

        $request = Request::create('/api/users', 'GET', [
            'filter' => [
                'score' => ['gte' => 70.00],
            ],
        ]);

        // when
        $results = RestProcessor::for(User::class, $request)
            ->allowedFilters(['score'])
            ->get();

        // then
        $this->assertCount(2, $results);
        $this->assertTrue($results->contains('name', 'Alice'));
        $this->assertTrue($results->contains('name', 'Bob'));
        $this->assertFalse($results->contains('name', 'Charlie'));
    }

    /**
     * Escenario: El cliente envía el operador 'in' con valores separados por coma (CSV).
     * Expectativa: Se normaliza internamente a array y se aplica whereIn correctamente.
     */
    #[Test]
    public function it_filters_records_using_in_operator_with_csv_string(): void
    {
        // given
        User::create(['name' => 'Alice', 'email' => 'alice@test.com', 'status' => 'active']);
        User::create(['name' => 'Bob', 'email' => 'bob@test.com', 'status' => 'pending']);
        $noisyUser = User::create(['name' => 'Charlie', 'email' => 'charlie@test.com', 'status' => 'archived']);

        $request = Request::create('/api/users', 'GET', [
            'filter' => [
                'status' => ['in' => 'active,pending'],
            ],
        ]);

        // when
        $results = RestProcessor::for(User::class, $request)
            ->allowedFilters(['status'])
            ->get();

        // then
        $this->assertCount(2, $results);
        $this->assertTrue($results->contains('name', 'Alice'));
        $this->assertTrue($results->contains('name', 'Bob'));
        $this->assertFalse($results->contains('name', 'Charlie'));
    }

    /**
     * Escenario: El cliente envía un filtro con valor de cadena vacía.
     * Expectativa: El filtro vacío se descarta automáticamente sin filtrar erróneamente todos los registros.
     */
    #[Test]
    public function it_discards_empty_string_filter_values(): void
    {
        // given
        User::create(['name' => 'Alice', 'email' => 'alice@test.com', 'status' => 'active']);
        User::create(['name' => 'Bob', 'email' => 'bob@test.com', 'status' => 'inactive']);

        $request = Request::create('/api/users', 'GET', [
            'filter' => [
                'status' => '',
            ],
        ]);

        // when
        $results = RestProcessor::for(User::class, $request)
            ->allowedFilters(['status'])
            ->get();

        // then
        $this->assertCount(2, $results);
    }

    /**
     * Escenario: Se configuran filtros por defecto (defaultFilters) y el cliente no envía valor.
     * Expectativa: Se aplica el filtro por defecto.
     */
    #[Test]
    public function it_applies_default_filters_when_client_omits_parameter(): void
    {
        // given
        User::create(['name' => 'Alice', 'email' => 'alice@test.com', 'status' => 'active']);
        $noisyUser = User::create(['name' => 'Bob', 'email' => 'bob@test.com', 'status' => 'inactive']);

        $request = Request::create('/api/users', 'GET', []);

        // when
        $results = RestProcessor::for(User::class, $request)
            ->allowedFilters(['status'])
            ->defaultFilters(['status' => 'active'])
            ->get();

        // then
        $this->assertCount(1, $results);
        $this->assertSame('Alice', $results->first()->name);
    }

    /**
     * Escenario: Se configuran filtros por defecto y el cliente envía un valor explícito diferente.
     * Expectativa: El valor del cliente sobrescribe el defaultFilter.
     */
    #[Test]
    public function it_overrides_default_filter_when_client_explicitly_provides_value(): void
    {
        // given
        $noisyUser = User::create(['name' => 'Alice', 'email' => 'alice@test.com', 'status' => 'active']);
        User::create(['name' => 'Bob', 'email' => 'bob@test.com', 'status' => 'inactive']);

        $request = Request::create('/api/users', 'GET', [
            'filter' => ['status' => 'inactive'],
        ]);

        // when
        $results = RestProcessor::for(User::class, $request)
            ->allowedFilters(['status'])
            ->defaultFilters(['status' => 'active'])
            ->get();

        // then
        $this->assertCount(1, $results);
        $this->assertSame('Bob', $results->first()->name);
    }

    /**
     * Escenario: El cliente intenta filtrar por un campo no autorizado en la lista blanca.
     * Expectativa: Se dispara una ValidationException de Laravel (HTTP 422) con el error específico del campo.
     */
    #[Test]
    public function it_throws_validation_exception_when_filtering_by_unauthorized_field(): void
    {
        // given
        $request = Request::create('/api/users', 'GET', [
            'filter' => ['secret_key' => '12345'],
        ]);

        // then
        $this->expectException(ValidationException::class);

        // when
        RestProcessor::for(User::class, $request)
            ->allowedFilters(['name', 'status'])
            ->get();
    }
}
