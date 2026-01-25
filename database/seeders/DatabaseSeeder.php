<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Create or update default admin user
        User::updateOrCreate(
            ['email' => 'abdul@example.com'],
            [
                'name' => 'abdul',
                'password' => bcrypt('Abdul@123'),
                'email_verified_at' => now(),
            ]
        );
    }
}
