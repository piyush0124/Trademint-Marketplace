<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('requirements', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('customer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('category_id')->constrained()->restrictOnDelete();
            // Nullable and nullOnDelete — same reasoning as listings.location_id:
            // a draft may not have a location yet, and PAN-India locations are
            // shared rows, never a hardcoded/fixed set.
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->decimal('quantity', 15, 2)->nullable();
            $table->string('unit', 30)->nullable();
            $table->decimal('budget_min', 15, 2)->nullable();
            $table->decimal('budget_max', 15, 2)->nullable();
            $table->date('required_date')->nullable();
            $table->string('urgency', 20)->default('normal');
            $table->string('status', 20)->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'status']);
            $table->index(['category_id', 'status', 'created_at']);
            $table->index(['status', 'location_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('requirements');
    }
};
