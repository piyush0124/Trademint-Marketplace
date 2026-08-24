<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Data-only migration: renames the legacy role values to the final
 * business roles. No column structure changes here — `role` is already
 * a plain string column (see add_role_to_users_table).
 *
 *   user      -> customer   (default role; ordinary marketplace buyers)
 *   moderator -> admin      (Moderator is retired; every moderator becomes
 *                            a full Admin — the app only recognises three
 *                            roles going forward: customer, owner, admin)
 *
 * Existing 'owner' rows (none should exist pre-migration, since the role
 * didn't previously exist) and 'admin' rows are left untouched.
 *
 * This does not delete any user data or rows — it only rewrites the
 * `role` column value on existing users.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')->where('role', 'user')->update(['role' => 'customer']);
        DB::table('users')->where('role', 'moderator')->update(['role' => 'admin']);

        // The column's stale 'user' default is intentionally left as-is
        // (changing it needs doctrine/dbal). It's harmless: every code
        // path that creates a User (register, registerOwner, seeders)
        // sets `role` explicitly and never relies on the DB default.
    }

    public function down(): void
    {
        DB::table('users')->where('role', 'customer')->update(['role' => 'user']);
        // Moderator -> Admin is not reversible (we can't tell which admins
        // were originally moderators), so rollback leaves admin as-is.
    }
};
