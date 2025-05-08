<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SaleItemResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            // Include product details when loaded
            'product_id' => $this->product_id,
            'product_name' => $this->whenLoaded('product', fn() => $this->product->name),
            'product_sku' => $this->whenLoaded('product', fn() => $this->product->sku),
            'quantity' => $this->quantity,
            'unit_price' => $this->unit_price, // Already cast in model
            'total_price' => $this->total_price, // Already cast in model
            'purchase_item_id' => $this->purchase_item_id,
            'batch_number_sold' => $this->batch_number_sold,
            'unit_cost_at_sale_time' => $this->whenLoaded('purchaseItemBatch', fn() => $this->purchaseItemBatch?->unit_cost), // For COGS
            // 'created_at' => $this->created_at->toISOString(), // Optional
        ];
    }
}
