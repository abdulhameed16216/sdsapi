<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Role;

class AdditionalRolesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $roles = [
            [
                'name' => 'Super Admin',
                'slug' => 'super-admin',
                'display_name' => 'Super Admin',
                'description' => 'Full system access with all permissions',
                'is_active' => true,
            ],
            [
                'name' => 'Stock Manager',
                'slug' => 'stock-manager',
                'display_name' => 'Stock Manager',
                'description' => 'Manage stock, inventory, and product operations',
                'is_active' => true,
            ],
            [
                'name' => 'Operator',
                'slug' => 'operator',
                'display_name' => 'Operator',
                'description' => 'Basic operational tasks and machine operations',
                'is_active' => true,
            ],
            [
                'name' => 'Supervisor',
                'slug' => 'supervisor',
                'display_name' => 'Supervisor',
                'description' => 'Supervise operations and manage team activities',
                'is_active' => true,
            ]
        ];

        foreach ($roles as $role) {
            Role::updateOrCreate(
                ['slug' => $role['slug']], // Find by slug
                $role // Update or create with this data
            );
        }
    }
}
