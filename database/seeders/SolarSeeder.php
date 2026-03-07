<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use App\Models\Unit;
use App\Models\Warehouse;
use Illuminate\Database\Seeder;

class SolarSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 1. Create/Get the unit
        $unit = Unit::firstOrCreate(
            ['name' => 'وحدة'],
            [
                'type' => 'sellable',
                'is_active' => true,
                'is_default' => true
            ]
        );

        // Get or create a stocking unit as well if needed
        $stockingUnit = Unit::firstOrCreate(
            ['name' => 'كرتون'],
            [
                'type' => 'stocking',
                'is_active' => true
            ]
        );

        // 2. Define categories and their products
        $data = [
            'محولات سكنية' => [
                'SOLARTHON 6.2 K',
                'SOLARTHON 4.2 K',
                'SOLARTHON 3 K',
                'SUN -12K DEYE HYBRID INVERTER',
                'COVAX HNVERTER 3.2',
                'COVAX HNVERTER 4.2',
                'COVAX HNVERTER 6.2',
                'VACKSON HNVERTER 4.2',
                'SUNX HNVERTER 6.2',
                'M.S HNVERTER 6.2',
            ],
            'منظمات' => [
                '2000 SMART INVERTER',
                'DC 12/24 SM ART INVERTER',
            ],
            'بطاريات ليثيوم' => [
                'MARS SOLAR LITHIUM BATTERY 15 K',
                'LITHIUM IRON PHOSPHATE BATTERY 200 A',
                'LITHIUM IRON PHOSPHATE BATTERY 100 A',
                'ENERGY STORAGE SYSTEM BATTERY 5 K',
            ],
            'محولات زراعية' => [
                'محول زراعي HYBRID SOLAR INVERTER',
                'محول زراعي MICNO 3PH 5.5 K',
                'محول زراعي MICNO 3PH 7.5 K',
                'محول زراعي MICNO 3PH 11 K',
                'محول زراعي MICNO 3PH 15 K',
                'محول زراعي MICNO 3PH 18.5 K',
                'محول زراعي MICNO 3PH 22 K',
                'محول زراعي MICNO 3PH 30 K',
            ],
            'طبلون زراعي' => [
                'طبلون زراعي صغير',
                'طبلون زراعي كبير',
            ],
        ];

        // 3. Get first warehouse if available
        $warehouse = Warehouse::first();

        // 4. Seed categories and products
        foreach ($data as $categoryName => $products) {
            $category = Category::firstOrCreate(['name' => $categoryName]);

            foreach ($products as $productName) {
                $product = Product::firstOrCreate(
                    ['name' => $productName],
                    [
                        'category_id' => $category->id,
                        'sellable_unit_id' => $unit->id,
                        'stocking_unit_id' => $stockingUnit->id,
                        'units_per_stocking_unit' => 1,
                        'description' => $categoryName . ' - ' . $productName,
                    ]
                );

                // Attach to warehouse if it exists and not already attached
                if ($warehouse && !$product->warehouses()->where('warehouses.id', $warehouse->id)->exists()) {
                    $product->warehouses()->attach($warehouse->id, [
                        'quantity' => 0,
                        'min_stock_level' => 5
                    ]);
                }
            }
        }
    }
}
