<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    /**
     * Provision the admin account from environment values.
     *
     * Registration is disabled in this internal tool, so the first
     * administrator is created (or updated) here from ADMIN_EMAIL /
     * ADMIN_PASSWORD. Idempotent via updateOrCreate keyed on email.
     */
    public function run(): void
    {
        $email = config('backup.admin.email');
        $password = config('backup.admin.password');

        if (blank($email) || blank($password)) {
            $this->command?->warn(
                'AdminUserSeeder skipped: set ADMIN_EMAIL and ADMIN_PASSWORD in .env.'
            );

            return;
        }

        $user = User::updateOrCreate(
            ['email' => $email],
            [
                'name' => config('backup.admin.name', 'Admin'),
                'password' => Hash::make($password),
                'email_verified_at' => now(),
            ],
        );

        $this->command?->info("Admin user provisioned: {$user->email}");
    }
}
