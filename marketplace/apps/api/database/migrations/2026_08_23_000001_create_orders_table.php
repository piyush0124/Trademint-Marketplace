<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7 — orders, formed from an accepted quote revision.
 *
 * Two structural guarantees:
 *
 *  1. `quote_id` is unique. One accepted quote produces exactly one order, so a
 *     duplicated request or a re-fired event cannot create a second one — the
 *     database refuses it rather than relying on application checks alone.
 *  2. `quote_revision_id` records the exact immutable offer the order was formed
 *     from, so the agreed terms can always be produced, even after further
 *     revisions exist on the quote.
 *
 * Money is copied from that revision by the backend, never from a request.
 * Nothing here is industry-specific: the same row describes 500 chairs, a website
 * build or a year of facility management.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('order_number', 30)->unique();

            $table->foreignId('quote_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('quote_revision_id')->nullable()->constrained('quote_revisions')->nullOnDelete();
            $table->foreignId('requirement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();

            $table->string('status', 20)->default('pending');
            $table->string('payment_status', 25)->default('unpaid');
            $table->string('fulfilment_status', 20)->default('not_started');

            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->decimal('delivery_fee', 15, 2)->default(0);
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->decimal('other_charges', 15, 2)->default(0);
            $table->decimal('total_amount', 15, 2)->default(0);
            // Amount actually settled, maintained by the payment layer (Phase 8).
            $table->decimal('amount_paid', 15, 2)->default(0);
            $table->decimal('amount_refunded', 15, 2)->default(0);
            $table->char('currency', 3);

            $table->date('expected_delivery_date')->nullable();
            $table->date('expected_completion_date')->nullable();

            $table->text('customer_notes')->nullable();
            $table->text('business_notes')->nullable();
            $table->text('cancellation_reason')->nullable();

            $table->timestamp('placed_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            $table->timestamps();

            $table->index(['customer_id', 'status', 'created_at']);
            $table->index(['business_id', 'status', 'created_at']);
            $table->index(['status', 'payment_status']);
            $table->index('requirement_id');
        });

        Schema::create('order_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();

            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('description');
            $table->string('item_type', 20)->default('custom');
            $table->string('reference_type', 40)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();

            $table->decimal('quantity', 15, 3)->default(1);
            $table->string('unit', 30)->nullable();
            $table->decimal('unit_price', 15, 2)->default(0);
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('total', 15, 2)->default(0);

            $table->timestamps();

            $table->index(['order_id', 'sort_order']);
        });

        // Append-only audit of the order's lifecycle. Never updated, never
        // deleted — it is the answer to "who changed this, when, and why".
        Schema::create('order_status_histories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();

            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->foreignId('changed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            // customer | business | admin | system
            $table->string('changed_by_party', 20)->default('system');
            $table->text('note')->nullable();

            $table->timestamps();

            $table->index(['order_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_status_histories');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
    }
};
