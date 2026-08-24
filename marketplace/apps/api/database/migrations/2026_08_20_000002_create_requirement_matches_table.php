<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Requirement <-> Business relationship produced by the matching engine
 * (Phase 4). One row is one business invited to one requirement.
 *
 * The score and reason are stored rather than recomputed on read: the
 * customer and the business must see the same explanation the engine
 * produced at invitation time, even after the business later edits its
 * catalogue or the weights are re-tuned. Re-running matching refreshes
 * score/reason but never rewrites invited_at, viewed_at or the response.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('requirement_matches', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('requirement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();

            // 0.00 - 100.00, the weighted percentage produced by the engine.
            $table->decimal('match_score', 5, 2)->default(0);
            // Per-dimension breakdown + human readable reasons ("why matched").
            $table->json('match_reason')->nullable();

            $table->timestamp('invited_at')->nullable();
            $table->timestamp('viewed_at')->nullable();

            $table->string('response_status', 20)->default('pending');
            $table->timestamp('responded_at')->nullable();
            // Free text the business supplies when declining.
            $table->text('response_note')->nullable();

            // A business may ask the customer a clarifying question without
            // committing either way — quotations are a later phase, so this
            // is deliberately a single question thread, not a message table.
            $table->text('question')->nullable();
            $table->timestamp('question_asked_at')->nullable();

            $table->timestamps();

            // A business is invited to a given requirement at most once.
            $table->unique(['requirement_id', 'business_id']);
            // Customer view: best matches for one requirement, ranked.
            $table->index(['requirement_id', 'match_score']);
            // Business dashboard: my matched requirements, newest first.
            $table->index(['business_id', 'response_status', 'invited_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('requirement_matches');
    }
};
