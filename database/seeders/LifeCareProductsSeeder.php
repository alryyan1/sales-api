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

class LifeCareProductsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        // 1. Setup Dependencies
        $category = Category::firstOrCreate(['name' => 'Lab Supplies']);
        // Default to a suitable unit. ID 22 is 'pcs', ID 1 is 'box'. We'll use pcs or create it.
        $unit = Unit::firstOrCreate(
            ['name' => 'pcs'],
            ['type' => 'sellable', 'is_active' => 1]
        );
        
        $supplier = Supplier::firstOrCreate(['name' => 'Life Care Products']);
        $warehouse = Warehouse::firstOrCreate(['name' => 'Main Warehouse']); // Ensure at least one warehouse exists
        $user = User::first(); // Assign to the first user

        // 2. Data from Images
        $items = [
            // Image 3 (1-20)
            ['name' => 'MICROSCOPE N 107', 'qty' => 1, 'price' => 770000],
            ['name' => 'COLORMETER DIGITAL', 'qty' => 1, 'price' => 370000],
            ['name' => 'CENTERFUGE 80-1', 'qty' => 1, 'price' => 180000],
            ['name' => 'EDTA VACCTAINER', 'qty' => 1, 'price' => 13000],
            ['name' => 'HEPARIN VACCTAINER', 'qty' => 1, 'price' => 13500],
            ['name' => 'PLAIN VACCTAINER', 'qty' => 1, 'price' => 12500],
            ['name' => 'SLIDE', 'qty' => 1, 'price' => 3000],
            ['name' => 'COVER GLASS', 'qty' => 1, 'price' => 10000],
            ['name' => 'ESR DETECTOR', 'qty' => 1, 'price' => 70000],
            ['name' => 'ESR TUBE', 'qty' => 1, 'price' => 16000],
            ['name' => 'GIEMSA STAIN 1 L', 'qty' => 1, 'price' => 95000],
            ['name' => 'AUTOMATIC PIPATTE 10-100', 'qty' => 1, 'price' => 55000],
            ['name' => 'AUTOMATIC PIPATTE 100-1000', 'qty' => 1, 'price' => 55000],
            ['name' => 'SPIRIT 500ML', 'qty' => 1, 'price' => 6000],
            ['name' => 'HCL 500 ML', 'qty' => 1, 'price' => 8000],
            ['name' => 'G A A 500 ML', 'qty' => 1, 'price' => 6000],
            ['name' => 'PENDICT REAGENT 500 ML', 'qty' => 1, 'price' => 10000],
            ['name' => 'YELLOW TIPS', 'qty' => 1, 'price' => 7500],
            ['name' => 'BLUE TIPS', 'qty' => 1, 'price' => 7500],
            ['name' => 'PIN LANCET', 'qty' => 1, 'price' => 2500],

            // Image 2 (21-37)
            ['name' => 'COTTON 100GM', 'qty' => 1, 'price' => 2000],
            ['name' => 'IMMERSION OIL', 'qty' => 1, 'price' => 7000],
            ['name' => 'TORINQUTE', 'qty' => 1, 'price' => 5000],
            ['name' => 'STOP WATCH', 'qty' => 1, 'price' => 15000],
            ['name' => 'URINE STRIPS CO4', 'qty' => 1, 'price' => 19000],
            ['name' => 'SYRINGE 5ML', 'qty' => 1, 'price' => 13000],
            ['name' => 'GLOVES', 'qty' => 1, 'price' => 10000],
            ['name' => 'DRYING RACK', 'qty' => 1, 'price' => 15000],
            ['name' => 'STAINING RACK', 'qty' => 1, 'price' => 15000],
            ['name' => 'PLASTIC RACK', 'qty' => 2, 'price' => 14000], // 14k each
            ['name' => 'CHAMBER', 'qty' => 1, 'price' => 20000],
            ['name' => 'DR AID', 'qty' => 3, 'price' => 3000], // 3k each
            ['name' => 'STOOL CONTAINERS', 'qty' => 100, 'price' => 190], // 190 each
            ['name' => 'URINE CONTAINER', 'qty' => 100, 'price' => 190], // 190 each
            ['name' => 'PLASTIC TEST TUBE', 'qty' => 50, 'price' => 100], // 100 each
            ['name' => 'GLASS TEST TUBE', 'qty' => 100, 'price' => 150], // 150 each
            ['name' => 'URINE CENTERFUGE TUBE', 'qty' => 100, 'price' => 100], // 100 each

            // Image 1 (38-42)
            ['name' => 'GLUCOSE REAGENT', 'qty' => 1, 'price' => 20000],
            ['name' => 'URIC ACID', 'qty' => 1, 'price' => 25000],
            ['name' => 'RHMATOUD FACTOR', 'qty' => 1, 'price' => 30000],
            ['name' => 'C REACTIVE PROTEIN', 'qty' => 1, 'price' => 30000],
            ['name' => 'PEN LANCET 200 PCS', 'qty' => 1, 'price' => 5000],
        ];

        DB::transaction(function () use ($items, $category, $unit, $supplier, $warehouse, $user) {
            
            // Calculate Grand Total for Purchase
            $grandTotal = 0;
            foreach ($items as $item) {
                $grandTotal += ($item['qty'] * $item['price']);
            }

            // Create Purchase Record
            $purchase = Purchase::create([
                'supplier_id' => $supplier->id,
                'warehouse_id' => $warehouse->id,
                'user_id' => $user ? $user->id : 1, // Fallback to 1 if no user
                'purchase_date' => Carbon::now(),
                'status' => 'received', // Assuming 'received' creates valid stock
                'total_amount' => $grandTotal,
                'stock_added_to_warehouse' => true,
                'reference_number' => 'LCP-INIT-' . Carbon::now()->timestamp,
                'notes' => 'Generated by LifeCareProductsSeeder',
            ]);

            foreach ($items as $item) {
                // Check if product exists, or create
                // Note: We use firstOrCreate to avoid duplicates if run multiple times, 
                // but usually seeders reset or we tolerate duplicates if names are unique.
                $product = Product::firstOrCreate(
                    ['name' => $item['name']],
                    [
                        'category_id' => $category->id,
                        'stocking_unit_id' => $unit->id,
                        'sellable_unit_id' => $unit->id,
                        'units_per_stocking_unit' => 1,
                        'stock_quantity' => 0, // Will be updated by Purchase if system has observers, else set explicitly
                        'stock_alert_level' => 5,
                        'has_expiry_date' => false,
                    ]
                );

                // Create Purchase Item
                $totalCost = $item['qty'] * $item['price'];
                
                PurchaseItem::create([
                    'purchase_id' => $purchase->id,
                    'product_id' => $product->id,
                    'quantity' => $item['qty'],
                    'remaining_quantity' => $item['qty'], // Since units_per_stocking_unit is 1
                    'unit_cost' => $item['price'],
                    'total_cost' => $totalCost,
                    'sale_price' => $item['price'] * 1.3, // Example markup 30%
                    'cost_per_sellable_unit' => $item['price'],
                    'expiry_date' => Carbon::now()->addYears(2), // Default expiry
                ]);

                // Manually update product stock quantity if not handled by observers
                // (Models usually rely on observers or periodic jobs, but for seeding it makes sense to set it)
                $product->increment('stock_quantity', $item['qty']);
                
                // Also likely need to attach to warehouse if M-to-M exists
                // The Product model has 'warehouses' relation
                $existingPivot = $product->warehouses()->where('warehouse_id', $warehouse->id)->first();
                if ($existingPivot) {
                    $product->warehouses()->updateExistingPivot($warehouse->id, [
                        'quantity' => $existingPivot->pivot->quantity + $item['qty']
                    ]);
                } else {
                    $product->warehouses()->attach($warehouse->id, ['quantity' => $item['qty']]);
                }
            }
        });
    }
}
