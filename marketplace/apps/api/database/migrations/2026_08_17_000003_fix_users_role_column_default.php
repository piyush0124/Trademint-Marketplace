<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The users.role column still defaults to the legacy 'user' string (from
 * before the Customer/Owner/Admin rename). That default is not just stale
 * — it's actively broken: 'user' is no longer a valid UserRole enum case,
 * so any row created without an explicit role (e.g. a raw insert, or a
 * factory call that forgets to set one) throws a ValueError the moment
 * `$user->role` is read.
 *
 * Data-safe: this only changes the column's default value for future
 * inserts. It does not touch any existing row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('role', 20)->default('customer')->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('role', 20)->default('user')->change();
        });
    }
};
