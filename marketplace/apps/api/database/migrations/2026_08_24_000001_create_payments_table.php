<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 8 — payments, webhook events and refunds.
 *
 * The three tables together give the properties a payment system has to have:
 *
 *  - `payments.idempotency_key` is unique, so a retried "pay now" cannot create a
 *    second attempt against the same order.
 *  - `payment_webhook_events(provider, event_id)` is unique, so a provider
 *    redelivering the same event cannot settle a payment twice. The raw payload is
 *    kept for dispute investigation.
 *  - `refunds` are their own records with their own references, so a partial
 *    refund history is auditable rather than a mutated amount.
 *
 * No provider is named anywhere: `provider` is a config key, and the columns are
 * the union of what any gateway needs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('payment_reference', 30)->unique();

            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();

            $table->string('provider', 40);
            $table->string('provider_payment_id', 191)->nullable();
            $table->string('provider_order_id', 191)->nullable();

            $table->decimal('amount', 15, 2);
            $table->char('currency', 3);
            $table->string('status', 25)->default('pending');
            $table->string('payment_method', 40)->nullable();

            // Non-sensitive provider extras only. Card numbers, tokens and
            // secrets must never be written here.
            $table->json('metadata')->nullable();
            $table->text('failure_reason')->nullable();

            // Supplied by the client (or derived) to make "pay now" idempotent.
            $table->string('idempotency_key', 100)->nullable()->unique();

            $table->decimal('amount_refunded', 15, 2)->default(0);

            $table->timestamp('initiated_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->timestamp('expires_at')->nullable();

            $table->timestamps();

            $table->index(['order_id', 'status']);
            $table->index(['customer_id', 'status', 'created_at']);
            $table->index(['business_id', 'status', 'created_at']);
            $table->index(['provider', 'provider_payment_id']);
        });

        Schema::create('payment_webhook_events', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 40);
            // The provider's own event id. Unique per provider — the whole
            // duplicate-webhook defence rests on this index.
            $table->string('event_id', 191);
            $table->string('event_type', 100)->nullable();

            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();

            $table->boolean('signature_valid')->default(false);
            // received | processed | ignored | rejected | failed
            $table->string('status', 20)->default('received');
            $table->text('error')->nullable();
            $table->json('payload')->nullable();

            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'event_id']);
            $table->index(['status', 'created_at']);
        });

        Schema::create('refunds', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('refund_reference', 30)->unique();

            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();

            $table->decimal('amount', 15, 2);
            $table->char('currency', 3);
            $table->string('status', 20)->default('pending');
            $table->text('reason')->nullable();

            $table->string('provider_refund_id', 191)->nullable();
            $table->text('failure_reason')->nullable();
            $table->json('metadata')->nullable();

            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('processed_at')->nullable();

            $table->timestamps();

            $table->index(['order_id', 'status']);
            $table->index(['payment_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
        Schema::dropIfExists('payment_webhook_events');
        Schema::dropIfExists('payments');
    }
};
