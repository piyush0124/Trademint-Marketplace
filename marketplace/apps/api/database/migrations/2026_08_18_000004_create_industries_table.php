<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Industries served" — a small, data-driven lookup table (e.g. Textiles,
 * Electronics, Food & Beverage), not hardcoded into business logic. Admin
 * manages the list; businesses select from it via the pivot.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('industries', function (Blueprint $table): void {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('business_industry', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('industry_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['business_id', 'industry_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_industry');
        Schema::dropIfExists('industries');
    }
};
