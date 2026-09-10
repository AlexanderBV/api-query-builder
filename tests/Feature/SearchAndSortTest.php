<?php

declare(strict_types=1);

namespace Warrior\ApiQueryBuilder\Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Warrior\ApiQueryBuilder\ApiQueryBuilder;
use Warrior\ApiQueryBuilder\Tests\Fixtures\Models\Role;
use Warrior\ApiQueryBuilder\Tests\Fixtures\Models\User;
use Warrior\ApiQueryBuilder\Tests\TestCase;

final class SearchAndSortTest extends TestCase
{
    /**
     * Escenario: El cliente envía un término de búsqueda global sobre columnas autorizadas.
     * Expectativa: Se devuelven los registros que coinciden en cualquiera de las columnas (OR agrupado).
     */
    #[Test]
    public function it_performs_global_search_across_multiple_columns(): void
    {
        // given
        User::create(['name' => 'Alice Smith', 'email' => 'asmith@test.com']);
        User::create(['name' => 'John Doe', 'email' => 'alice_fan@test.com']);
        $noisyUser = User::create(['name' => 'Bob Brown', 'email' => 'bbrown@test.com']);

        $request = Request::create('/api/users', 'GET', [
            'search' => 'Alice',
        ]);

        // when
        $results = ApiQueryBuilder::for(User::class, $request)
            ->allowedSearch(['name', 'email'])
            ->get();

        // then
        $this->assertCount(2, $results);
        $this->assertTrue($results->contains('name', 'Alice Smith'));
        $this->assertTrue($results->contains('name', 'John Doe'));
        $this->assertFalse($results->contains('name', 'Bob Brown'));
    }

    /**
     * Escenario: La búsqueda global incluye una columna de una relación (roles.name).
     * Expectativa: Se ejecuta un orWhereHas y encuentra al usuario por el rol coincidente.
     */
    #[Test]
    public function it_performs_global_search_across_relations(): void
    {
        // given
        $managerRole = Role::create(['name' => 'Operations Manager', 'code' => 'OPS']);
        $devRole = Role::create(['name' => 'Backend Developer', 'code' => 'DEV']);

        $user1 = User::create(['name' => 'Carlos', 'email' => 'carlos@test.com']);
        $user1->roles()->attach($managerRole);

        $noisyUser = User::create(['name' => 'Daniel', 'email' => 'daniel@test.com']);
        $noisyUser->roles()->attach($devRole);

        $request = Request::create('/api/users', 'GET', [
            'search' => 'Manager',
        ]);

        // when
        $results = ApiQueryBuilder::for(User::class, $request)
            ->allowedSearch(['name', 'roles.name'])
            ->get();

        // then
        $this->assertCount(1, $results);
        $this->assertSame('Carlos', $results->first()->name);
    }

    /**
     * Escenario: El cliente ordena por múltiples columnas con prefijo '-' para descendente.
     * Expectativa: Se aplican los orderBy en el orden especificado.
     */
    #[Test]
    public function it_sorts_records_by_multiple_columns(): void
    {
        // given
        $u1 = User::create(['name' => 'Alice', 'email' => 'a@test.com', 'score' => 50.00]);
        $u2 = User::create(['name' => 'Bob', 'email' => 'b@test.com', 'score' => 90.00]);
        $u3 = User::create(['name' => 'Charlie', 'email' => 'c@test.com', 'score' => 90.00]);

        $request = Request::create('/api/users', 'GET', [
            'sort' => '-score,name',
        ]);

        // when
        $results = ApiQueryBuilder::for(User::class, $request)
            ->allowedSorts(['score', 'name'])
            ->get();

        // then
        $this->assertSame('Bob', $results->get(0)->name);
        $this->assertSame('Charlie', $results->get(1)->name);
        $this->assertSame('Alice', $results->get(2)->name);
    }

    /**
     * Escenario: El cliente intenta ordenar por una columna de una relación (roles.name).
     * Expectativa: Se rechaza con ValidationException (HTTP 422) protegiendo la cardinalidad de la paginación.
     */
    #[Test]
    public function it_rejects_relation_sorting_with_validation_exception(): void
    {
        // given
        $request = Request::create('/api/users', 'GET', [
            'sort' => 'roles.name',
        ]);

        // then
        $this->expectException(ValidationException::class);

        // when
        ApiQueryBuilder::for(User::class, $request)
            ->allowedSorts(['name'])
            ->get();
    }
}
