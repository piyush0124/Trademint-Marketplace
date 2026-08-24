<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => 'user',
            'attributes' => [
                'name' => $this->name,
                'email' => $this->when($request->user()?->id === $this->id || $request->user()?->isAdmin(), $this->email),
                'role' => $this->role->value,
                'created_at' => $this->created_at?->toIso8601String(),
            ],
        ];
    }
}
