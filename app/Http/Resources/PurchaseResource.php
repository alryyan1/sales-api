<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseResource extends JsonResource
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
            // Supplier Info (using SupplierResource) when loaded
            // 'supplier' => new SupplierResource($this->whenLoaded('supplier')), // Option 1: Full details
            'supplier_id' => $this->supplier_id, // Option 2a: Just ID
            'supplier_name' => $this->whenLoaded('supplier', fn() => $this->supplier?->name), // Option 2b: ID + Name (use optional chaining ?. )

             // User Info (optional)
             'user_id' => $this->user_id,
             'user_name' => $this->whenLoaded('user', fn() => $this->user?->name),

            'purchase_date' => $this->purchase_date->format('Y-m-d'), // Format date
            'reference_number' => $this->reference_number,
            'status' => $this->status,
            'total_amount' => $this->total_amount, // Already cast in model
            'notes' => $this->notes,
            'created_at' => $this->created_at->toISOString(),
            'currency' => $this->currency,

            // Conditionally include purchase items (usually for the 'show' endpoint)
            // Use PurchaseItemResource::collection to format each item
            'items' => PurchaseItemResource::collection($this->whenLoaded('items')),
        ];
    }
}