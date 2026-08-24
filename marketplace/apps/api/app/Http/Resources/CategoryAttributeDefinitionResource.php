<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CategoryAttributeDefinitionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => 'category_attribute_definition',
            'attributes' => [
                'key' => $this->key,
                'label' => $this->label,
                'type' => $this->type->value,
                'options' => $this->options,
                'is_required' => $this->is_required,
                'is_filterable' => $this->is_filterable,
                'sort_order' => $this->sort_order,
            ],
        ];
    }
}
