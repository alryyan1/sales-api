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
            'scientific_name' => $this->scientific_name,
            'sku' => $this->sku,
            'description' => $this->description,
            'category_id' => $this->category_id,
            'category_name' => $this->whenLoaded('category', fn() => $this->category?->name),
            'stocking_unit_id' => $this->stocking_unit_id,
            'stocking_unit_name' => $this->whenLoaded('stockingUnit', fn() => $this->stockingUnit?->name),
            'sellable_unit_id' => $this->sellable_unit_id,
            'sellable_unit_name' => $this->whenLoaded('sellableUnit', fn() => $this->sellableUnit?->name),
            'units_per_stocking_unit' => (int) $this->units_per_stocking_unit,
            'stock_quantity' => (int) $this->stock_quantity, // Total in sellable units
            'stock_alert_level' => $this->stock_alert_level, // In sellable units
            'created_at' => $this->created_at->toISOString(),
            'updated_at' => $this->updated_at->toISOString(),
            
            'latest_cost_per_sellable_unit' => $this->whenAppended('latest_cost_per_sellable_unit', $this->latest_cost_per_sellable_unit),
            'suggested_sale_price_per_sellable_unit' => $this->whenAppended('suggested_sale_price_per_sellable_unit', $this->suggested_sale_price_per_sellable_unit),
            'available_batches' => PurchaseItemResource::collection($this->whenLoaded('purchaseItemsWithStock')),
        ];
    }
}
