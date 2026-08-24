<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 9 — the trust layer: verification workflow, private documents, reviews.
 *
 * Design notes worth keeping:
 *
 *  - `reviews.order_id` is unique. One completed order earns exactly one review, so
 *    review-stuffing is prevented by the schema rather than by a check that could be
 *    raced.
 *  - Documents store a path on a *private* disk and are never web-accessible; only
 *    their status is ever public.
 *  - The existing per-dimension verification timestamps from Phase 2 are untouched.
 *    This adds the overall admin-controlled state machine on top of them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table): void {
            $table->string('verification_status', 20)->default('unverified')->after('is_verified');
            $table->text('verification_notes')->nullable()->after('verification_status');
            $table->timestamp('verification_requested_at')->nullable()->after('verification_notes');
            $table->timestamp('verified_at')->nullable()->after('verification_requested_at');
            $table->foreignId('verified_by_user_id')->nullable()->after('verified_at')->constrained('users')->nullOnDelete();
            $table->timestamp('suspended_at')->nullable()->after('verified_by_user_id');
            $table->text('suspension_reason')->nullable()->after('suspended_at');
            // Optional, self-reported; shown as "established" on the passport.
            $table->date('established_on')->nullable()->after('years_in_business');

            $table->index('verification_status');
        });

        Schema::create('business_documents', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();

            // gst | pan | registration | address_proof | insurance | other
            $table->string('type', 40);
            $table->string('title')->nullable();
            // Path on a private disk. Never rendered into a public URL.
            $table->string('file_path');
            $table->string('file_mime', 100)->nullable();
            $table->unsignedInteger('file_size')->nullable();

            $table->string('status', 20)->default('pending');
            $table->text('review_notes')->nullable();
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();

            $table->timestamps();

            $table->index(['business_id', 'status']);
        });

        Schema::create('reviews', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            // One review per completed order — the anti-manipulation constraint.
            $table->foreignId('order_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('users')->cascadeOnDelete();

            $table->unsignedTinyInteger('rating');
            $table->text('comment')->nullable();
            $table->string('status', 20)->default('published');

            // The business may reply once; it can never edit or remove the review.
            $table->text('business_response')->nullable();
            $table->timestamp('business_responded_at')->nullable();

            $table->foreignId('moderated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('moderation_note')->nullable();
            $table->timestamp('moderated_at')->nullable();

            $table->timestamps();

            $table->index(['business_id', 'status', 'created_at']);
            $table->index(['customer_id', 'created_at']);
            $table->index('rating');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reviews');
        Schema::dropIfExists('business_documents');

        Schema::table('businesses', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('verified_by_user_id');
            $table->dropColumn([
                'verification_status', 'verification_notes', 'verification_requested_at',
                'verified_at', 'suspended_at', 'suspension_reason', 'established_on',
            ]);
        });
    }
};
