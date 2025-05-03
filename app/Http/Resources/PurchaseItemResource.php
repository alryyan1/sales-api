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
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            // Include product details (using ProductResource) when the relationship is loaded
            // 'product' => new ProductResource($this->whenLoaded('product')), // Option 1: Full product details
             'product_id' => $this->product_id,                           // Option 2a: Just ID
             'product_name' => $this->whenLoaded('product', fn() => $this->product->name), // Option 2b: ID + Name
             'product_sku' => $this->whenLoaded('product', fn() => $this->product->sku),   // Option 2c: ID + Name + SKU
            'quantity' => $this->quantity,
            'unit_cost' => $this->unit_cost, // Already cast in model
            'total_cost' => $this->total_cost, // Already cast in model
            // 'created_at' => $this->created_at->toISOString(), // Optional timestamp
        ];
    }
}