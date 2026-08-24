<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class IndustryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => 'industry',
            'attributes' => [
                'slug' => $this->slug,
                'name' => $this->name,
            ],
        ];
    }
}
