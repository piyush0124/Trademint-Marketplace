<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A business can serve multiple locations (its "service areas") — this is
 * separate from `businesses.location_id`, which is the business's own
 * registered/home location. Reuses the existing, data-driven `locations`
 * table rather than a hardcoded city list.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_service_areas', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['business_id', 'location_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_service_areas');
    }
};
