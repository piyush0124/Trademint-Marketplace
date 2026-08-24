<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 needs a rating signal for two reasons: the customer's matched
 * business list has to display a rating where one exists, and the matching
 * engine's trust dimension reads it.
 *
 * Both columns are nullable / zero-defaulted on purpose. There is no review
 * system yet, so for every existing business `rating` stays null and the
 * trust score falls back to verification + tenure + responsiveness instead
 * of inventing a number. A null rating means "not rated yet", never "bad".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table): void {
            $table->decimal('rating', 3, 2)->nullable()->after('is_available');
            $table->unsignedInteger('review_count')->default(0)->after('rating');
        });
    }

    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table): void {
            $table->dropColumn(['rating', 'review_count']);
        });
    }
};
