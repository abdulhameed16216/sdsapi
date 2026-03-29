<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Permission;

class PermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $permissions = [
            // User Management: Add, Edit, View, Delete
            ['name' => 'Add User', 'slug' => 'user_add', 'module' => 'user_management', 'action' => 'add', 'description' => 'Add new users'],
            ['name' => 'Edit User', 'slug' => 'user_edit', 'module' => 'user_management', 'action' => 'edit', 'description' => 'Edit existing users'],
            ['name' => 'View User', 'slug' => 'user_view', 'module' => 'user_management', 'action' => 'view', 'description' => 'View user details'],
            ['name' => 'Delete User', 'slug' => 'user_delete', 'module' => 'user_management', 'action' => 'delete', 'description' => 'Delete users'],

            // Role: Add, View, Edit, Delete
            ['name' => 'Add Role', 'slug' => 'role_add', 'module' => 'role_management', 'action' => 'add', 'description' => 'Add new roles'],
            ['name' => 'View Role', 'slug' => 'role_view', 'module' => 'role_management', 'action' => 'view', 'description' => 'View role details'],
            ['name' => 'Edit Role', 'slug' => 'role_edit', 'module' => 'role_management', 'action' => 'edit', 'description' => 'Edit existing roles'],
            ['name' => 'Delete Role', 'slug' => 'role_delete', 'module' => 'role_management', 'action' => 'delete', 'description' => 'Delete roles'],

            // Vendors: Add, Edit, View, Delete
            ['name' => 'Add Vendor', 'slug' => 'vendor_add', 'module' => 'vendor_management', 'action' => 'add', 'description' => 'Add new vendors'],
            ['name' => 'Edit Vendor', 'slug' => 'vendor_edit', 'module' => 'vendor_management', 'action' => 'edit', 'description' => 'Edit existing vendors'],
            ['name' => 'View Vendor', 'slug' => 'vendor_view', 'module' => 'vendor_management', 'action' => 'view', 'description' => 'View vendor details'],
            ['name' => 'Delete Vendor', 'slug' => 'vendor_delete', 'module' => 'vendor_management', 'action' => 'delete', 'description' => 'Delete vendors'],

            // Machines: Add, Edit, View, Delete
            ['name' => 'Add Machine', 'slug' => 'machine_add', 'module' => 'machine_management', 'action' => 'add', 'description' => 'Add new machines'],
            ['name' => 'Edit Machine', 'slug' => 'machine_edit', 'module' => 'machine_management', 'action' => 'edit', 'description' => 'Edit existing machines'],
            ['name' => 'View Machine', 'slug' => 'machine_view', 'module' => 'machine_management', 'action' => 'view', 'description' => 'View machine details'],
            ['name' => 'Delete Machine', 'slug' => 'machine_delete', 'module' => 'machine_management', 'action' => 'delete', 'description' => 'Delete machines'],

            // Products: Add, Edit, View, Delete
            ['name' => 'Add Product', 'slug' => 'product_add', 'module' => 'product_management', 'action' => 'add', 'description' => 'Add new products'],
            ['name' => 'Edit Product', 'slug' => 'product_edit', 'module' => 'product_management', 'action' => 'edit', 'description' => 'Edit existing products'],
            ['name' => 'View Product', 'slug' => 'product_view', 'module' => 'product_management', 'action' => 'view', 'description' => 'View product details'],
            ['name' => 'Delete Product', 'slug' => 'product_delete', 'module' => 'product_management', 'action' => 'delete', 'description' => 'Delete products'],

            // Attendance: Report, Regularization
            ['name' => 'Attendance Report', 'slug' => 'attendance_report', 'module' => 'attendance_management', 'action' => 'report', 'description' => 'Generate attendance reports'],
            ['name' => 'Attendance Regularization', 'slug' => 'attendance_regularization', 'module' => 'attendance_management', 'action' => 'regularization', 'description' => 'Regularize attendance records'],

            // Stocks: In, Out, Transfer
            ['name' => 'Stock In', 'slug' => 'stock_in', 'module' => 'stock_management', 'action' => 'in', 'description' => 'Record stock incoming'],
            ['name' => 'Stock Out', 'slug' => 'stock_out', 'module' => 'stock_management', 'action' => 'out', 'description' => 'Record stock outgoing'],
            ['name' => 'Stock Transfer', 'slug' => 'stock_transfer', 'module' => 'stock_management', 'action' => 'transfer', 'description' => 'Transfer stock between locations'],

            // Machine Reading: Add, Edit, View, Delete
            ['name' => 'Add Machine Reading', 'slug' => 'machine_reading_add', 'module' => 'machine_reading', 'category' => 'machineReading', 'action' => 'add', 'description' => 'Add new machine reading records'],
            ['name' => 'Edit Machine Reading', 'slug' => 'machine_reading_edit', 'module' => 'machine_reading', 'category' => 'machineReading', 'action' => 'edit', 'description' => 'Edit existing machine reading data'],
            ['name' => 'View Machine Reading', 'slug' => 'machine_reading_view', 'module' => 'machine_reading', 'category' => 'machineReading', 'action' => 'view', 'description' => 'View machine reading details and reports'],
            ['name' => 'Delete Machine Reading', 'slug' => 'machine_reading_delete', 'module' => 'machine_reading', 'category' => 'machineReading', 'action' => 'delete', 'description' => 'Delete machine reading records'],

            // File Upload: upload, view, delete
            ['name' => 'File Upload', 'slug' => 'file_upload', 'module' => 'file_management', 'action' => 'upload', 'description' => 'Upload files'],
            ['name' => 'File View', 'slug' => 'file_view', 'module' => 'file_management', 'action' => 'view', 'description' => 'View uploaded files'],
            ['name' => 'File Delete', 'slug' => 'file_delete', 'module' => 'file_management', 'action' => 'delete', 'description' => 'Delete uploaded files'],
        ];

        foreach ($permissions as $permission) {
            Permission::create($permission);
        }
    }
}
