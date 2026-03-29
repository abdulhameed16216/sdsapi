<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Role;
use App\Models\Permission;

class RolePermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Super Admin - All permissions
        $superAdmin = Role::where('slug', 'super_admin')->first();
        if ($superAdmin) {
            $superAdmin->permissions()->sync(Permission::pluck('id'));
        }

        // Admin - Most permissions except role management
        $admin = Role::where('slug', 'admin')->first();
        if ($admin) {
            $adminPermissions = Permission::whereNotIn('module', ['role_management'])->pluck('id');
            $admin->permissions()->sync($adminPermissions);
        }

        // Supervisor - Monitoring and reporting permissions
        $supervisor = Role::where('slug', 'supervisor')->first();
        if ($supervisor) {
            $supervisorPermissions = Permission::whereIn('module', [
                'user_management',
                'vendor_management',
                'stock_management',
                'product_management',
                'attendance_management',
                'machine_management',
                'document_management',
                'reports',
                'dashboard'
            ])->whereIn('action', ['read', 'export'])->pluck('id');
            
            // Add some update permissions for supervisor
            $supervisorUpdatePermissions = Permission::whereIn('slug', [
                'attendance_update',
                'machine_reading',
                'stock_update'
            ])->pluck('id');
            
            $supervisor->permissions()->sync($supervisorPermissions->merge($supervisorUpdatePermissions));
        }

        // Operator - Basic operational permissions
        $operator = Role::where('slug', 'operator')->first();
        if ($operator) {
            $operatorPermissions = Permission::whereIn('slug', [
                'attendance_create',
                'attendance_read',
                'stock_read',
                'stock_update',
                'stock_transfer',
                'machine_read',
                'machine_reading',
                'vendor_read',
                'product_read',
                'document_read',
                'dashboard_read'
            ])->pluck('id');
            
            $operator->permissions()->sync($operatorPermissions);
        }
    }
}
