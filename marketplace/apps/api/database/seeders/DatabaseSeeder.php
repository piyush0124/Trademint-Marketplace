<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seeding is idempotent: running `migrate --seed` against a database that has
     * already been seeded must be a no-op, not a unique-constraint crash. Reference
     * data seeders upsert; the demo accounts are skipped if their email is taken.
     */
    public function run(): void
    {
        $this->call(CategorySeeder::class);
        $this->call(LocationSeeder::class);
        $this->call(IndustrySeeder::class);

        $this->demoUsers();
    }

    /**
     * The three demo logins, one per role. Passwords are deliberately weak and the
     * emails use the reserved `.test` TLD — these exist for local development and
     * must never be seeded into a production database.
     */
    private function demoUsers(): void
    {
        $accounts = [
            ['name' => 'Demo Admin', 'email' => 'admin@marketplace.test', 'role' => UserRole::Admin],
            ['name' => 'Demo Owner', 'email' => 'owner@marketplace.test', 'role' => UserRole::Owner],
            ['name' => 'Demo Customer', 'email' => 'customer@marketplace.test', 'role' => UserRole::Customer],
        ];

        foreach ($accounts as $account) {
            // Existence check rather than updateOrCreate: re-seeding must not reset
            // the password on an account someone is already using locally.
            if (User::query()->where('email', $account['email'])->exists()) {
                $this->command?->line(sprintf('  <fg=yellow>-</> %s already exists, skipped', $account['email']));

                continue;
            }

            // Factories run unguarded, which is how `role` — deliberately absent
            // from User::$fillable — can be set here but never through the API.
            User::factory()->create([...$account, 'password' => 'password']);
        }
    }
}
