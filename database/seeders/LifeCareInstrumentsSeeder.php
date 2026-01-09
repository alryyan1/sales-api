<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Product;
use App\Models\Category;
use App\Models\Unit;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class LifeCareInstrumentsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        // 1. Setup Dependencies
        $unit = Unit::firstOrCreate(
            ['name' => 'pcs'],
            ['type' => 'sellable', 'is_active' => 1]
        );

        $supplier = Supplier::firstOrCreate(['name' => 'Life Care Instruments']);
        $warehouse = Warehouse::firstOrCreate(['name' => 'Main Warehouse']);
        $user = User::first();

        DB::transaction(function () use ($unit, $supplier, $warehouse, $user) {

            // 2. Create Categories and Products
            $categoriesData = [
                [
                    'name' => 'أجهزة والأثاث الطبي',
                    'products' => [
                        'Operation Table (Electrical & Manual)',
                        'Traction Table',
                        'Delivery Table (Hydraulic / Steel / Metal)',
                        'Hospital Bed (1 & 2 Movement)',
                        'Baby Bed – 3موديلات',
                        'Bedside Cabinet',
                        'Examination Couch (Metal / Steel)',
                        'Overbed "Dinner" Table',
                        'Folding Stretcher',
                        'Patient Trolley -Steel',
                        'Instrument Trolley',
                    ],
                ],
                [
                    'name' => 'أجهزة العناية والعمليات',
                    'products' => [
                        'Operating Light (4-unit with Battery / 4-unit / 9-unit)',
                        'Examination Lamps Standard and LED',
                        'Patient Monitor',
                        'Infusion Pump',
                        'Syringe Pump',
                        'Fetal Doppler',
                        'Viewer Box (Single)',
                        'Pen Torch',
                        'Pulse Oximeter',
                        'Digital Thermometer',
                        'IV Stand (Steel)',
                        'Sphygmomanometer (ALPK2) Mercury, Digital and Aneroid',
                        'Stethoscope (Littmann)',
                        'Weight & Height Scale',
                        'Wooden Board Scale',
                    ],
                ],
                [
                    'name' => 'أجهزة التعقيم والحفظ',
                    'products' => [
                        'Pressure Autoclave (18L, 50L, 75L, 100L)',
                        'Hot Air Oven (30L, 60L, 100L)',
                        'Dry Oven (30L, 70L)',
                        'Biosafety Cabinet',
                        'Solar Vaccine Refrigerator',
                    ],
                ],
                [
                    'name' => 'بنك الدم والغسيل',
                    'products' => [
                        'Blood Bank Refrigerator',
                        'Tube Sealer',
                        'Plasma Extractor',
                        'Platelet Shaker',
                        'Manual / Electric Collection Chair',
                        'Dialysis Chair',
                        'Stool Lab',
                    ],
                ],
                [
                    'name' => 'الوقاية والمستهلكات الطبية',
                    'products' => [
                        'Sterile Gown (Standard / Reinforced)',
                        'Angiography Set',
                        'Body Death Bag',
                        'Mosquito Net (Double)',
                        'Thermometer & Hygrometer',
                        'Tongue Depressor',
                        'Cord Clamps',
                    ],
                ],
                [
                    'name' => 'أجهزة المعامل',
                    'products' => [
                        'Microscope 107 LED',
                        'Microscope Olympus CX23 -Origenal',
                        'Incubator (40L / 80L – Anhui)',
                        'Water Bath',
                        'Roller Mixer',
                        'Orbital Shaker',
                        'Vortex Mixer',
                        'Centrifuge 80-1',
                        'Digital Centrifuge (8 Tubes)',
                        'Analytical Balance (0.01 g / 0.1 g)',
                        'Automatic Pipettes',
                    ],
                ],
                [
                    'name' => 'الزجاجيات والمستهلكات المعملية',
                    'products' => [
                        'Plain Slides & Cover Glass',
                        'Petri Dish (Glass / Disposable)',
                        'Pasteur Pipettes (Sterile / Non-sterile)',
                        'Glass Mouth Pipettes',
                        'ESR Rack',
                        'Counting Chamber',
                        'Stopwatch',
                        'Lancet Pen (100/Box)',
                        'Lancet Steel (100–200/Box)',
                        'Tourniquet',
                        'Wooden Stick',
                        'Doctor Aid Plaster',
                        'Culture Swabs',
                        'Yellow Tips',
                        'Blue Tips',
                    ],
                ],
            ];

            foreach ($categoriesData as $categoryData) {
                // Create category
                $category = Category::firstOrCreate(['name' => $categoryData['name']]);

                // Create products for this category
                foreach ($categoryData['products'] as $productName) {
                    Product::firstOrCreate(
                        ['name' => $productName],
                        [
                            'category_id' => $category->id,
                            'stocking_unit_id' => $unit->id,
                            'sellable_unit_id' => $unit->id,
                            'units_per_stocking_unit' => 1,
                            'stock_quantity' => 0,
                            'stock_alert_level' => 5,
                            'has_expiry_date' => false,
                        ]
                    );
                }
            }
        });
    }
}
