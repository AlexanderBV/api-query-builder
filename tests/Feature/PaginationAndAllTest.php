<?php

declare(strict_types=1);

namespace Warrior\RestProcessor\Tests\Feature;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Warrior\RestProcessor\RestProcessor;
use Warrior\RestProcessor\Tests\Fixtures\Models\User;
use Warrior\RestProcessor\Tests\Fixtures\Resources\UserResource;
use Warrior\RestProcessor\Tests\TestCase;

final class PaginationAndAllTest extends TestCase
{
    /**
     * Escenario: Consulta estándar sin all=true.
     * Expectativa: Retorna un objeto paginador LengthAwarePaginator de Laravel con tamaño configurado.
     */
    #[Test]
    public function it_paginates_records_by_default(): void
    {
        // given
        for ($i = 1; $i <= 20; $i++) {
            User::create(['name' => "User {$i}", 'email' => "user{$i}@test.com"]);
        }

        $request = Request::create('/api/users', 'GET', [
            'per_page' => '5',
            'page'     => '2',
        ]);

        // when
        $paginator = RestProcessor::for(User::class, $request)->get();

        // then
        $this->assertInstanceOf(LengthAwarePaginator::class, $paginator);
        $this->assertSame(20, $paginator->total());
        $this->assertSame(5, $paginator->perPage());
        $this->assertSame(2, $paginator->currentPage());
        $this->assertCount(5, $paginator->items());
    }

    /**
     * Escenario: El cliente envía all=true pero el endpoint NO invocó allowAll().
     * Expectativa: Se rechaza con ValidationException (HTTP 422).
     */
    #[Test]
    public function it_rejects_all_true_when_allow_all_is_not_enabled(): void
    {
        // given
        $request = Request::create('/api/users', 'GET', [
            'all' => 'true',
        ]);

        // then
        $this->expectException(ValidationException::class);

        // when
        RestProcessor::for(User::class, $request)->get();
    }

    /**
     * Escenario: El endpoint autorizó allowAll() y el cliente envía all=true.
     * Expectativa: ->get() devuelve una Collection y ->response() devuelve un array plano de objetos [ {...} ].
     */
    #[Test]
    public function it_returns_flat_array_when_all_true_is_allowed(): void
    {
        // given
        User::create(['name' => 'Alice', 'email' => 'alice@test.com']);
        User::create(['name' => 'Bob', 'email' => 'bob@test.com']);

        $request = Request::create('/api/users', 'GET', [
            'all' => 'true',
        ]);

        $processor = RestProcessor::for(User::class, $request)->allowAll();

        // when (get)
        $collection = $processor->get();

        // then (get)
        $this->assertInstanceOf(Collection::class, $collection);
        $this->assertCount(2, $collection);

        // when (response directo sin Resource)
        $response = $processor->response();
        $data = $response->getData(true);

        // then (response)
        // Debe ser un array plano indexado, NO un objeto con claves 'data' ni 'links'
        $this->assertIsArray($data);
        $this->assertTrue(array_is_list($data));
        $this->assertCount(2, $data);
        $names = array_column($data, 'name');
        $this->assertContains('Alice', $names);
        $this->assertContains('Bob', $names);
    }

    /**
     * Escenario: all=true con allowAll() formateado mediante un API Resource (UserResource).
     * Expectativa: Se devuelven los modelos transformados con la estructura del Resource en un array plano.
     */
    #[Test]
    public function it_formats_all_true_collection_with_api_resource(): void
    {
        // given
        User::create(['name' => 'Alice', 'email' => 'alice@test.com', 'score' => 99.00]);

        $request = Request::create('/api/users', 'GET', [
            'all' => 'true',
        ]);

        // when
        $response = RestProcessor::for(User::class, $request)
            ->allowAll()
            ->response(UserResource::class);

        $data = $response->getData(true);

        // then
        $this->assertIsArray($data);
        $this->assertSame('Alice', $data[0]['name']);
        $this->assertEquals(99.0, $data[0]['score']);
    }

    /**
     * Escenario: El total de registros en BD supera el límite de seguridad (maxAllLimit).
     * Expectativa: Se rechaza con ValidationException (HTTP 422) previniendo Out Of Memory (OOM).
     */
    #[Test]
    public function it_enforces_anti_oom_limit_when_all_true_exceeds_max_limit(): void
    {
        // given
        for ($i = 1; $i <= 10; $i++) {
            User::create(['name' => "User {$i}", 'email' => "user{$i}@test.com"]);
        }

        $request = Request::create('/api/users', 'GET', [
            'all' => 'true',
        ]);

        // then
        $this->expectException(ValidationException::class);

        // when: límite configurado en 5 pero hay 10 registros
        RestProcessor::for(User::class, $request)
            ->allowAll(maxLimitOrUnlimited: 5)
            ->get();
    }

    /**
     * Escenario: Uso de la Macro registrada en Eloquent (User::processRest()).
     * Expectativa: Funciona de forma idéntica a RestProcessor::for(User::class).
     */
    #[Test]
    public function it_supports_eloquent_macro_syntax(): void
    {
        // given
        User::create(['name' => 'Alice', 'email' => 'alice@test.com', 'status' => 'active']);
        $noisyUser = User::create(['name' => 'Bob', 'email' => 'bob@test.com', 'status' => 'inactive']);

        $request = Request::create('/api/users', 'GET', [
            'filter' => ['status' => 'active'],
        ]);

        // when
        $results = User::processRest($request)
            ->allowedFilters(['status'])
            ->get();

        // then
        $this->assertCount(1, $results);
        $this->assertSame('Alice', $results->first()->name);
    }
}
