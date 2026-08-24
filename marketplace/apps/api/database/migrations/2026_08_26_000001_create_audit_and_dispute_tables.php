<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 10 — audit trail and disputes.
 *
 * `audit_logs` is append-only and deliberately minimal: who, what action, which
 * entity, when, from where, plus a small metadata bag. It stores *no* payloads,
 * credentials or personal data beyond the acting user id and an IP — enough to
 * answer "who changed this" without becoming a second copy of the database.
 *
 * `disputes` is the foundation the spec asks for, not an arbitration engine: an
 * order, a raising party, a reason, evidence notes, a status and admin resolution.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->id();
            // Nullable: system actions have no user, and deleting a user must not
            // erase the trail of what they did.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('actor_role', 20)->nullable();

            $table->string('action', 80);
            $table->string('entity_type', 60)->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            // Human-readable reference (order number, quote number, slug).
            $table->string('entity_reference', 60)->nullable();

            $table->string('ip_address', 45)->nullable();
            // Small, non-sensitive context only — never request bodies or secrets.
            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->index(['entity_type', 'entity_id']);
            $table->index(['action', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('disputes', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('reference', 30)->unique();

            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();

            // customer | business — who raised it.
            $table->string('raised_by_party', 20);
            $table->foreignId('raised_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('reason', 60);
            $table->text('description');
            // Free-text references to evidence (invoice numbers, photos shared out
            // of band). Deliberately not a file store in this phase.
            $table->text('evidence')->nullable();

            $table->string('status', 20)->default('open');
            $table->text('admin_notes')->nullable();
            $table->text('resolution')->nullable();
            $table->foreignId('resolved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();

            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['customer_id', 'status']);
            $table->index(['business_id', 'status']);
            $table->index('order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('disputes');
        Schema::dropIfExists('audit_logs');
    }
};
