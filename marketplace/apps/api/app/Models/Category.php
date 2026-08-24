<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    use HasFactory;

    protected $fillable = [
        'parent_id',
        'slug',
        'name',
        'description',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Category::class, 'parent_id')->orderBy('sort_order');
    }

    public function attributeDefinitions(): HasMany
    {
        return $this->hasMany(CategoryAttributeDefinition::class)->orderBy('sort_order');
    }

    /**
     * Businesses whose primary category this is.
     *
     * Only the declared primary category — a supplier that merely lists a product in
     * this category is not counted here. Used to rank suggestions and category tiles by
     * real supplier presence, so a buyer is never sent to an empty category page.
     */
    public function businesses(): HasMany
    {
        return $this->hasMany(Business::class);
    }

    public function listings(): HasMany
    {
        return $this->hasMany(Listing::class);
    }
}
