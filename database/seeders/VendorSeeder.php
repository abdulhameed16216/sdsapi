<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Vendor;

class VendorSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $vendors = [
            [
                'name' => 'ABC Construction Ltd',
                'code' => 'VENDOR001',
                'contact_person' => 'John Smith',
                'email' => 'john@abcconstruction.com',
                'phone' => '+1234567890',
                'address' => '123 Main Street',
                'city' => 'Mumbai',
                'state' => 'Maharashtra',
                'pincode' => '400001',
                'gst_number' => '27ABCDE1234F1Z5',
                'status' => 'active',
                'notes' => 'Primary construction vendor',
            ],
            [
                'name' => 'XYZ Engineering Works',
                'code' => 'VENDOR002',
                'contact_person' => 'Jane Doe',
                'email' => 'jane@xyzengineering.com',
                'phone' => '+1234567891',
                'address' => '456 Industrial Area',
                'city' => 'Delhi',
                'state' => 'Delhi',
                'pincode' => '110001',
                'gst_number' => '07FGHIJ5678K2L6',
                'status' => 'active',
                'notes' => 'Engineering and maintenance services',
            ],
            [
                'name' => 'DEF Machinery Co',
                'code' => 'VENDOR003',
                'contact_person' => 'Bob Wilson',
                'email' => 'bob@defmachinery.com',
                'phone' => '+1234567892',
                'address' => '789 Tech Park',
                'city' => 'Bangalore',
                'state' => 'Karnataka',
                'pincode' => '560001',
                'gst_number' => '29MNOPQ9012R3S7',
                'status' => 'active',
                'notes' => 'Heavy machinery supplier',
            ],
        ];

        foreach ($vendors as $vendor) {
            Vendor::create($vendor);
        }
    }
}
