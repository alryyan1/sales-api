<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use App\Models\Unit;
use Illuminate\Database\Seeder;

/**
 * Imports products from a batch of scanned supplier invoices (Aug 2026 batch)
 * and a "Sales by Customer" report as Product records only — no Purchase
 * records and no warehouse stock. Products are seeded at zero quantity;
 * actual on-hand quantities are entered afterwards via a warehouse
 * Inventory Count (جرد المخزن).
 *
 * Only line items that were clearly legible were included. Ambiguous /
 * corrected / illegible lines were deliberately left out - see the summary
 * printed at the end for what to enter manually.
 *
 * Safe to re-run: products are matched by name, so nothing is duplicated
 * on a second run.
 */
class InvoiceImport202608Seeder extends Seeder
{
    /** @var array<string, Unit> */
    private array $units = [];

    /** @var array<string, Category> */
    private array $categories = [];

    public function run(): void
    {
        $this->seedUnits();
        $this->seedCategories();

        // ─────────────────────────────────────────────────────────────
        // Products from scanned supplier invoices. Seeded with cost_price
        // only, at zero stock — no Purchase records are created here.
        // ─────────────────────────────────────────────────────────────
        $invoiceItems = [
            // Wang and Wu Guimin International LLC — No. 2610, 2026-08-01
            ['name' => 'JP-500mL', 'cost' => 6.2, 'category' => 'household'],
            ['name' => 'YJC2-500', 'cost' => 0.3, 'category' => 'household'],
            ['name' => 'Tripot (Small)', 'cost' => 2.2, 'category' => 'household'],
            ['name' => 'Easy-0152', 'cost' => 5.2, 'category' => 'household'],
            ['name' => 'BS-004', 'cost' => 3.5, 'category' => 'household'],
            ['name' => 'Shower Bag (20L)', 'cost' => 1.5, 'category' => 'camping'],
            ['name' => 'Gas Pipe - 70cm', 'cost' => 0.7, 'category' => 'stoves'],

            // QASER — No. 3587, delivery 2026-06-17
            // (rows with handwritten corrections / illegible values excluded —
            // see summary at the end)
            ['name' => 'STOVE ZYZY 47', 'cost' => 95.00, 'category' => 'stoves'],
            ['name' => 'STOVE ZYZY 59', 'cost' => 135.00, 'category' => 'stoves'],
            ['name' => 'STOVE ZYZY 01 BLACK', 'cost' => 135.00, 'category' => 'stoves'],
            ['name' => 'STOVE ZYZY 01 ORANGE', 'cost' => 135.00, 'category' => 'stoves'],
            ['name' => 'STOVE ZYZY 88', 'cost' => 50.00, 'category' => 'stoves'],
            ['name' => 'STOVE ZAYZY 45', 'cost' => 65.00, 'category' => 'stoves'],
            ['name' => 'STOVE BRS 71', 'cost' => 130.00, 'category' => 'stoves'],
            ['name' => 'ZYZY 33-31', 'cost' => 35.00, 'category' => 'stoves'],
            ['name' => 'ZYZY 23-2', 'cost' => 20.00, 'category' => 'stoves'],
            ['name' => 'ZYZY 29A', 'cost' => 25.00, 'category' => 'stoves'],
            ['name' => 'ZYZY 29B', 'cost' => 30.00, 'category' => 'stoves'],
            ['name' => 'DADPTOR 18-462', 'cost' => 6.00, 'category' => 'stoves'],
            ['name' => 'ZYZY 28-6', 'cost' => 17.50, 'category' => 'stoves'],
            ['name' => 'CAMPING JERRYCAN 20L 1X4', 'cost' => 150.00, 'category' => 'camping'],
            ['name' => 'CAMPING JERRYCAN 30L 1X3', 'cost' => 150.00, 'category' => 'camping'],
            ['name' => 'P230', 'cost' => 4.00, 'category' => 'kitchen'],
            ['name' => 'P450', 'cost' => 6.00, 'category' => 'kitchen'],
            ['name' => 'LQ1860-6', 'cost' => 25.00, 'category' => 'kitchen'],
            ['name' => 'LQ1850-6', 'cost' => 20.00, 'category' => 'kitchen'],
            ['name' => 'LQ1840-6', 'cost' => 17.00, 'category' => 'kitchen'],
            ['name' => 'LQ1830-6', 'cost' => 14.00, 'category' => 'kitchen'],
            ['name' => 'LQ1820-6', 'cost' => 12.00, 'category' => 'kitchen'],
            ['name' => 'CUP DRINER 6011', 'cost' => 20.00, 'category' => 'kitchen'],
            ['name' => 'CUP DRINER 6012', 'cost' => 25.00, 'category' => 'kitchen'],
            ['name' => 'CUP DRINER 6013', 'cost' => 30.00, 'category' => 'kitchen'],
            ['name' => 'REGULATOR MONDIAL', 'cost' => 12.00, 'category' => 'stoves'],
            ['name' => 'ZYZY 74', 'cost' => 12.50, 'category' => 'stoves'],

            // Fakhruddin General Trading Co. LLC — S/Order 9839, 2026-07-25
            ['name' => 'Round Tray W/DSGN 45CM', 'cost' => 26.280, 'category' => 'kitchen'],
            ['name' => 'Timmy 20QLTR Pressure Cooker', 'cost' => 10.000, 'category' => 'kitchen'],
            ['name' => 'Timmy TM9180 15QLTR Pressure Cooker', 'cost' => 8.900, 'category' => 'kitchen'],
            ['name' => 'Timmy 18QLTR Pressure Cooker', 'cost' => 9.300, 'category' => 'kitchen'],
        ];

        $invoiceCount = $this->seedProducts($invoiceItems);
        $this->command->info("Created {$invoiceCount} products from the invoice batch (zero stock — use a warehouse Inventory Count to set quantities).");

        // ─────────────────────────────────────────────────────────────
        // "Sales by Customer" report (19-Jul-2026) — already-sold products,
        // added with sale_price only (no cost/stock).
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
        $this->command->newLine();
        $this->command->warn('All products above were seeded at zero stock. Use a warehouse Inventory Count (جرد المخزن) to record actual on-hand quantities.');
    }

