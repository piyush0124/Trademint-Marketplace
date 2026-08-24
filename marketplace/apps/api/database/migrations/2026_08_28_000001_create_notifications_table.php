<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * In-app notifications.
 *
 * ---------------------------------------------------------------------------
 * WHY THIS TABLE EXISTS AT ALL
 * ---------------------------------------------------------------------------
 * Nineteen domain events already fire across the marketplace — a quote arriving,
 * an order shipping, a payment clearing — and until now exactly one of them had a
 * listener. Everything else was dispatched into the void. AppServiceProvider even
 * says so: the events "exist as hooks for a future notification layer".
 *
 * The practical effect was that a buyer posted a requirement, closed the tab, and
 * nothing ever told them three suppliers had quoted. Every trust feature in the
 * product — verification, the revision ledger, earned reviews — is invisible to a
 * user who is not currently looking at the page. This is that layer.
 *
 * ---------------------------------------------------------------------------
 * SCHEMA CHOICES
 * ---------------------------------------------------------------------------
 * Laravel's standard shape (uuid primary key, morph target, json data, nullable
 * read_at) rather than a bespoke one, so `Notifiable::notify()`, `unreadNotifications`
 * and `markAsRead()` all work without adapters. A custom table would mean
 * reimplementing that and losing the mail/broadcast channels later.
 *
 * `read_at` is nullable rather than a boolean `is_read`: it answers "unread?" and
 * "when did they see it?" with one column, and support conversations are far more
 * often about *when* someone was told than whether.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            // String, not increments: Laravel's DatabaseChannel generates a UUID.
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->json('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            /*
             * The index that matters.
             *
             * Every read is "this user's notifications, unread first, newest first",
             * and the bell polls the unread count on an interval. `morphs()` already
             * indexes (notifiable_type, notifiable_id); this adds read_at and
             * created_at so both the badge count and the list are covered by one
             * index instead of scanning a user's whole history.
             */
            $table->index(['notifiable_type', 'notifiable_id', 'read_at', 'created_at'], 'notifications_inbox_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
