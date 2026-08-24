<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5 — the enquiry: one conversation between one customer and one
 * business about one requirement.
 *
 * Sits between a match ("this business looks suitable") and a quote ("here is
 * my price"). Deliberately separate from the existing `leads` table, which
 * models an unrelated flow: a public, possibly anonymous enquiry about a
 * single listing. Folding the two together would have forced listing_id to
 * become nullable and mixed two different status vocabularies in one place.
 *
 * Entirely industry-neutral: no column here knows or cares whether the
 * requirement is for goods, a service, or a project.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enquiries', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('requirement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            // The match that justified this pairing. Nullable so an enquiry can
            // still exist if matching data is ever pruned, and set null rather
            // than cascading — losing the match must not destroy the
            // commercial conversation that followed it.
            $table->foreignId('match_id')->nullable()->constrained('requirement_matches')->nullOnDelete();

            $table->string('status', 20)->default('pending');
            // Either side may open the conversation: a customer reaching out to
            // a matched business, or a business responding to its invitation.
            $table->string('initiated_by', 20)->default('customer');

            $table->text('customer_message')->nullable();
            $table->text('business_message')->nullable();

            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('declined_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            $table->timestamps();

            // One conversation per customer/business pair per requirement.
            $table->unique(['requirement_id', 'business_id']);
            $table->index(['customer_id', 'status', 'created_at']);
            $table->index(['business_id', 'status', 'created_at']);
            $table->index('match_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enquiries');
    }
};