    /**
     * Creates products (by name, skipping existing ones) at zero stock — no
     * Purchase records, no warehouse pivot. All products use the 'piece' unit.
     * Returns the number created.
     *
     * @param array<int, array{name:string, cost:float, category:string}> $items
     */
    private function seedProducts(array $items): int
    {
        $pieceUnit = $this->units['piece'];

        $count = 0;
        foreach ($items as $item) {
            $product = Product::firstOrNew(['name' => $item['name']]);
            if (!$product->exists) {
                $category = $this->categories[$item['category']];

                $product->category_id = $category->id;
                $product->cost_price = $item['cost'];
                $product->stocking_unit_id = $pieceUnit->id;
                $product->sellable_unit_id = $pieceUnit->id;
                $product->units_per_stocking_unit = 1;
                $product->save();
                $count++;
            }
        }
        return $count;
    }

    private function seedUnits(): void
    {
        $this->units['piece'] = Unit::firstOrCreate(
            ['name' => 'قطعة'],
            ['name_en' => 'Piece', 'description' => 'Piece', 'is_active' => true, 'is_default' => true]
        );
    }

    private function seedCategories(): void
    {
        $this->categories['stoves'] = Category::firstOrCreate(
            ['name' => 'مواقد وأجهزة غاز'],
            ['name_en' => 'Stoves & Gas Appliances', 'description' => 'Stoves & Gas Appliances']
        );
        $this->categories['camping'] = Category::firstOrCreate(
            ['name' => 'مستلزمات تخييم'],
            ['name_en' => 'Camping & Outdoor', 'description' => 'Camping & Outdoor']
        );
        $this->categories['kitchen'] = Category::firstOrCreate(
            ['name' => 'أدوات مطبخ'],
            ['name_en' => 'Kitchenware', 'description' => 'Kitchenware']
        );
        $this->categories['household'] = Category::firstOrCreate(
            ['name' => 'أدوات منزلية وبلاستيك'],
            ['name_en' => 'Household & Plastics', 'description' => 'Household & Plastics', 'is_default' => true]
        );
    }
}
