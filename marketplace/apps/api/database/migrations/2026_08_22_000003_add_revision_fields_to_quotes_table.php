<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6 — points a quote at its current revision.
 *
 * Purely additive. The quote's own money columns keep mirroring the current
 * offer, so every Phase 5 endpoint, resource and screen continues to work
 * untouched; `current_revision_id` is what lets an order (Phase 7) record the
 * exact revision it was formed from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotes', function (Blueprint $table): void {
            $table->foreignId('current_revision_id')->nullable()->after('business_id')
                ->constrained('quote_revisions')->nullOnDelete();
            $table->unsignedSmallInteger('revision_count')->default(0)->after('current_revision_id');
        });
    }

    public function down(): void
    {
        Schema::table('quotes', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('current_revision_id');
            $table->dropColumn('revision_count');
        });
    }
};
