<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('listings', function (Blueprint $table): void {
            $table->string('availability_status', 20)->default('available')->after('status');
            $table->text('rejection_reason')->nullable()->after('published_at');
            $table->timestamp('submitted_at')->nullable()->after('rejection_reason');
            $table->timestamp('rejected_at')->nullable()->after('submitted_at');
            $table->timestamp('archived_at')->nullable()->after('rejected_at');

            $table->index(['status', 'submitted_at']);
            $table->index(['availability_status']);
        });
    }

    public function down(): void
    {
        Schema::table('listings', function (Blueprint $table): void {
            $table->dropIndex(['status', 'submitted_at']);
            $table->dropIndex(['availability_status']);
            $table->dropColumn([
                'availability_status',
                'rejection_reason',
                'submitted_at',
                'rejected_at',
                'archived_at',
            ]);
        });
    }
};
