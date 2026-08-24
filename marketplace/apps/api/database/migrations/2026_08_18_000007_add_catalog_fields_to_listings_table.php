<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive fields on the existing `listings` table. `price` (already
 * present) becomes the range minimum when `price_max` is set; a listing
 * with only `price` behaves exactly as before (single fixed price).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('listings', function (Blueprint $table): void {
            $table->decimal('price_max', 15, 2)->nullable()->after('price');
            $table->string('unit', 30)->nullable()->after('price_max');
            $table->unsignedInteger('min_order_quantity')->nullable()->after('unit');
        });
    }

    public function down(): void
    {
        Schema::table('listings', function (Blueprint $table): void {
            $table->dropColumn(['price_max', 'unit', 'min_order_quantity']);
        });
    }
};
