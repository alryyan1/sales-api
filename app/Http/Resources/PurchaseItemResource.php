<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;

class PurchaseItemResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    // app/Http/Resources/PurchaseItemResource.php
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'product_name' => $this->whenLoaded('product', fn() => $this->product->name),
            'product_sku' => $this->whenLoaded('product', fn() => $this->product->sku),
            'product_image_url' => $this->whenLoaded('product', function () {
                $url = $this->product->image_url;
                if (!$url) return null;
                return str_starts_with($url, 'http') ? $url : asset($url);
            }),
            'batch_number' => $this->batch_number,
            'quantity' => $this->quantity, // e.g., number of boxes purchased
            'unit_cost' => $this->unit_cost, // e.g., cost per box
            'cost_per_sellable_unit' => $this->cost_per_sellable_unit, // Accessor value
            'total_cost' => $this->total_cost,
            'sale_price' => $this->sale_price, // Intended sale price per sellable unit for this batch
            'sale_price_stocking_unit' => $this->sale_price_stocking_unit,
            'expiry_date' => $this->expiry_date ? $this->expiry_date->format('Y-m-d') : null,
            'purchase_id' => $this->purchase_id,
            'purchase_date' => $this->whenLoaded('purchase', fn() => $this->purchase?->purchase_date),
            'purchase_currency' => $this->whenLoaded('purchase', fn() => $this->purchase?->currency ?? 'SDG'),
            'supplier_name' => $this->whenLoaded('purchase', fn() => $this->purchase?->supplier?->name),
            'quantity_returned' => DB::table('purchase_return_items')
                ->join('purchase_returns', 'purchase_return_items.purchase_return_id', '=', 'purchase_returns.id')
                ->where('purchase_returns.purchase_id', $this->purchase_id)
                ->where('purchase_return_items.product_id', $this->product_id)
                ->sum('purchase_return_items.quantity') ?? 0,
            'available_stock' => $this->whenLoaded('purchase', fn() =>
                DB::table('product_warehouse')
                    ->where('product_id', $this->product_id)
                    ->where('warehouse_id', $this->purchase?->warehouse_id)
                    ->value('quantity') ?? 0
            ),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
