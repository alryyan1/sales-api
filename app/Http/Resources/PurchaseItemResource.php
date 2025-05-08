<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

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
            'quantity' => $this->quantity,
            'unit_cost' => $this->unit_cost, // This IS the cost price for this batch
            'total_cost' => $this->total_cost,
            'sale_price' => $this->sale_price,   // New
            'batch_number' => $this->batch_number, // New
            'expiry_date' => $this->expiry_date ? $this->expiry_date->format('Y-m-d') : null, // New
            'remaining_quantity' => $this->remaining_quantity
        ];
    }
}
