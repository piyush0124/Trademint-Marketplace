<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Industry extends Model
{
    use HasFactory;

    protected $fillable = ['slug', 'name'];

    public function businesses(): BelongsToMany
    {
        return $this->belongsToMany(Business::class, 'business_industry')->withTimestamps();
    }
}
