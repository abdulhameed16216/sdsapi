<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Employee;
use App\Models\User;
use App\Models\Role;
use Illuminate\Support\Facades\Hash;

class EmployeeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Get the developer role
        $developerRole = Role::where('slug', 'developer')->first();
        
        if (!$developerRole) {
            $this->command->error('Developer role not found. Please run RoleSeeder first.');
            return;
        }

        // Create developer employee
        $employee = Employee::create([
            'name' => 'Developer',
            'mobile_number' => '1234567890',
            'email' => 'developer@example.com',
            'blood_group' => 'O+',
            'date_of_birth' => '1990-01-01',
            'date_of_joining' => now()->format('Y-m-d'),
            'address' => 'Developer Address',
            'city' => 'Developer City',
            'role_id' => $developerRole->id,
            'id_proof' => null,
            'username' => 'developer',
            'password' => Hash::make('password'),
            'status' => 'active',
        ]);

        // Create user account for authentication
        User::create([
            'username' => 'developer',
            'password' => Hash::make('password'),
            'employee_id' => $employee->id,
            'status' => 'active',
        ]);

        $this->command->info('Developer employee created successfully!');
        $this->command->info('Username: developer');
        $this->command->info('Password: password');
    }
}
