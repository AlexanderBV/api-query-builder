<?php

declare(strict_types=1);

namespace Warrior\RestProcessor\Tests\Feature;

use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use Warrior\RestProcessor\RestProcessor;
use Warrior\RestProcessor\Tests\Fixtures\Models\User;
use Warrior\RestProcessor\Tests\TestCase;

final class LogicalGroupTest extends TestCase
{
    /**
     * Escenario: El cliente envía un grupo OR con dos condiciones: status = active OR score >= 90.
     * Expectativa: Se devuelven los usuarios que cumplen al menos una de las dos condiciones.
     */
    #[Test]
    public function it_evaluates_or_groups_correctly(): void
    {
        // given
        // Cumple por status:
        $u1 = User::create(['name' => 'Alice', 'email' => 'a@test.com', 'status' => 'active', 'score' => 50.00]);
        // Cumple por score:
        $u2 = User::create(['name' => 'Bob', 'email' => 'b@test.com', 'status' => 'inactive', 'score' => 95.00]);
        // No cumple ninguna:
        $noisy = User::create(['name' => 'Charlie', 'email' => 'c@test.com', 'status' => 'inactive', 'score' => 40.00]);

        $request = Request::create('/api/users', 'GET', [
            'filter' => [
                'or' => [
                    ['status' => 'active'],
                    ['score' => ['gte' => 90.00]],
                ],
            ],
        ]);

        // when
        $results = RestProcessor::for(User::class, $request)
            ->allowedFilters(['status', 'score'])
            ->get();

        // then
        $this->assertCount(2, $results);
        $this->assertTrue($results->contains('name', 'Alice'));
        $this->assertTrue($results->contains('name', 'Bob'));
        $this->assertFalse($results->contains('name', 'Charlie'));
    }

    /**
     * Escenario: Árbol anidado complejo: (status = active AND score >= 80) OR (name = 'VIP User').
     * Expectativa: El procesador preserva los paréntesis y la jerarquía de operadores booleanos en SQL.
     */
    #[Test]
    public function it_evaluates_nested_hierarchical_and_or_trees(): void
    {
        // given
        // Rama 1: status = active AND score >= 80
        $u1 = User::create(['name' => 'Alice', 'email' => 'a@test.com', 'status' => 'active', 'score' => 85.00]);
        // Cumple status pero NO score:
        $u2 = User::create(['name' => 'Bob', 'email' => 'b@test.com', 'status' => 'active', 'score' => 60.00]);
        // Rama 2: name = VIP User (aunque status sea inactive y score bajo)
        $u3 = User::create(['name' => 'VIP User', 'email' => 'vip@test.com', 'status' => 'inactive', 'score' => 10.00]);
        // No cumple nada:
        $noisy = User::create(['name' => 'Charlie', 'email' => 'c@test.com', 'status' => 'inactive', 'score' => 50.00]);

        $request = Request::create('/api/users', 'GET', [
            'filter' => [
                'or' => [
                    [
                        'and' => [
                            ['status' => 'active'],
                            ['score' => ['gte' => 80.00]],
                        ],
                    ],
                    [
                        'name' => 'VIP User',
                    ],
                ],
            ],
        ]);

        // when
        $results = RestProcessor::for(User::class, $request)
            ->allowedFilters(['status', 'score', 'name'])
            ->get();

        // then
        $this->assertCount(2, $results);
        $this->assertTrue($results->contains('name', 'Alice'));
        $this->assertTrue($results->contains('name', 'VIP User'));
        $this->assertFalse($results->contains('name', 'Bob'));
        $this->assertFalse($results->contains('name', 'Charlie'));
    }
}
