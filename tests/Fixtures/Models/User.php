<?php

declare(strict_types=1);

namespace Warrior\ApiQueryBuilder\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

final class User extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected $casts = [
        'extra_data' => 'array',
        'score' => 'decimal:2',
    ];

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }

    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }

    // Local Scope para probar Caso A (Booleano)
    public function scopeActive(Builder $query, bool $value = true): Builder
    {
        return $value
            ? $query->where('status', 'active')
            : $query->where('status', '!=', 'active');
    }

    // Local Scope para probar Caso D (Soft Deletes)
    public function scopeTrashed(Builder $query, string $value): Builder
    {
        return match ($value) {
            'with' => $query->withTrashed(),
            'only' => $query->onlyTrashed(),
            default => $query->withoutTrashed(),
        };
    }
}
