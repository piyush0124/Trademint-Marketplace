<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5 — a commercial offer from a business to satisfy a requirement.
 *
 * Money columns are decimal(15,2), never float, and every one of them is
 * written by the backend from the line items (see QuoteService/Money) — a
 * total supplied by a client is always discarded.
 *
 * Currency is stored per quote rather than assumed globally, so supporting a
 * second currency later needs no schema change. The default comes from
 * config('marketplace.default_currency'), not a hardcoded literal.
 *
 * `customer_id` and `business_id` are denormalised from the enquiry on
 * purpose: every access check and dashboard query filters on them, and they
 * must stay fixed for the life of the quote even if anything upstream is
 * later re-pointed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quotes', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            // Human-readable reference, e.g. QT-2026-000001. Unique so a
            // concurrent double-generation fails loudly instead of colliding.
            $table->string('quote_number', 30)->unique();

            $table->foreignId('enquiry_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requirement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();

            $table->string('title');
            $table->text('description')->nullable();

            // Net of any per-line discount; see QuoteService for the exact
            // arithmetic each of these represents.
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->decimal('delivery_fee', 15, 2)->default(0);
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->decimal('other_charges', 15, 2)->default(0);
            $table->decimal('total_amount', 15, 2)->default(0);
            $table->char('currency', 3);

            $table->date('validity_date')->nullable();
            // Not every marketplace transaction is a physical delivery, so a
            // quote may carry a delivery date, a completion date, both, or
            // neither — a service or project quote typically has no delivery.
            $table->date('estimated_delivery_date')->nullable();
            $table->date('estimated_completion_date')->nullable();

            $table->text('payment_terms')->nullable();
            $table->text('warranty_terms')->nullable();
            $table->text('cancellation_terms')->nullable();
            $table->text('notes')->nullable();

            $table->string('status', 20)->default('draft');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('viewed_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('withdrawn_at')->nullable();
            $table->text('rejection_reason')->nullable();

            $table->timestamps();

            $table->index(['customer_id', 'status', 'created_at']);
            $table->index(['business_id', 'status', 'created_at']);
            $table->index(['enquiry_id', 'status']);
            $table->index('requirement_id');
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quotes');
    }
};
