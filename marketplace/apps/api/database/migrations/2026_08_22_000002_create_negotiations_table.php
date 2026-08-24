<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6 — the negotiation over a quote, plus its timeline.
 *
 * One negotiation per quote (unique), created when the first counter offer is
 * made. `awaiting_party` is the turn indicator: whoever it names is the only
 * party allowed to counter, accept or reject, which is how "accepted → counter"
 * and "accept your own offer" are made unrepresentable rather than merely
 * validated against.
 *
 * `negotiation_messages` is an append-only timeline. Every commercial action
 * writes one, so the history reads as a conversation and is never rewritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('negotiations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('quote_id')->unique()->constrained()->cascadeOnDelete();
            // Denormalised for access checks and dashboard queries, exactly as
            // quotes does — and fixed for the life of the negotiation.
            $table->foreignId('requirement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();

            $table->string('status', 20)->default('open');
            $table->string('initiated_by', 20);        // customer | business
            // Whose response the negotiation is waiting on. Null once settled.
            $table->string('awaiting_party', 20)->nullable();
            $table->unsignedSmallInteger('round')->default(1);

            $table->foreignId('current_revision_id')->nullable()->constrained('quote_revisions')->nullOnDelete();

            $table->timestamp('last_activity_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('withdrawn_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->text('outcome_note')->nullable();

            $table->timestamps();

            $table->index(['customer_id', 'status', 'last_activity_at']);
            $table->index(['business_id', 'status', 'last_activity_at']);
            $table->index(['status', 'awaiting_party']);
        });

        Schema::create('negotiation_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('negotiation_id')->constrained()->cascadeOnDelete();
            // The offer this entry relates to, when it relates to one.
            $table->foreignId('quote_revision_id')->nullable()->constrained('quote_revisions')->nullOnDelete();

            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('author_party', 20);              // customer | business | system
            // counter | accept | reject | withdraw | close | message | system
            $table->string('entry_type', 20)->default('message');
            $table->text('body')->nullable();

            $table->timestamps();

            $table->index(['negotiation_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('negotiation_messages');
        Schema::dropIfExists('negotiations');
    }
};
