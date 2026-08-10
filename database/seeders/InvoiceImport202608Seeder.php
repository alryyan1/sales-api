<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\Unit;
use Illuminate\Database\Seeder;

/**
 * Imports products from a batch of scanned supplier invoices (Aug 2026 batch)
 * as real Purchase + PurchaseItem records (adding stock to the Main Warehouse),
 * plus a set of already-sold products from a "Sales by Customer" report
 * (added with sale_price only, no stock/purchase).
 *
 * Only line items that were clearly legible and internally consistent
 * (qty x price = total) were included. Ambiguous / corrected / illegible
 * lines were deliberately left out - see the summary printed at the end
 * for what to enter manually.
 *
 * Safe to re-run: products are matched by name, purchases by reference_number,
 * so nothing is duplicated on a second run.
 */
class InvoiceImport202608Seeder extends Seeder
{
    private const WAREHOUSE_ID = 1; // Main Warehouse
    private const USER_ID = 1;      // Super Admin

    /** @var array<string, Unit> */
    private array $units = [];

    /** @var array<string, Category> */
    private array $categories = [];

    public function run(): void
    {
        $this->seedUnits();
        $this->seedCategories();

        $supplierWang = Supplier::firstOrCreate(['name' => 'Wang and Wu Guimin International LLC']);
        $supplierQaser = Supplier::firstOrCreate(['name' => 'QASER']);
        $supplierFakhruddin = Supplier::firstOrCreate(['name' => 'Fakhruddin General Trading Co. LLC']);

        // ─────────────────────────────────────────────────────────────
        // Invoice: Wang and Wu Guimin International LLC — No. 2610, 2026-08-01
        // ─────────────────────────────────────────────────────────────
        $this->importPurchase(
            supplier: $supplierWang,
            referenceNumber: 'WWG-2610',
            date: '2026-08-01',
            note: 'مستورد من فاتورة Wang and Wu Guimin International LLC رقم 2610 بتاريخ 01/08/2026 (سيدر)',
            items: [
                ['name' => 'JP-500mL', 'qty' => 6, 'cost' => 6.2, 'unit' => 'piece', 'category' => 'household'],
                ['name' => 'YJC2-500', 'qty' => 5, 'cost' => 0.3, 'unit' => 'piece', 'category' => 'household'],
                ['name' => 'Tripot (Small)', 'qty' => 3, 'cost' => 2.2, 'unit' => 'piece', 'category' => 'household'],
                ['name' => 'Easy-0152', 'qty' => 2, 'cost' => 5.2, 'unit' => 'piece', 'category' => 'household'],
                ['name' => 'BS-004', 'qty' => 3, 'cost' => 3.5, 'unit' => 'piece', 'category' => 'household'],
                ['name' => 'Shower Bag (20L)', 'qty' => 2, 'cost' => 1.5, 'unit' => 'piece', 'category' => 'camping'],
                ['name' => 'Gas Pipe - 70cm', 'qty' => 10, 'cost' => 0.7, 'unit' => 'piece', 'category' => 'stoves'],
            ],
        );

        // ─────────────────────────────────────────────────────────────
        // Invoice: QASER — No. 3587, delivery 2026-06-17
        // (rows with handwritten corrections / illegible values excluded —
        // see summary at the end)
        // ─────────────────────────────────────────────────────────────
        $this->importPurchase(
            supplier: $supplierQaser,
            referenceNumber: 'QASER-3587',
            date: '2026-06-17',
            note: 'مستورد من فاتورة QASER رقم 3587 بتاريخ تسليم 17/06/2026 (سيدر) - تم استبعاد بعض الأصناف غير الواضحة',
            items: [
                ['name' => 'STOVE ZYZY 47', 'qty' => 4, 'cost' => 95.00, 'unit' => 'piece', 'category' => 'stoves'],
                ['name' => 'STOVE ZYZY 59', 'qty' => 4, 'cost' => 135.00, 'unit' => 'piece', 'category' => 'stoves'],
                ['name' => 'STOVE ZYZY 01 BLACK', 'qty' => 4, 'cost' => 135.00, 'unit' => 'piece', 'category' => 'stoves'],
                ['name' => 'STOVE ZYZY 01 ORANGE', 'qty' => 4, 'cost' => 135.00, 'unit' => 'piece', 'category' => 'stoves'],
                ['name' => 'STOVE ZYZY 88', 'qty' => 4, 'cost' => 50.00, 'unit' => 'piece', 'category' => 'stoves'],
                ['name' => 'STOVE ZAYZY 45', 'qty' => 4, 'cost' => 65.00, 'unit' => 'piece', 'category' => 'stoves'],
                ['name' => 'STOVE BRS 71', 'qty' => 6, 'cost' => 130.00, 'unit' => 'piece', 'category' => 'stoves'],
                ['name' => 'ZYZY 33-31', 'qty' => 6, 'cost' => 35.00, 'unit' => 'piece', 'category' => 'stoves'],
                ['name' => 'ZYZY 23-2', 'qty' => 12, 'cost' => 20.00, 'unit' => 'piece', 'category' => 'stoves'],
                ['name' => 'ZYZY 29A', 'qty' => 12, 'cost' => 25.00, 'unit' => 'piece', 'category' => 'stoves'],
                ['name' => 'ZYZY 29B', 'qty' => 12, 'cost' => 30.00, 'unit' => 'piece', 'category' => 'stoves'],
                ['name' => 'DADPTOR 18-462', 'qty' => 12, 'cost' => 6.00, 'unit' => 'piece', 'category' => 'stoves'],
                ['name' => 'ZYZY 28-6', 'qty' => 12, 'cost' => 17.50, 'unit' => 'piece', 'category' => 'stoves'],
                ['name' => 'CAMPING JERRYCAN 20L 1X4', 'qty' => 1, 'cost' => 150.00, 'unit' => 'carton', 'category' => 'camping'],
                ['name' => 'CAMPING JERRYCAN 30L 1X3', 'qty' => 1, 'cost' => 150.00, 'unit' => 'carton', 'category' => 'camping'],
                ['name' => 'P230', 'qty' => 36, 'cost' => 4.00, 'unit' => 'piece', 'category' => 'kitchen'],
                ['name' => 'P450', 'qty' => 36, 'cost' => 6.00, 'unit' => 'piece', 'category' => 'kitchen'],
                ['name' => 'LQ1860-6', 'qty' => 10, 'cost' => 25.00, 'unit' => 'piece', 'category' => 'kitchen'],
                ['name' => 'LQ1850-6', 'qty' => 10, 'cost' => 20.00, 'unit' => 'piece', 'category' => 'kitchen'],
                ['name' => 'LQ1840-6', 'qty' => 10, 'cost' => 17.00, 'unit' => 'piece', 'category' => 'kitchen'],
                ['name' => 'LQ1830-6', 'qty' => 10, 'cost' => 14.00, 'unit' => 'piece', 'category' => 'kitchen'],
                ['name' => 'LQ1820-6', 'qty' => 10, 'cost' => 12.00, 'unit' => 'piece', 'category' => 'kitchen'],
                ['name' => 'CUP DRINER 6011', 'qty' => 12, 'cost' => 20.00, 'unit' => 'piece', 'category' => 'kitchen'],
                ['name' => 'CUP DRINER 6012', 'qty' => 12, 'cost' => 25.00, 'unit' => 'piece', 'category' => 'kitchen'],
                ['name' => 'CUP DRINER 6013', 'qty' => 12, 'cost' => 30.00, 'unit' => 'piece', 'category' => 'kitchen'],
                ['name' => 'REGULATOR MONDIAL', 'qty' => 12, 'cost' => 12.00, 'unit' => 'piece', 'category' => 'stoves'],
                ['name' => 'ZYZY 74', 'qty' => 12, 'cost' => 12.50, 'unit' => 'piece', 'category' => 'stoves'],
            ],
        );

        // ─────────────────────────────────────────────────────────────
        // Invoice: Fakhruddin General Trading Co. LLC — S/Order 9839, 2026-07-25
        // ─────────────────────────────────────────────────────────────
        $this->importPurchase(
            supplier: $supplierFakhruddin,
            referenceNumber: 'FKH-9839',
            date: '2026-07-25',
            note: 'مستورد من فاتورة Fakhruddin General Trading رقم S/Order 9839 بتاريخ 25/07/2026 (سيدر)',
            items: [
                ['name' => 'Round Tray W/DSGN 45CM', 'qty' => 1, 'cost' => 26.280, 'unit' => 'dozen', 'category' => 'kitchen'],
                ['name' => 'Timmy 20QLTR Pressure Cooker', 'qty' => 3, 'cost' => 10.000, 'unit' => 'piece', 'category' => 'kitchen'],
                ['name' => 'Timmy TM9180 15QLTR Pressure Cooker', 'qty' => 3, 'cost' => 8.900, 'unit' => 'piece', 'category' => 'kitchen'],
                ['name' => 'Timmy 18QLTR Pressure Cooker', 'qty' => 3, 'cost' => 9.300, 'unit' => 'piece', 'category' => 'kitchen'],
            ],
        );

        // ─────────────────────────────────────────────────────────────
        // "Sales by Customer" report (19-Jul-2026) — already-sold products,
        // added with sale_price only (no supplier/cost/stock).
        // Unit prices below = printed line Total / Units (both figures were
        // legible and the 18 lines sum exactly to the report's stated
        // OMR 200.00 total, so these are high-confidence).
        // ─────────────────────────────────────────────────────────────
        $this->command->info('Seeding already-sold products from "Sales by Customer" report...');
        $soldItems = [
            ['sku' => '1026', 'name' => 'Rectangular Bowl With Holes', 'sale_price' => 0.90, 'category' => 'household'],
            ['sku' => '1143', 'name' => 'Tea Glass Set', 'sale_price' => 1.80, 'category' => 'kitchen'],
            ['sku' => '1297', 'name' => 'Syrian Hot Holder', 'sale_price' => 0.60, 'category' => 'kitchen'],
            ['sku' => '1389', 'name' => 'Ratchet Rope 3/8"', 'sale_price' => 1.50, 'category' => 'household'],
            ['sku' => '1419', 'name' => 'Tea Mat', 'sale_price' => 0.40, 'category' => 'kitchen'],
            ['sku' => '1538', 'name' => 'Folding BBQ Grill', 'sale_price' => 2.20, 'category' => 'camping'],
            ['sku' => '1545', 'name' => 'Bag Size 32x22x25cm', 'sale_price' => 5.50, 'category' => 'camping'],
            ['sku' => '1554', 'name' => '10 PCS Mekial', 'sale_price' => 1.80, 'category' => 'kitchen'],
            ['sku' => '21', 'name' => 'Bath Room Tent (S)', 'sale_price' => 5.50, 'category' => 'camping'],
            ['sku' => '266', 'name' => 'Moca Kettle 450 ML', 'sale_price' => 2.50, 'category' => 'kitchen'],
            ['sku' => '267', 'name' => 'Moca Kettle 600 ML', 'sale_price' => 3.00, 'category' => 'kitchen'],
            ['sku' => '469', 'name' => 'Tea Maker (BIG)', 'sale_price' => 3.00, 'category' => 'kitchen'],
            ['sku' => '79', 'name' => 'Bath Room Chair With Back', 'sale_price' => 3.00, 'category' => 'household'],
            ['sku' => '80', 'name' => 'Bath Room Chair', 'sale_price' => 2.00, 'category' => 'household'],
            ['sku' => '830', 'name' => 'Folding Bowl (S)', 'sale_price' => 0.90, 'category' => 'household'],
            ['sku' => '831', 'name' => 'Folding Bowl (B)', 'sale_price' => 1.20, 'category' => 'household'],
            ['sku' => '833', 'name' => 'Folding Plastic Dish With Net', 'sale_price' => 0.80, 'category' => 'household'],
        ];

        $soldCount = 0;
        foreach ($soldItems as $item) {
            $product = Product::firstOrNew(['name' => $item['name']]);
            if (!$product->exists) {
                $product->sku = $item['sku'];
                $product->category_id = $this->categories[$item['category']]->id;
                $product->sale_price = $item['sale_price'];
                $product->stocking_unit_id = $this->units['piece']->id;
                $product->sellable_unit_id = $this->units['piece']->id;
                $product->units_per_stocking_unit = 1;
                $product->save();
                $soldCount++;
            }
        }
        $this->command->info("Created {$soldCount} already-sold products (sale_price only, no stock).");

        $this->command->newLine();
        $this->command->warn('Skipped — needs manual review:');
        $this->command->line('- Wang and Wu Guimin #2610: 1 item with an illegible product code (qty 3, rate 3.8, amount 11.4)');
        $this->command->line('- QASER #3587: ZYZY 28-7, TQ1526, ZYZY 35-3, ZYZY 35-2, ZYZY 35-1B, STOVE N3600 R 1X6 (corrections/illegible or qty x price != total)');
        $this->command->line('- QASER #3670 (customer SHAZELIAH, 30/07/2026): entire invoice skipped - almost every line has handwritten corrections and the grand total itself was crossed out/re-written');
        $this->command->line('- Iman Household Appliances Trading (Dubai, invoice X78007, 30/07/2026): entire invoice skipped - priced in AED, no currency field on products table');
        $this->command->line('- "1403-LUXURY COFFEE AND..." from the Sales by Customer report: skipped, product name is cut off in the photo');
    }

