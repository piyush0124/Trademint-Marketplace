<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MediaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => 'media',
            'attributes' => [
                'url' => $this->url(),
                'filename' => $this->filename,
                'mime_type' => $this->mime_type,
                'is_primary' => $this->is_primary,
                'sort_order' => $this->sort_order,
            ],
        ];
    }
}
