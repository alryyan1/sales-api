<?php

namespace App\Console\Commands;

use App\Models\InventoryCount;
use App\Models\InventoryCountItem;
use App\Models\Product;
use App\Models\Unit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

class ImportPharmacyInventory extends Command
{
    protected $signature = 'pharmacy:import-inventory
                            {file? : مسار ملف Excel (الافتراضي: جرد  الصيدليه.xlsx)}
                            {--warehouse=1 : معرف المستودع}
                            {--user=1 : معرف المستخدم المسؤول عن الجرد}
                            {--dry-run : معاينة فقط بدون حفظ}
                            {--force : تخطي تأكيد الحذف}';

    protected $description = 'مسح المنتجات والمخزون ثم استيراد جرد الصيدلية من ملف Excel';

    public function handle(): int
    {
        $filePath    = $this->argument('file') ?? base_path('جرد  الصيدليه.xlsx');
        $warehouseId = (int) $this->option('warehouse');
        $userId      = (int) $this->option('user');
        $dryRun      = $this->option('dry-run');

        if (!file_exists($filePath)) {
            $this->error("الملف غير موجود: {$filePath}");
            return 1;
        }

        // ── قراءة ملف Excel ──────────────────────────────────────────────
        $this->info("جاري قراءة الملف...");
        $spreadsheet = IOFactory::load($filePath);
        $sheet       = $spreadsheet->getActiveSheet();
        $totalRows   = $sheet->getHighestRow();

        $dataRows = [];
        for ($r = 2; $r <= $totalRows; $r++) {
            $name = trim((string) ($sheet->getCell("A{$r}")->getValue() ?? ''));
            if ($name === '' || $name === 'الاسم التجاري') {
                continue;
            }
            $qty = (int) ($sheet->getCell("M{$r}")->getValue() ?? 0);
            if ($qty <= 0) {
                continue;
            }
            $dataRows[] = [
                'name'            => $name,
                'cost_price'      => $this->toFloat($sheet->getCell("I{$r}")->getValue()),
                'sale_price'      => $this->toFloat($sheet->getCell("K{$r}")->getValue()),
                'quantity'        => $qty,
                'barcode'         => trim((string) ($sheet->getCell("Q{$r}")->getValue() ?? '')),
            ];
        }

        $this->info("عدد الأدوية في الملف: " . count($dataRows));

        if ($dryRun) {
            $this->table(
                ['الاسم', 'التكلفة', 'البيع', 'الكمية', 'الباركود'],
                array_slice(array_map(fn($r) => [
                    $r['name'],
                    $r['cost_price'], $r['sale_price'], $r['quantity'], $r['barcode'],
                ], $dataRows), 0, 20)
            );
            $this->warn("(وضع المعاينة - لم يُحفظ شيء)");
            return 0;
        }

        // ── تأكيد الحذف ──────────────────────────────────────────────────
        if (!$this->option('force')) {
            $this->warn("⚠  سيتم حذف جميع المنتجات والمشتريات والمخزون والجرد السابق!");
            if (!$this->confirm("هل تريد المتابعة؟", false)) {
                $this->info("تم الإلغاء.");
                return 0;
            }
        }

        // ── 1. مسح الجداول (خارج الـ transaction لأن TRUNCATE يُغلقها) ──
        $this->info("جاري مسح البيانات القديمة...");
        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        DB::table('inventory_count_items')->truncate();
        DB::table('inventory_counts')->truncate();
        DB::table('stock_requisition_items')->truncate();
        DB::table('stock_requisitions')->truncate();
        DB::table('stock_adjustments')->truncate();
        DB::table('stock_transfers')->truncate();
        DB::table('sale_items')->truncate();
        DB::table('sale_return_items')->truncate();
        DB::table('sale_returns')->truncate();
        DB::table('payments')->truncate();
        DB::table('sales')->truncate();
        DB::table('purchase_items')->truncate();
        DB::table('purchase_payments')->truncate();
        DB::table('purchases')->truncate();
        DB::table('product_warehouse')->truncate();
        DB::table('products')->truncate();
        DB::table('units')->truncate();

        DB::statement('SET FOREIGN_KEY_CHECKS=1');
        $this->info("تم مسح البيانات القديمة.");

        DB::beginTransaction();
        try {

            // ── 2. إنشاء وحدة افتراضية ────────────────────────────────
            $unit = Unit::create([
                'name'       => 'قطعة',
                'is_default' => true,
                'is_active'  => true,
            ]);

            // ── 3. إنشاء المنتجات ─────────────────────────────────────
            $this->info("جاري إضافة المنتجات...");

            // نجمع المنتجات حسب الاسم (نفس الدواء قد يظهر أكثر من مرة ببيانات مختلفة)
            $productMap = [];   // name => Product
            $skuMap     = [];   // sku  => Product

            $bar = $this->output->createProgressBar(count($dataRows));
            $bar->start();

            // تجميع الكميات لكل منتج (مجموع دفعاته)
            $productTotals = [];  // name => total qty
            foreach ($dataRows as $row) {
                $key = mb_strtolower(trim($row['name']));
                $productTotals[$key] = ($productTotals[$key] ?? 0) + $row['quantity'];
            }

            foreach ($dataRows as $row) {
                $sku     = $this->extractSku($row['barcode']);
                $nameKey = mb_strtolower(trim($row['name']));

                // هل المنتج تم إنشاؤه مسبقاً في هذه الدورة؟
                $product = $productMap[$nameKey] ?? null;
                if (!$product && $sku) {
                    $product = $skuMap[$sku] ?? null;
                }

                if (!$product) {
                    $product = Product::create([
                        'name'                    => $row['name'],
                        'sku'                     => $sku,
                        'stocking_unit_id'        => $unit->id,
                        'sellable_unit_id'        => $unit->id,
                        'units_per_stocking_unit' => 1,
                        'stock_alert_level'       => 10,
                        'sale_price'              => $row['sale_price'] ?: null,
                        'cost_price'              => $row['cost_price'] ?: null,
                    ]);
                    $productMap[$nameKey] = $product;
                    if ($sku) $skuMap[$sku] = $product;
                } else {
                    // دفعة إضافية لنفس المنتج - حدّث الأسعار إن كانت فارغة
                    $updates = [];
                    if (!$product->sale_price && $row['sale_price']) $updates['sale_price'] = $row['sale_price'];
                    if (!$product->cost_price && $row['cost_price']) $updates['cost_price'] = $row['cost_price'];
                    if (!$product->sku && $sku) $updates['sku'] = $sku;
                    if ($updates) $product->update($updates);
                }

                $bar->advance();
            }

            $bar->finish();
            $this->newLine();
            $uniqueProducts = count($productMap);
            $this->info("تم إنشاء {$uniqueProducts} منتج.");

            // ── 4. إنشاء جرد المخزون ──────────────────────────────────
            $this->info("جاري إنشاء جرد المخزون...");

            $inventoryCount = InventoryCount::create([
                'warehouse_id' => $warehouseId,
                'user_id'      => $userId,
                'count_date'   => now()->toDateString(),
                'status'       => 'completed',
                'notes'        => 'جرد أولي مستورد من ملف Excel - جرد الصيدلية',
            ]);

            // إضافة بنود الجرد (مجموع الكميات لكل منتج)
            $insertedItems = [];
            foreach ($dataRows as $row) {
                $nameKey = mb_strtolower(trim($row['name']));
                $product = $productMap[$nameKey] ?? null;
                if (!$product) continue;

                $pid = $product->id;
                if (isset($insertedItems[$pid])) {
                    // دفعة إضافية: نجمع الكميات
                    InventoryCountItem::where([
                        'inventory_count_id' => $inventoryCount->id,
                        'product_id'         => $pid,
                    ])->increment('actual_quantity', $row['quantity']);
                    $insertedItems[$pid] += $row['quantity'];
                } else {
                    InventoryCountItem::create([
                        'inventory_count_id' => $inventoryCount->id,
                        'product_id'         => $pid,
                        'expected_quantity'  => 0,
                        'actual_quantity'    => $row['quantity'],
                        'notes'              => null,
                    ]);
                    $insertedItems[$pid] = $row['quantity'];
                }
            }

            // ── 5. اعتماد الجرد (يُحدّث product_warehouse) ───────────
            $this->info("جاري اعتماد الجرد وتحديث المخزون...");
            $inventoryCount->load('items.product');
            $inventoryCount->approve($userId);

            DB::commit();

            $totalQty = array_sum($insertedItems);
            $this->info("\n=== تم الاستيراد بنجاح ===");
            $this->info("المنتجات المضافة  : {$uniqueProducts}");
            $this->info("بنود الجرد        : " . count($insertedItems));
            $this->info("إجمالي الكميات    : " . number_format($totalQty));
            $this->info("رقم جلسة الجرد    : {$inventoryCount->id}");

        } catch (\Throwable $e) {
            DB::rollBack();
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
            $this->error("خطأ: " . $e->getMessage());
            $this->error($e->getTraceAsString());
            return 1;
        }

        return 0;
    }

    private function toFloat(mixed $v): float
    {
        if ($v === null || $v === '') return 0.0;
        $s = str_replace([',', ' '], '', (string) $v);
        return is_numeric($s) ? (float) $s : 0.0;
    }

    private function extractSku(string $raw): ?string
    {
        if (strlen($raw) >= 6 && preg_match('/^[0-9A-Za-z\-]+$/', $raw)) {
            return $raw;
        }
        return null;
    }
}
