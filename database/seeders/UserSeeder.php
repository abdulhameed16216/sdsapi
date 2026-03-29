<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Role;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Get roles
        $superAdminRole = Role::where('slug', 'super_admin')->first();
        $adminRole = Role::where('slug', 'admin')->first();
        $supervisorRole = Role::where('slug', 'supervisor')->first();
        $operatorRole = Role::where('slug', 'operator')->first();

        // Create Super Admin
        User::create([
            'name' => 'Super Admin',
            'email' => 'superadmin@ebdashboard.com',
            'password' => Hash::make('password'),
            'phone' => '+1234567890',
            'role_id' => $superAdminRole->id,
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        // Create Admin
        User::create([
            'name' => 'Admin User',
            'email' => 'admin@ebdashboard.com',
            'password' => Hash::make('password'),
            'phone' => '+1234567891',
            'role_id' => $adminRole->id,
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        // Create Supervisor
        User::create([
            'name' => 'John Supervisor',
            'email' => 'supervisor@ebdashboard.com',
            'password' => Hash::make('password'),
            'phone' => '+1234567892',
            'role_id' => $supervisorRole->id,
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        // Create Operators
        User::create([
            'name' => 'Mike Operator',
            'email' => 'operator1@ebdashboard.com',
            'password' => Hash::make('password'),
            'phone' => '+1234567893',
            'role_id' => $operatorRole->id,
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        User::create([
            'name' => 'Sarah Operator',
            'email' => 'operator2@ebdashboard.com',
            'password' => Hash::make('password'),
            'phone' => '+1234567894',
            'role_id' => $operatorRole->id,
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
    }
}
