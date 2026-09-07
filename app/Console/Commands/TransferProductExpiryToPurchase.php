<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\Purchase;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class TransferProductExpiryToPurchase extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'purchases:transfer-expiry-dates';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Copy the expiry date set directly on products (Product::expire_date) onto the matching items of a purchase invoice you choose interactively.';

    public function handle()
    {
        $products = Product::whereNotNull('expire_date')->orderBy('name')->get();

        if ($products->isEmpty()) {
            $this->info('لا توجد منتجات لديها تاريخ صلاحية مضاف.');

            return self::SUCCESS;
        }

        $this->info("تم العثور على {$products->count()} منتج لديه تاريخ صلاحية مضاف:");
        $this->table(
            ['ID', 'الاسم', 'SKU', 'تاريخ الصلاحية'],
            $products->map(fn (Product $p) => [
                $p->id,
                $p->name,
                $p->sku,
                $p->expire_date?->format('Y-m-d'),
            ])
        );

        $identifier = $this->ask('أدخل رقم فاتورة المشتريات (ID) أو رقمها المرجعي (reference_number) التي تريد نقل التواريخ إليها');

        if ($identifier === null || trim((string) $identifier) === '') {
            $this->error('لم يتم إدخال رقم فاتورة.');

            return self::FAILURE;
        }

        $purchase = is_numeric($identifier) ? Purchase::find((int) $identifier) : null;
        $purchase ??= Purchase::where('reference_number', $identifier)->first();

        if (! $purchase) {
            $this->error("لم يتم العثور على فاتورة مشتريات بالمعرّف/الرقم المرجعي: {$identifier}");

            return self::FAILURE;
        }

        $this->info("الفاتورة المختارة: #{$purchase->id} - {$purchase->reference_number} - ".optional($purchase->supplier)->name);

        $productIds = $products->pluck('id');
        $items = $purchase->items()->whereIn('product_id', $productIds)->get();

        if ($items->isEmpty()) {
            $this->warn('لا توجد أصناف في هذه الفاتورة تطابق أيًا من المنتجات التي لديها تاريخ صلاحية.');

            return self::SUCCESS;
        }

        $productsById = $products->keyBy('id');

        $this->info("سيتم تحديث {$items->count()} صنف في الفاتورة #{$purchase->id} على النحو التالي (سيُستبدل أي تاريخ حالي):");
        $this->table(
            ['ID الصنف', 'المنتج', 'رقم الدفعة', 'التاريخ الحالي', 'التاريخ الجديد'],
            $items->map(function ($item) use ($productsById) {
                $product = $productsById[$item->product_id];

                return [
                    $item->id,
                    $product->name,
                    $item->batch_number,
                    $item->expiry_date?->format('Y-m-d') ?? '—',
                    $product->expire_date->format('Y-m-d'),
                ];
            })
        );

        $matchedProductIds = $items->pluck('product_id')->unique();
        $unmatchedProducts = $products->whereNotIn('id', $matchedProductIds);
        if ($unmatchedProducts->isNotEmpty()) {
            $this->warn('المنتجات التالية لديها تاريخ صلاحية لكن لا يوجد لها صنف في هذه الفاتورة (لن يتم لمسها):');
            $this->table(
                ['ID', 'الاسم'],
                $unmatchedProducts->map(fn (Product $p) => [$p->id, $p->name])
            );
        }

        if (! $this->confirm('هل تريد تنفيذ النقل؟', true)) {
            $this->info('تم الإلغاء.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($items, $productsById) {
            foreach ($items as $item) {
                $item->expiry_date = $productsById[$item->product_id]->expire_date;
                $item->save();
            }
        });

        $this->info("تم تحديث تاريخ الصلاحية لعدد {$items->count()} صنف في الفاتورة #{$purchase->id} بنجاح.");

        return self::SUCCESS;
    }
}
