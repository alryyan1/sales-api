<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Money columns storing OMR (which has 3 decimal places / 1000 baisa).
     * Widening the scale from 2 to 3 lets the app actually persist baisa
     * instead of silently rounding them away on save.
     *
     * Each entry: [precision, oldScale, nullable, default]. `default` is the
     * raw SQL default clause fragment (or null for "no default").
     */
    private array $columns = [
        'expenses' => [
            'amount' => [12, 2, false, null],
        ],
        'payments' => [
            'amount' => [12, 2, false, null],
        ],
        'products' => [
            'cost_price' => [15, 2, true, 'NULL'],
            'sale_price' => [15, 2, true, 'NULL'],
        ],
        'purchases' => [
            'total_amount' => [12, 2, false, '0'],
        ],
        'purchase_items' => [
            'unit_cost' => [10, 2, false, null],
            'total_cost' => [12, 2, false, null],
            'sale_price' => [10, 2, true, 'NULL'],
            'sale_price_stocking_unit' => [10, 2, true, 'NULL'],
            'cost_per_sellable_unit' => [10, 2, false, '0'],
        ],
        'purchase_payments' => [
            'amount' => [12, 2, false, null],
        ],
        'sales' => [
            'discount_amount' => [12, 2, false, '0'],
        ],
        'sale_items' => [
            'unit_price' => [10, 2, false, null],
            'total_price' => [12, 2, false, null],
            'cost_price_at_sale' => [10, 2, false, '0'],
        ],
        'sale_return_items' => [
            'price' => [12, 2, false, null],
        ],
    ];

    public function up(): void
    {
        $this->applyScale(3);
    }

    public function down(): void
    {
        $this->applyScale(2, true);
    }

    private function applyScale(int $scale, bool $useOldScale = false): void
    {
        foreach ($this->columns as $table => $cols) {
            foreach ($cols as $column => [$precision, $oldScale, $nullable, $default]) {
                $targetScale = $useOldScale ? $oldScale : $scale;
                $sql = "ALTER TABLE `{$table}` MODIFY `{$column}` DECIMAL({$precision}, {$targetScale}) " . ($nullable ? 'NULL' : 'NOT NULL');
                if ($default !== null) {
                    $sql .= " DEFAULT {$default}";
                }
                DB::statement($sql);
            }
        }
    }
};
