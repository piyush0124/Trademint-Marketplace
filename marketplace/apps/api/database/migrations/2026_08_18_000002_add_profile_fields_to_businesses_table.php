<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extends the existing `businesses` table with profile fields (Phase 2).
 * Purely additive/nullable-or-defaulted — no existing column is touched,
 * so every existing business row is still valid after this runs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table): void {
            $table->foreignId('category_id')->nullable()->after('location_id')
                ->constrained('categories')->nullOnDelete();

            $table->string('business_type', 40)->nullable()->after('name');
            $table->unsignedSmallInteger('years_in_business')->nullable()->after('business_type');
            $table->string('trade_type', 10)->nullable()->after('years_in_business'); // b2b | b2c | both
            $table->json('business_hours')->nullable()->after('website');
            $table->boolean('is_available')->default(true)->after('business_hours');

            // Verification — each dimension tracked independently, never a
            // single self-reported flag. Null means "not verified"; only an
            // admin action (see BusinessVerificationController) sets these.
            $table->timestamp('email_verified_at')->nullable()->after('is_verified');
            $table->timestamp('phone_verified_at')->nullable()->after('email_verified_at');
            $table->timestamp('business_verified_at')->nullable()->after('phone_verified_at');
            $table->timestamp('address_verified_at')->nullable()->after('business_verified_at');
            $table->string('gst_number', 20)->nullable()->after('address_verified_at');
            $table->timestamp('gst_verified_at')->nullable()->after('gst_number');
        });
    }

    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('category_id');
            $table->dropColumn([
                'business_type',
                'years_in_business',
                'trade_type',
                'business_hours',
                'is_available',
                'email_verified_at',
                'phone_verified_at',
                'business_verified_at',
                'address_verified_at',
                'gst_number',
                'gst_verified_at',
            ]);
        });
    }
};
