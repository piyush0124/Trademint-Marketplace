<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 11 — the AI layer's own storage.
 *
 * Five tables, and two deliberate omissions:
 *
 *  - **No `ai_usage_logs`.** `ai_requests` already is the per-request ledger.
 *    Daily/monthly usage is an aggregate query over it, so a second table would be
 *    the same facts written twice and free to disagree with itself.
 *  - **No `ai_audit_logs`.** Phase 10 already ships an append-only `audit_logs`
 *    table with actor, role, entity, IP and metadata, plus an `AuditLogger` that
 *    sanitises what goes in. AI actions are logged there under `ai.*` action names,
 *    so staff read one trail instead of two. The brief's "do not create duplicate
 *    systems" rule outranks its table list.
 *
 * `ai_embeddings` is also absent: semantic search is not implemented in these
 * phases, and the brief says to create that table only if it actually is. The
 * provider interface exposes `embed()` so it can be added without redesign.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * A single assistant thread. `type` scopes which system prompt and which
         * tools apply, and is validated against AiConversationType rather than
         * accepted freely.
         */
        Schema::create('ai_conversations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 50);
            $table->string('title')->nullable();
            // Small, non-sensitive scoping hints only (e.g. a requirement uuid the
            // thread is about). Never a copy of the records themselves.
            $table->json('context')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'type']);
            $table->index(['user_id', 'last_message_at']);
        });

        /*
         * Turns within a thread. `role` is one of system|user|assistant|tool.
         *
         * Token counts are nullable because not every provider reports them —
         * Ollama does, some hosted providers omit them for streamed replies. A null
         * means "not reported", which is honest; zero would be a lie.
         */
        Schema::create('ai_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ai_conversation_id')->constrained()->cascadeOnDelete();
            $table->string('role', 20);
            $table->text('content');
            // Tool calls, tool results, grounding references, confidence. Never
            // credentials and never a raw provider payload.
            $table->json('metadata')->nullable();
            $table->string('provider', 40)->nullable();
            $table->string('model', 100)->nullable();
            $table->unsignedInteger('prompt_tokens')->nullable();
            $table->unsignedInteger('completion_tokens')->nullable();
            $table->timestamps();

            $table->index(['ai_conversation_id', 'id']);
        });

        /*
         * One row per provider call, successful or not. This is the cost ledger and
         * the latency record, and it is what the daily quota is counted from.
         *
         * Note what is absent: no prompt text, no response text, no API key. A cost
         * ledger does not need the content, and storing it would duplicate
         * ai_messages while widening the blast radius of a database leak.
         */
        Schema::create('ai_requests', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            // Nullable so a system/scheduled AI call is still accounted for.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('actor_role', 20)->nullable();
            // Which product feature spent the money: assistant, requirement_builder,
            // search, matching, listing_generator, ...
            $table->string('feature', 60);
            $table->string('provider', 40);
            $table->string('model', 100);
            $table->string('status', 20);
            $table->unsignedInteger('latency_ms')->nullable();
            $table->unsignedInteger('prompt_tokens')->nullable();
            $table->unsignedInteger('completion_tokens')->nullable();
            $table->unsignedInteger('total_tokens')->nullable();
            // Six decimal places: per-1K-token pricing produces very small numbers
            // and rounding to paise would floor most single calls to zero.
            $table->decimal('estimated_cost', 12, 6)->nullable();
            $table->string('error_code', 60)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['feature', 'created_at']);
            $table->index(['status', 'created_at']);
        });

        /*
         * A write action the AI has proposed but not performed.
         *
         * This table is the confirmation system. A write tool never executes on the
         * turn it is chosen: it lands here as `pending_confirmation` and only runs
         * when the user explicitly confirms, through a separate authenticated
         * request. That makes "the AI did something I didn't ask for" structurally
         * impossible rather than a prompt-engineering hope.
         */
        Schema::create('ai_actions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('ai_conversation_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('tool', 80);
            // The exact validated arguments that will be replayed on confirmation.
            // Re-validated at execution time; never trusted just because they were
            // validated when proposed.
            $table->json('arguments');
            // Human-readable description of what will happen, shown at the
            // confirmation prompt. Generated server-side from the tool, not the LLM.
            $table->text('summary')->nullable();
            $table->string('status', 30);
            $table->json('result')->nullable();
            $table->string('failure_reason')->nullable();
            // Stale proposals must not be executable. Expiry is enforced on confirm.
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['ai_conversation_id', 'status']);
        });

        /*
         * Was this answer useful? One vote per user per message, so feedback can be
         * corrected but not stuffed.
         */
        Schema::create('ai_feedback', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ai_message_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // -1 or 1. A two-way vote gets used; a five-star scale on a chat reply
            // does not.
            $table->tinyInteger('rating');
            $table->text('comment')->nullable();
            $table->timestamps();

            $table->unique(['ai_message_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_feedback');
        Schema::dropIfExists('ai_actions');
        Schema::dropIfExists('ai_requests');
        Schema::dropIfExists('ai_messages');
        Schema::dropIfExists('ai_conversations');
    }
};
