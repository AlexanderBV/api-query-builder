<?php

declare(strict_types=1);

namespace Warrior\ApiQueryBuilder\Tests\Feature;

use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use Warrior\ApiQueryBuilder\ApiQueryBuilder;
use Warrior\ApiQueryBuilder\Tests\Fixtures\Models\Role;
use Warrior\ApiQueryBuilder\Tests\Fixtures\Models\User;
use Warrior\ApiQueryBuilder\Tests\TestCase;

final class RelationAndJsonFilterTest extends TestCase
{
    /**
     * Escenario: El cliente filtra registros a través de una relación de Eloquent (roles.code).
     * Expectativa: El procesador ejecuta un whereHas relacional devolviendo solo los usuarios con ese rol.
     */
    #[Test]
    public function it_filters_records_via_eloquent_relation(): void
    {
        // given
        $adminRole = Role::create(['name' => 'Administrator', 'code' => 'ADMIN']);
        $userRole = Role::create(['name' => 'Regular User', 'code' => 'USER']);

        $admin = User::create(['name' => 'Alice Admin', 'email' => 'admin@test.com']);
        $admin->roles()->attach($adminRole);

        $regular = User::create(['name' => 'Bob Regular', 'email' => 'user@test.com']);
        $regular->roles()->attach($userRole);

        $request = Request::create('/api/users', 'GET', [
            'filter' => ['roles.code' => 'ADMIN'],
        ]);

        // when
        $results = ApiQueryBuilder::for(User::class, $request)
            ->allowedFilters(['roles.code'])
            ->get();

        // then
        $this->assertCount(1, $results);
        $this->assertSame('Alice Admin', $results->first()->name);
    }

    /**
     * Escenario: El desarrollador autoriza un comodín relacional (roles.*).
     * Expectativa: Se permite filtrar por cualquier atributo de la relación roles.
     */
    #[Test]
    public function it_supports_relation_wildcard_filtering(): void
    {
        // given
        $role = Role::create(['name' => 'Editor', 'code' => 'EDITOR']);
        $user = User::create(['name' => 'Alice', 'email' => 'alice@test.com']);
        $user->roles()->attach($role);

        $request = Request::create('/api/users', 'GET', [
            'filter' => ['roles.name' => 'Editor'],
        ]);

        // when
        $results = ApiQueryBuilder::for(User::class, $request)
            ->allowedFilters(['roles.*'])
            ->get();

        // then
        $this->assertCount(1, $results);
        $this->assertSame('Alice', $results->first()->name);
    }

    /**
     * Escenario: El cliente filtra por un atributo anidado dentro de una columna JSON (extra_data.settings.theme).
     * Expectativa: El procesador compila la notación de punto a la sintaxis nativa de flechas JSON en Eloquent.
     */
    #[Test]
    public function it_filters_nested_json_attributes(): void
    {
        // given
        User::create([
            'name' => 'Alice',
            'email' => 'alice@test.com',
            'extra_data' => ['settings' => ['theme' => 'dark']],
        ]);

        $noisyUser = User::create([
            'name' => 'Bob',
            'email' => 'bob@test.com',
            'extra_data' => ['settings' => ['theme' => 'light']],
        ]);

        $request = Request::create('/api/users', 'GET', [
            'filter' => ['extra_data.settings.theme' => 'dark'],
        ]);

        // when
        $results = ApiQueryBuilder::for(User::class, $request)
            ->allowedFilters(['extra_data.*'])
            ->get();

        // then
        $this->assertCount(1, $results);
        $this->assertSame('Alice', $results->first()->name);
    }
}