    /**
     * @param array<int, array{name:string, qty:int|float, cost:float, unit:string, category:string}> $items
     */
    private function importPurchase(Supplier $supplier, string $referenceNumber, string $date, string $note, array $items): void
    {
        if (Purchase::where('reference_number', $referenceNumber)->exists()) {
            $this->command->info("Purchase {$referenceNumber} already imported, skipping.");
            return;
        }

        $purchase = Purchase::create([
            'warehouse_id' => self::WAREHOUSE_ID,
            'supplier_id' => $supplier->id,
            'user_id' => self::USER_ID,
            'purchase_date' => $date,
            'reference_number' => $referenceNumber,
            'status' => 'received',
            'notes' => $note,
            'total_amount' => 0,
        ]);

        $total = 0.0;

        foreach ($items as $item) {
            $unit = $this->units[$item['unit']];
            $category = $this->categories[$item['category']];

            $product = Product::firstOrCreate(
                ['name' => $item['name']],
                [
                    'category_id' => $category->id,
                    'cost_price' => $item['cost'],
                    'stocking_unit_id' => $unit->id,
                    'sellable_unit_id' => $unit->id,
                    'units_per_stocking_unit' => 1,
                ]
            );

            $totalCost = $item['qty'] * $item['cost'];
            $total += $totalCost;

            $purchase->items()->create([
                'product_id' => $product->id,
                'quantity' => $item['qty'],
                'unit_cost' => $item['cost'],
                'cost_per_sellable_unit' => $item['cost'],
                'total_cost' => $totalCost,
            ]);

            // Add stock to the warehouse (mirrors PurchaseController@store)
            $pivot = $product->warehouses()->where('warehouse_id', self::WAREHOUSE_ID)->first();
            if ($pivot) {
                $product->warehouses()->updateExistingPivot(self::WAREHOUSE_ID, [
                    'quantity' => $pivot->pivot->quantity + $item['qty'],
                ]);
            } else {
                $product->warehouses()->attach(self::WAREHOUSE_ID, ['quantity' => $item['qty']]);
            }
        }

        $purchase->total_amount = $total;
        $purchase->stock_added_to_warehouse = true;
        $purchase->save();

        $this->command->info("Imported purchase {$referenceNumber}: " . count($items) . " items, total {$total}.");
    }

