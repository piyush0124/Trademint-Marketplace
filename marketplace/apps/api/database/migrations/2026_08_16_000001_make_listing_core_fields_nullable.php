<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A DRAFT listing is intentionally allowed to be incomplete (title/price
     * are filled in over later wizard steps), but the original migration
     * declared these NOT NULL — every "create draft with just a category"
     * request was crashing with a SQL constraint violation. This makes the
     * schema match the workflow the API actually implements.
     */
    public function up(): void
    {
        Schema::table('listings', function (Blueprint $table): void {
            $table->string('title')->nullable()->change();
            $table->decimal('price', 15, 2)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('listings', function (Blueprint $table): void {
            $table->string('title')->nullable(false)->change();
            $table->decimal('price', 15, 2)->nullable(false)->change();
        });
    }
};
