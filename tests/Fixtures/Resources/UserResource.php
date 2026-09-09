<?php

declare(strict_types=1);

namespace Warrior\RestProcessor\Tests\Fixtures\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class UserResource extends JsonResource
{
    /**
     * @param Request $request
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'name'           => $this->name,
            'email'          => $this->email,
            'status'         => $this->status,
            'score'          => (float) $this->score,
            'roles'          => $this->whenLoaded('roles'),
            'comments_count' => $this->whenCounted('comments'),
        ];
    }
}
