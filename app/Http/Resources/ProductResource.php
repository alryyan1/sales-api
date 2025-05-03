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
    public function toArray(Request $request): array
    {
        // Return the attributes defined in the model, respecting casts
        return [
            'id' => $this->id,
            'name' => $this->name,
            'sku' => $this->sku,
            'description' => $this->description,
            // Prices should already be cast to appropriate types (e.g., float/string) by the model's $casts
            'purchase_price' => $this->purchase_price,
            'sale_price' => $this->sale_price,
            'stock_quantity' => $this->stock_quantity, // Integer
            'stock_alert_level' => $this->stock_alert_level, // Integer or null
            // 'unit' => $this->unit, // Include if added
            'created_at' => $this->created_at->toISOString(),
            'updated_at' => $this->updated_at->toISOString(),

            // Example: Include category information if the relationship exists and is loaded
            // 'category' => new CategoryResource($this->whenLoaded('category')),
        ];
    }
}