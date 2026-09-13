<?php

declare(strict_types=1);

namespace Warrior\ApiQueryBuilder\Tests\Feature;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use Warrior\ApiQueryBuilder\ApiQueryBuilder;
use Warrior\ApiQueryBuilder\Tests\Fixtures\Models\Post;
use Warrior\ApiQueryBuilder\Tests\Fixtures\Models\User;
use Warrior\ApiQueryBuilder\Tests\TestCase;

final class AdvancedOperatorsTest extends TestCase
{
    /**
     * Escenario: El cliente filtra utilizando operadores de texto (starts_with, ends_with, not_contains).
     * Expectativa: Se devuelven únicamente las cadenas que satisfacen la regla literal de texto.
     */
    #[Test]
    public function it_filters_using_advanced_text_operators(): void
    {
        // given
        User::create(['name' => 'Alexander The Great', 'email' => 'alex@greek.org']);
        User::create(['name' => 'Alexis Sanchez', 'email' => 'alexis@chile.org']);
        $noisy = User::create(['name' => 'Bob Alexander', 'email' => 'bob@alex.org']);

        // Probar starts_with
        $request1 = Request::create('/api/users', 'GET', [
            'filter' => ['name' => ['starts_with' => 'Alex']],
        ]);

        $results1 = ApiQueryBuilder::for(User::class, $request1)
            ->allowedFilters(['name'])
            ->get();

        $this->assertCount(2, $results1);
        $this->assertTrue($results1->contains('name', 'Alexander The Great'));
        $this->assertTrue($results1->contains('name', 'Alexis Sanchez'));
        $this->assertFalse($results1->contains('name', 'Bob Alexander'));

        // Probar ends_with
        $request2 = Request::create('/api/users', 'GET', [
            'filter' => ['name' => ['ends_with' => 'Sanchez']],
        ]);

        $results2 = ApiQueryBuilder::for(User::class, $request2)
            ->allowedFilters(['name'])
            ->get();

        $this->assertCount(1, $results2);
        $this->assertSame('Alexis Sanchez', $results2->first()->name);
    }

    /**
     * Escenario: El cliente filtra utilizando operadores de nulos y cadenas vacías (is_null, is_not_null).
     * Expectativa: Se filtran correctamente los registros nulos en la columna.
     */
    #[Test]
    public function it_filters_using_null_operators(): void
    {
        // given
        User::create(['name' => 'Alice', 'email' => 'alice@test.com', 'extra_data' => ['role' => 'admin']]);
        User::create(['name' => 'Bob', 'email' => 'bob@test.com', 'extra_data' => null]);

        $request = Request::create('/api/users', 'GET', [
            'filter' => ['extra_data' => ['is_null' => 'true']],
        ]);

        // when
        $results = ApiQueryBuilder::for(User::class, $request)
            ->allowedFilters(['extra_data'])
            ->get();

        // then
        $this->assertCount(1, $results);
        $this->assertSame('Bob', $results->first()->name);
    }

    /**
     * Escenario: El cliente filtra por fecha usando date_eq o year.
     * Expectativa: Se ejecuta whereDate o whereYear sobre la columna temporal.
     */
    #[Test]
    public function it_filters_using_date_and_year_operators(): void
    {
        // given
        $u1 = User::create(['name' => 'Alice', 'email' => 'a@test.com']);
        $u1->created_at = '2025-05-15 10:00:00';
        $u1->save();

        $u2 = User::create(['name' => 'Bob', 'email' => 'b@test.com']);
        $u2->created_at = '2026-01-20 14:00:00';
        $u2->save();

        // Probar year
        $request = Request::create('/api/users', 'GET', [
            'filter' => ['created_at' => ['year' => 2026]],
        ]);

        // when
        $results = ApiQueryBuilder::for(User::class, $request)
            ->allowedFilters(['created_at'])
            ->get();

        // then
        $this->assertCount(1, $results);
        $this->assertSame('Bob', $results->first()->name);
    }

    /**
     * Escenario: El desarrollador autoriza el comodín global '*' en allowedFilters.
     * Expectativa: Se permite filtrar por cualquier columna de la tabla base sin enumerarlas una a una.
     */
    #[Test]
    public function it_supports_global_wildcard_for_all_direct_columns(): void
    {
        // given
        User::create(['name' => 'Alice', 'email' => 'alice@test.com', 'status' => 'active']);
        $noisy = User::create(['name' => 'Bob', 'email' => 'bob@test.com', 'status' => 'inactive']);

        $request = Request::create('/api/users', 'GET', [
            'filter' => [
                'name' => 'Alice',
                'status' => 'active',
            ],
        ]);

        // when
        $results = ApiQueryBuilder::for(User::class, $request)
            ->allowedFilters(['*'])
            ->get();

        // then
        $this->assertCount(1, $results);
        $this->assertSame('Alice', $results->first()->name);
    }

    /**
     * Escenario: El cliente solicita paginación en modo 'simple' (?pagination=simple).
     * Expectativa: Se devuelve una instancia de Paginator sin ejecutar count(*) innecesario.
     */
    #[Test]
    public function it_supports_simple_pagination_mode(): void
    {
        // given
        for ($i = 1; $i <= 10; $i++) {
            User::create(['name' => "User {$i}", 'email' => "user{$i}@test.com"]);
        }

        $request = Request::create('/api/users', 'GET', [
            'pagination' => 'simple',
            'per_page' => '3',
        ]);

        // when
        $paginator = ApiQueryBuilder::for(User::class, $request)->get();

        // then: simplePaginate() devuelve Illuminate\Pagination\Paginator, no LengthAwarePaginator
        $this->assertInstanceOf(Paginator::class, $paginator);
        $this->assertNotInstanceOf(LengthAwarePaginator::class, $paginator);
        $this->assertSame(3, $paginator->perPage());
    }

    /**
     * Escenario: Ordenamiento relacional usando subconsulta explícita en la consulta base (patrón seguro SPEC-004).
     * Expectativa: Ordena por la subconsulta autorizada como alias sin provocar duplicidad de filas.
     */
    #[Test]
    public function it_supports_relation_sorting_via_explicit_subquery(): void
    {
        // given
        $u1 = User::create(['name' => 'Alice', 'email' => 'a@test.com']);
        Post::create(['user_id' => $u1->id, 'title' => 'Zebra Post']);

        $u2 = User::create(['name' => 'Bob', 'email' => 'b@test.com']);
        Post::create(['user_id' => $u2->id, 'title' => 'Apple Post']);

        $request = Request::create('/api/users', 'GET', [
            'sort' => 'latest_post_title',
        ]);

        // Base query con subselect explícito
        $baseQuery = User::query()->addSelect([
            'latest_post_title' => Post::select('title')
                ->whereColumn('posts.user_id', 'users.id')
                ->latest()
                ->limit(1),
        ]);

        // when
        $results = ApiQueryBuilder::for($baseQuery, $request)
            ->allowedSorts(['name', 'latest_post_title'])
            ->get();

        // then: 'Apple Post' (Bob) debe venir antes de 'Zebra Post' (Alice)
        $this->assertSame('Bob', $results->get(0)->name);
        $this->assertSame('Alice', $results->get(1)->name);
    }
}
