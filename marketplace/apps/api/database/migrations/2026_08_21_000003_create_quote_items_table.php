<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5 — the individual lines of a quote.
 *
 * One shape serves every category: "500 units x ₹800" and "Website
 * development x 1" are both just a description, a type, a quantity and a
 * unit price. `item_type` carries the four generic shapes (product, service,
 * project, custom) and never an industry-specific one.
 *
 * `reference_type` / `reference_id` optionally point a line at the catalogue
 * entry it came from (a Product or Service). Kept as a loose reference rather
 * than a hard foreign key on purpose: a quote line must survive the business
 * later deleting that catalogue entry, because the line is a historical
 * record of what was offered.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quote_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('quote_id')->constrained()->cascadeOnDelete();

            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('description');
            $table->string('item_type', 20)->default('custom');

            $table->string('reference_type', 40)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();

            // Fractional quantities are legitimate (2.5 tonnes, 7.5 hours).
            $table->decimal('quantity', 15, 3)->default(1);
            $table->string('unit', 30)->nullable();
            $table->decimal('unit_price', 15, 2)->default(0);

            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->decimal('discount_amount', 15, 2)->default(0);
            // subtotal = quantity x unit_price (before discount and tax).
            $table->decimal('subtotal', 15, 2)->default(0);
            // total = subtotal - discount_amount + tax_amount.
            $table->decimal('total', 15, 2)->default(0);

            $table->timestamps();

            $table->index(['quote_id', 'sort_order']);
            $table->index(['reference_type', 'reference_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quote_items');
    }
};
