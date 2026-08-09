<?php

namespace App\Console\Commands;

use App\Models\InventoryCount;
use App\Models\InventoryCountItem;
use App\Models\Product;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

class ImportOpeningStockFromExcel extends Command
{
    protected $signature = 'import:opening-stock
        {--dry-run : Show what would be imported without writing to the database}
        {--warehouse=1 : Warehouse ID to stock the items into}';

    protected $description = 'Import products and opening stock quantities from the merged invoices and opening stock Excel files via an Inventory Count';

    /** @var array<string, array{name:string, unit:?string, qty:float, price:?float, source: string[]}> */
    private array $grouped = [];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $warehouseId = (int) $this->option('warehouse');

        $warehouse = Warehouse::find($warehouseId);
        if (!$warehouse) {
            $this->error("Warehouse #{$warehouseId} not found.");
            return 1;
        }

        $invoicesFile = base_path('عائشه بخيت mm.xlsx');
        $openingFile = base_path('مخزون_افتتاحي.xlsx');

        if (!file_exists($invoicesFile) || !file_exists($openingFile)) {
            $this->error('One or both Excel files were not found in the project root.');
            return 1;
        }

        $this->info('Reading invoices file...');
        $this->collectFromInvoicesFile($invoicesFile);

        $this->info('Reading opening stock file...');
        $this->collectFromOpeningFile($openingFile);

        $this->info('Total distinct products found: ' . count($this->grouped));

        if ($dryRun) {
            $this->table(
                ['Name', 'Unit', 'Qty', 'Price'],
                collect($this->grouped)->take(40)->map(fn($p) => [
                    $p['name'],
                    $p['unit'],
                    $p['qty'],
                    $p['price'],
                ])->toArray()
            );
            $this->warn('Dry run only - nothing was written. Showing first 40 of ' . count($this->grouped) . ' products.');
            return 0;
        }

        $user = User::first();

        DB::transaction(function () use ($warehouseId, $user) {
            $inventoryCount = InventoryCount::create([
                'warehouse_id' => $warehouseId,
                'user_id' => $user?->id,
                'count_date' => now()->toDateString(),
                'status' => 'completed',
                'notes' => 'استيراد آلي من ملفات الفواتير المدمجة والمخزون الافتتاحي',
            ]);

            $created = 0;
            $reused = 0;

            foreach ($this->grouped as $key => $data) {
                $product = Product::whereRaw('LOWER(name) = ?', [$key])->first();

                if (!$product) {
                    $unitId = $this->getOrCreateUnit($data['unit']);

                    $product = Product::create([
                        'name' => $data['name'],
                        'stocking_unit_id' => $unitId,
                        'sellable_unit_id' => $unitId,
                        'units_per_stocking_unit' => 1,
                        'cost_price' => $data['price'],
                        'sale_price' => $data['price'],
                    ]);
                    $created++;
                } else {
                    $reused++;
                }

                InventoryCountItem::create([
                    'inventory_count_id' => $inventoryCount->id,
                    'product_id' => $product->id,
                    'expected_quantity' => $product->countStock($warehouseId),
                    'actual_quantity' => $data['qty'],
                    'notes' => 'استيراد آلي',
                ]);
            }

            $this->info("Products created: {$created}, existing products reused: {$reused}");

            $inventoryCount->refresh();
            $approved = $inventoryCount->approve($user?->id ?? 0);

            if (!$approved) {
                $this->error('Failed to approve inventory count.');
            } else {
                $this->info("Inventory count #{$inventoryCount->id} approved. Stock quantities updated in warehouse #{$warehouseId}.");
            }
        });

        return 0;
    }

    private function collectFromInvoicesFile(string $path): void
    {
        $sheet = IOFactory::load($path)->getActiveSheet();
        $highestRow = $sheet->getHighestRow();

        for ($row = 2; $row <= $highestRow; $row++) {
            $data = $sheet->rangeToArray("A{$row}:L{$row}", null, true, true, false)[0];
            [$num, , , , , $name, $qty, , $unit, , $price] = array_pad($data, 11, null);

            if (!is_numeric($num) || empty($name)) {
                continue; // header/total/blank rows
            }

            $name = trim((string) $name);
            $qty = is_numeric($qty) ? (float) $qty : 0.0;

            $priceValue = $this->parsePrice($price);

            $this->addToGroup($name, $unit, $qty, $priceValue);
        }
    }

    private function collectFromOpeningFile(string $path): void
    {
        $sheet = IOFactory::load($path)->getActiveSheet();
        $highestRow = $sheet->getHighestRow();

        for ($row = 4; $row <= $highestRow; $row++) {
            $data = $sheet->rangeToArray("A{$row}:H{$row}", null, true, true, false)[0];
            [$num, $name, , $qty, , $unit, $price] = array_pad($data, 7, null);

            if (!is_numeric($num) || empty($name)) {
                continue; // header/total/blank rows
            }

            $name = trim((string) $name);
            $qty = is_numeric($qty) ? (float) $qty : 0.0;

            $priceValue = $this->parsePrice($price);

            $this->addToGroup($name, $unit, $qty, $priceValue);
        }
    }

    private function addToGroup(string $name, ?string $unit, float $qty, ?float $price): void
    {
        $key = mb_strtolower($name);
        $unit = $unit ? trim($unit) : null;

        if (!isset($this->grouped[$key])) {
            $this->grouped[$key] = [
                'name' => $name,
                'unit' => $unit,
                'qty' => 0.0,
                'price' => null,
                'price_weighted_sum' => 0.0,
                'price_qty_sum' => 0.0,
            ];
        }

        $this->grouped[$key]['qty'] += $qty;

        if ($unit && !$this->grouped[$key]['unit']) {
            $this->grouped[$key]['unit'] = $unit;
        }

        if ($price !== null) {
            $this->grouped[$key]['price_weighted_sum'] += $price * max($qty, 1);
            $this->grouped[$key]['price_qty_sum'] += max($qty, 1);
            $this->grouped[$key]['price'] = round(
                $this->grouped[$key]['price_weighted_sum'] / $this->grouped[$key]['price_qty_sum'],
                3
            );
        }
    }

    private function parsePrice($value): ?float
    {
        if ($value === null || $value === '' || $value === '-') {
            return null;
        }
        if (!is_numeric($value) && !is_numeric(str_replace(',', '', (string) $value))) {
            return null; // e.g. "#VALUE!" or a stray date string
        }
        return (float) str_replace(',', '', (string) $value);
    }

    private function getOrCreateUnit(?string $name): ?int
    {
        if (!$name) {
            $name = 'Piece';
        }

        $unit = Unit::whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first();
        if ($unit) {
            return $unit->id;
        }

        $unit = Unit::create([
            'name' => $name,
            'is_active' => true,
        ]);

        return $unit->id;
    }
}
