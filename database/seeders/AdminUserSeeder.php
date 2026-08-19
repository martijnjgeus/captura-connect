<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $name = config('admin.name', 'Admin');
        $email = config('admin.email');
        $password = config('admin.password');

        if (! is_string($email) || $email === '') {
            $this->command?->error('Config [admin.email] is not configured.');

            return;
        }

        if (! is_string($password) || $password === '') {
            $this->command?->error('Config [admin.password] is not configured.');

            return;
        }

        $existingUser = User::query()
            ->where('email', $email)
            ->first();

        if ($existingUser) {
            $existingUser->forceFill([
                'name' => $name,
                'password' => Hash::make($password),
            ])->save();

            $this->command?->info("Admin user [{$email}] updated.");

            return;
        }

        User::factory()->create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
        ]);

        $this->command?->info("Admin user [{$email}] created.");
    }
}
