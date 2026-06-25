<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class WarehouseStockImportSeeder extends Seeder
{
    public function run(): void
    {
        $warehouseId = 1; // Main Warehouse
        $userId      = DB::table('users')->value('id') ?? 1;
        $now         = Carbon::now();

        $products = [
            ['sku' => '1000', 'name' => 'القلفزات',                  'qty' => 57],
            ['sku' => '1001', 'name' => 'القلفزات الجراحية 28',       'qty' => 0],
            ['sku' => '1002', 'name' => 'head cover',                 'qty' => 7],
            ['sku' => '1003', 'name' => 'ecg paper',                  'qty' => 23],
            ['sku' => '1004', 'name' => 'شاش',                       'qty' => 6],
            ['sku' => '1005', 'name' => 'بلاستر',                    'qty' => 12],
            ['sku' => '1006', 'name' => 'يامدين',                    'qty' => 1],
            ['sku' => '1007', 'name' => 'فكريل مدور 0',              'qty' => 4],
            ['sku' => '1008', 'name' => 'فكريل 4/0',                 'qty' => 12],
            ['sku' => '1009', 'name' => 'فكريل 2 مدور',              'qty' => 2],
            ['sku' => '1010', 'name' => 'فكريل 1',                   'qty' => 4],
            ['sku' => '1011', 'name' => 'فكريل 2/0 مدور',            'qty' => 11],
            ['sku' => '1012', 'name' => 'كمامات',                    'qty' => 4],
            ['sku' => '1013', 'name' => 'نايلون 5/0 قاطع',           'qty' => 10],
            ['sku' => '1014', 'name' => 'نايلون 2 قاطع',             'qty' => 12],
            ['sku' => '1015', 'name' => 'قطن',                       'qty' => 20],
            ['sku' => '1016', 'name' => 'نايلون 1 قاطع',             'qty' => 12],
            ['sku' => '1017', 'name' => 'نايلون 2/0 مدور',           'qty' => 10],
            ['sku' => '1018', 'name' => 'نايلون 2/0 قاطع',           'qty' => 12],
            ['sku' => '1019', 'name' => 'نايلون 3/0 قاطع',           'qty' => 7],
            ['sku' => '1020', 'name' => 'نايلون 0 قاطع',             'qty' => 8],
        ];

        foreach ($products as $item) {
            // Skip if product with this SKU already exists
            if (DB::table('products')->where('sku', $item['sku'])->exists()) {
                $this->command->line("  Skipping existing SKU {$item['sku']}: {$item['name']}");
                continue;
            }

            // Insert product
            $productId = DB::table('products')->insertGetId([
                'name'                 => $item['name'],
                'sku'                  => $item['sku'],
                'category_id'          => null,
                'stocking_unit_id'     => null,
                'sellable_unit_id'     => null,
                'sale_price'           => 0,
                'cost_price'           => 0,
                'stock_alert_level'    => 5,
                'has_expiry_date'      => 0,
                'units_per_stocking_unit' => 1,
                'created_at'           => $now,
                'updated_at'           => $now,
            ]);

            $qty = (int) $item['qty'];

            // Insert into product_warehouse pivot
            DB::table('product_warehouse')->insert([
                'product_id'    => $productId,
                'warehouse_id'  => $warehouseId,
                'quantity'      => $qty,
                'min_stock_level' => 0,
                'created_at'    => $now,
                'updated_at'    => $now,
            ]);

            // Record in stock_adjustments for audit trail
            if ($qty > 0) {
                DB::table('stock_adjustments')->insert([
                    'product_id'      => $productId,
                    'warehouse_id'    => $warehouseId,
                    'user_id'         => $userId,
                    'quantity_change' => $qty,
                    'quantity_before' => 0,
                    'quantity_after'  => $qty,
                    'reason'          => 'جرد افتتاحي - استيراد من ملف رصد المخزن',
                    'notes'           => "SKU: {$item['sku']}",
                    'created_at'      => $now,
                    'updated_at'      => $now,
                ]);
            }

            $this->command->info("  ✓ [{$item['sku']}] {$item['name']} — qty: {$qty}");
        }

        $this->command->info('Done. ' . count($products) . ' products processed.');
    }
}
