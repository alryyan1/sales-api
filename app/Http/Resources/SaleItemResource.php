<?php // app/Http/Resources/SaleItemResource.php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SaleItemResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  Request  $request
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // $this->resource refers to the SaleItem model instance
        return [
            'id' => $this->id,
            'sale_id' => $this->sale_id, // ID of the parent sale
            'product_id' => $this->product_id,

            // Conditionally load product details if the 'product' relationship is eager loaded
            'product_name' => $this->whenLoaded('product', function () {
                return $this->product?->name; // Use optional chaining in case product is somehow null
            }),
            'product_sku' => $this->whenLoaded('product', function () {
                return $this->product?->sku;
            }),
             // Add your dynamic properties here
            'max_returnable_quantity' => $this->when(isset($this->max_returnable_quantity), function () {
                return $this->max_returnable_quantity;
            }),
            // If you want to include the full ProductResource when 'product' is loaded:
            // 'product' => new ProductResource($this->whenLoaded('product')),

            'purchase_item_id' => $this->purchase_item_id, // ID of the batch it was sold from

            // Conditionally load purchase item (batch) details if 'purchaseItemBatch' relationship is loaded
            'batch_number_sold' => $this->batch_number_sold ?? $this->whenLoaded('purchaseItemBatch', function () {
                return $this->purchaseItemBatch?->batch_number; // Get batch number from the related PurchaseItem
            }),
            'unit_cost_at_sale_time' => $this->whenLoaded('purchaseItemBatch', function () {
                // This is the cost of the item from the specific batch it was sold from, crucial for COGS
                return $this->purchaseItemBatch?->unit_cost;
            }),
            'batch_expiry_date' => $this->whenLoaded('purchaseItemBatch', function () {
                return $this->purchaseItemBatch?->expiry_date?->format('Y-m-d');
            }),
            // If you want to include the full PurchaseItemResource when 'purchaseItemBatch' is loaded:
            // 'batch_details' => new PurchaseItemResource($this->whenLoaded('purchaseItemBatch')),


            'quantity' => $this->quantity, // Quantity sold in this line item
            'unit_price' => $this->unit_price, // Price at which this item was sold (already cast to decimal:2 in model)
            'total_price' => $this->total_price, // quantity * unit_price (already cast in model)

            'created_at' => $this->created_at?->toISOString(), // Optional: if needed by frontend
            'updated_at' => $this->updated_at?->toISOString(), // Optional: if needed by frontend
        ];
    }
}