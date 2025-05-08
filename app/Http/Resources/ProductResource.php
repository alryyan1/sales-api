<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    // app/Http/Resources/ProductResource.php
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'sku' => $this->sku,
            'description' => $this->description,
            // Prices are removed
            'stock_quantity' => $this->stock_quantity,
            'stock_alert_level' => $this->stock_alert_level,
            // 'unit' => $this->unit,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),

            // Optional: Expose calculated/latest costs/prices via accessors
            'latest_purchase_cost' => $this->latest_purchase_cost,
            'suggested_sale_price' =>  $this->suggested_sale_price,
            // Conditionally include batches if loaded
        // Use a different key like 'available_batches' to avoid confusion if 'purchaseItems' is used elsewhere
        'available_batches' => PurchaseItemResource::collection($this->whenLoaded('purchaseItems')),
        ];
    }
}
