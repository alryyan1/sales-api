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
            'batch_number' => $this->batch_number,
            'quantity' => $this->quantity, // e.g., number of boxes purchased
            'remaining_quantity' => $this->remaining_quantity, // e.g., number of pieces remaining
            'unit_cost' => $this->unit_cost, // e.g., cost per box
            'cost_per_sellable_unit' => $this->cost_per_sellable_unit, // Accessor value
            'total_cost' => $this->total_cost,
            'sale_price' => $this->sale_price, // Intended sale price per sellable unit for this batch
            'sale_price_stocking_unit' => $this->sale_price_stocking_unit,
            'expiry_date' => $this->expiry_date ? $this->expiry_date->format('Y-m-d') : null,
            'purchase' => new PurchaseResource($this->whenLoaded('purchase')),
        ];
    }
}