    private function seedUnits(): void
    {
        $this->units['piece'] = Unit::firstOrCreate(
            ['name' => 'قطعة'],
            ['description' => 'Piece', 'is_active' => true, 'is_default' => true]
        );
        $this->units['set'] = Unit::firstOrCreate(
            ['name' => 'طقم'],
            ['description' => 'Set', 'is_active' => true]
        );
        $this->units['carton'] = Unit::firstOrCreate(
            ['name' => 'كرتون'],
            ['description' => 'Carton', 'is_active' => true]
        );
        $this->units['dozen'] = Unit::firstOrCreate(
            ['name' => 'دستة'],
            ['description' => 'Dozen', 'is_active' => true]
        );
    }

    private function seedCategories(): void
    {
        $this->categories['stoves'] = Category::firstOrCreate(
            ['name' => 'مواقد وأجهزة غاز'],
            ['description' => 'Stoves & Gas Appliances']
        );
        $this->categories['camping'] = Category::firstOrCreate(
            ['name' => 'مستلزمات تخييم'],
            ['description' => 'Camping & Outdoor']
        );
        $this->categories['kitchen'] = Category::firstOrCreate(
            ['name' => 'أدوات مطبخ'],
            ['description' => 'Kitchenware']
        );
        $this->categories['household'] = Category::firstOrCreate(
            ['name' => 'أدوات منزلية وبلاستيك'],
            ['description' => 'Household & Plastics', 'is_default' => true]
        );
    }
}
