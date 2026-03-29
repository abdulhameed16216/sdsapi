<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Product;

class ProductSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $products = [
            [
                'name' => 'Diesel Fuel',
                'code' => 'PROD001',
                'description' => 'High-grade diesel fuel for generators and machinery',
                'unit' => 'liters',
                'price' => 85.50,
                'status' => 'active',
            ],
            [
                'name' => 'Engine Oil',
                'code' => 'PROD002',
                'description' => 'Premium engine oil for heavy machinery',
                'unit' => 'liters',
                'price' => 450.00,
                'status' => 'active',
            ],
            [
                'name' => 'Hydraulic Fluid',
                'code' => 'PROD003',
                'description' => 'Hydraulic fluid for construction equipment',
                'unit' => 'liters',
                'price' => 320.00,
                'status' => 'active',
            ],
            [
                'name' => 'Air Filter',
                'code' => 'PROD004',
                'description' => 'Air filter for generators and compressors',
                'unit' => 'pieces',
                'price' => 1250.00,
                'status' => 'active',
            ],
            [
                'name' => 'Oil Filter',
                'code' => 'PROD005',
                'description' => 'Oil filter for engines',
                'unit' => 'pieces',
                'price' => 850.00,
                'status' => 'active',
            ],
            [
                'name' => 'Coolant',
                'code' => 'PROD006',
                'description' => 'Engine coolant for temperature regulation',
                'unit' => 'liters',
                'price' => 180.00,
                'status' => 'active',
            ],
        ];

        foreach ($products as $product) {
            Product::create($product);
        }
    }
}
