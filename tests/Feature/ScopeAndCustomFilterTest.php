<?php

declare(strict_types=1);

namespace Warrior\ApiQueryBuilder\Tests\Feature;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use Warrior\ApiQueryBuilder\Filters\Filter;
use Warrior\ApiQueryBuilder\ApiQueryBuilder;
use Warrior\ApiQueryBuilder\Tests\Fixtures\Models\User;
use Warrior\ApiQueryBuilder\Tests\TestCase;

final class ScopeAndCustomFilterTest extends TestCase
{
    /**
     * Escenario: El cliente filtra por un Local Scope registrado (scopeActive) con valor true.
     * Expectativa: El procesador detecta la convención e invoca el scope en el Builder.
     */
    #[Test]
    public function it_applies_local_scope_when_authorized(): void
    {
        // given
        User::create(['name' => 'Alice', 'email' => 'alice@test.com', 'status' => 'active']);
        $noisyUser = User::create(['name' => 'Bob', 'email' => 'bob@test.com', 'status' => 'inactive']);

        $request = Request::create('/api/users', 'GET', [
            'filter' => ['active' => 'true'],
        ]);

        // when
        $results = ApiQueryBuilder::for(User::class, $request)
            ->allowedFilters(['active'])
            ->get();

        // then
        $this->assertCount(1, $results);
        $this->assertSame('Alice', $results->first()->name);
    }

    /**
     * Escenario: El cliente filtra por Local Scope de Soft Deletes (scopeTrashed) para incluir eliminados.
     * Expectativa: El scope recibe 'with' y aplica withTrashed() sin acoplar la librería.
     */
    #[Test]
    public function it_applies_soft_deletes_scope_from_model(): void
    {
        // given
        $activeUser = User::create(['name' => 'Alice', 'email' => 'alice@test.com']);
        $deletedUser = User::create(['name' => 'Bob', 'email' => 'bob@test.com']);
        $deletedUser->delete();

        $request = Request::create('/api/users', 'GET', [
            'filter' => ['trashed' => 'with'],
        ]);

        // when
        $results = ApiQueryBuilder::for(User::class, $request)
            ->allowedFilters(['name', 'trashed'])
            ->get();

        // then
        $this->assertCount(2, $results);
        $this->assertTrue($results->contains('name', 'Alice'));
        $this->assertTrue($results->contains('name', 'Bob'));
    }

    /**
     * Escenario: El desarrollador registra un filtro ad-hoc mediante Filter::custom().
     * Expectativa: El procesador ejecuta el Closure sobre la consulta cuando el cliente envía el parámetro.
     */
    #[Test]
    public function it_applies_custom_closure_filter(): void
    {
        // given
        User::create(['name' => 'Alice', 'email' => 'alice@test.com', 'score' => 95.00]);
        $noisyUser = User::create(['name' => 'Bob', 'email' => 'bob@test.com', 'score' => 60.00]);

        $request = Request::create('/api/users', 'GET', [
            'filter' => ['is_honor_roll' => 'true'],
        ]);

        // when
        $results = ApiQueryBuilder::for(User::class, $request)
            ->allowedFilters([
                'name',
                Filter::custom('is_honor_roll', function (Builder $query, $value): void {
                    if ($value === 'true' || $value === true) {
                        $query->where('score', '>=', 90.00);
                    }
                }),
            ])
            ->get();

        // then
        $this->assertCount(1, $results);
        $this->assertSame('Alice', $results->first()->name);
    }
}
