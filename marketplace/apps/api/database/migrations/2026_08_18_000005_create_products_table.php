<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A Product describes a general capability a Business offers (its
 * catalog), distinct from a Listing (a specific marketplace-searchable
 * item). E.g. a Business might define the Product "Cotton T-Shirts" once,
 * and separately publish several Listings for specific batches/variants.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('unit', 30)->nullable();
            $table->unsignedInteger('min_order')->nullable();
            $table->unsignedInteger('max_order')->nullable();
            $table->string('capacity', 100)->nullable();
            $table->boolean('customization_available')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['business_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
