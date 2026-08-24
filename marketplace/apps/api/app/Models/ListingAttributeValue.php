<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ListingAttributeValue extends Model
{
    protected $fillable = [
        'listing_id',
        'category_attribute_definition_id',
        'value',
    ];

    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }

    public function definition(): BelongsTo
    {
        return $this->belongsTo(CategoryAttributeDefinition::class, 'category_attribute_definition_id');
    }
}
