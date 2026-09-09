<?php

declare(strict_types=1);

namespace Warrior\RestProcessor\Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Warrior\RestProcessor\RestProcessor;
use Warrior\RestProcessor\Tests\Fixtures\Models\Post;
use Warrior\RestProcessor\Tests\Fixtures\Models\Role;
use Warrior\RestProcessor\Tests\Fixtures\Models\User;
use Warrior\RestProcessor\Tests\TestCase;

final class IncludeAndCountTest extends TestCase
{
    /**
     * Escenario: Se solicitan relaciones autorizadas con ?include=roles.
     * Expectativa: Se realiza Eager Loading sin N+1 (exactamente 2 queries SQL: 1 base + 1 relación).
     */
    #[Test]
    public function it_prevents_n_plus_one_with_eager_loading(): void
    {
        // given
        $role1 = Role::create(['name' => 'Admin', 'code' => 'ADMIN']);
        $role2 = Role::create(['name' => 'User', 'code' => 'USER']);

        for ($i = 0; $i < 5; $i++) {
            $u = User::create(['name' => "User {$i}", 'email' => "user{$i}@test.com"]);
            $u->roles()->attach([$role1->id, $role2->id]);
        }

        $request = Request::create('/api/users', 'GET', [
            'include' => 'roles',
        ]);

        DB::enableQueryLog();

        // when
        $results = RestProcessor::for(User::class, $request)
            ->allowedIncludes(['roles'])
            ->get();

        // Iterar sobre las relaciones de cada modelo
        foreach ($results as $user) {
            $this->assertTrue($user->relationLoaded('roles'));
            $this->assertCount(2, $user->roles);
        }

        $queries = DB::getQueryLog();

        // then: 1 query para count (paginador) + 1 query para usuarios + 1 query para roles = exactamente 3 queries en total
        // (y NUNCA 1 query por cada usuario)
        $this->assertLessThanOrEqual(3, count($queries));
    }

    /**
     * Escenario: El cliente solicita el conteo de una relación autorizada (?count=posts).
     * Expectativa: Se aplica withCount('posts') y cada usuario expone posts_count sin hidratar los modelos de post.
     */
    #[Test]
    public function it_loads_relation_counts_with_with_count(): void
    {
        // given
        $user1 = User::create(['name' => 'Alice', 'email' => 'alice@test.com']);
        Post::create(['user_id' => $user1->id, 'title' => 'Post 1']);
        Post::create(['user_id' => $user1->id, 'title' => 'Post 2']);

        $user2 = User::create(['name' => 'Bob', 'email' => 'bob@test.com']);
        Post::create(['user_id' => $user2->id, 'title' => 'Post 3']);

        $request = Request::create('/api/users', 'GET', [
            'count' => 'posts',
        ]);

        // when
        $results = RestProcessor::for(User::class, $request)
            ->allowedCounts(['posts'])
            ->get();

        // then
        $alice = $results->firstWhere('name', 'Alice');
        $bob = $results->firstWhere('name', 'Bob');

        $this->assertSame(2, $alice->posts_count);
        $this->assertSame(1, $bob->posts_count);
    }

    /**
     * Escenario: El cliente intenta incluir una relación no autorizada.
     * Expectativa: Se rechaza con ValidationException (HTTP 422).
     */
    #[Test]
    public function it_throws_validation_exception_on_unauthorized_include(): void
    {
        // given
        $request = Request::create('/api/users', 'GET', [
            'include' => 'secret_audit_logs',
        ]);

        // then
        $this->expectException(ValidationException::class);

        // when
        RestProcessor::for(User::class, $request)
            ->allowedIncludes(['roles'])
            ->get();
    }
}
