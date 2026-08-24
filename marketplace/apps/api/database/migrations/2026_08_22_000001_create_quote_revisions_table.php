<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6 — the immutable commercial ledger of a quote.
 *
 * Revision 1 is written when a quote is sent (the opening offer). Every counter
 * offer, from either side, appends a new revision. Nothing here is ever updated
 * after insert except `superseded_at` and `is_current`, which are bookkeeping
 * rather than commercial content — so the terms each party actually agreed to
 * can always be reconstructed, and an accepted order can point at the exact
 * revision it was formed from (Phase 7).
 *
 * The money columns duplicate `quotes` on purpose: `quotes` reflects the
 * current offer for convenience, this table is the record of every offer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quote_revisions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('quote_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('revision_number');

            // Who put this offer on the table. Drives whose turn it is next.
            $table->string('authored_by', 20);                 // customer | business
            $table->foreignId('authored_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            // initial | counter — a counter is a response, an initial is the opening offer.
            $table->string('revision_type', 20)->default('counter');
            // A party may mark an offer as final; purely informational, it does
            // not restrict the other side's right to counter.
            $table->boolean('is_final_offer')->default(false);

            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->decimal('delivery_fee', 15, 2)->default(0);
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->decimal('other_charges', 15, 2)->default(0);
            $table->decimal('total_amount', 15, 2)->default(0);
            $table->char('currency', 3);

            $table->date('validity_date')->nullable();
            $table->date('estimated_delivery_date')->nullable();
            $table->date('estimated_completion_date')->nullable();
            $table->timestamp('expires_at')->nullable();

            $table->text('payment_terms')->nullable();
            $table->text('warranty_terms')->nullable();
            $table->text('cancellation_terms')->nullable();
            $table->text('notes')->nullable();
            // The message that accompanied this offer ("can you do 780/unit?").
            $table->text('message')->nullable();

            $table->boolean('is_current')->default(true);
            $table->timestamp('superseded_at')->nullable();

            $table->timestamps();

            // Revision numbers are per quote and gap-free; the unique index is
            // what makes two simultaneous counters impossible to both commit.
            $table->unique(['quote_id', 'revision_number']);
            $table->index(['quote_id', 'is_current']);
        });

        Schema::create('quote_revision_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('quote_revision_id')->constrained()->cascadeOnDelete();

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

            $table->index(['quote_revision_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quote_revision_items');
        Schema::dropIfExists('quote_revisions');
    }
};
