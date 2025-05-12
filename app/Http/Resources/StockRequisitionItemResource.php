<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockRequisitionItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'stock_requisition_id' => $this->stock_requisition_id,
            'product_id' => $this->product_id,
            'product' => new ProductResource($this->whenLoaded('product')), // Basic product info
            'requested_quantity' => $this->requested_quantity,
            'issued_quantity' => $this->issued_quantity,
            'issued_from_purchase_item_id' => $this->issued_from_purchase_item_id,
            'issued_batch_number' => $this->issued_batch_number,
             // Optionally load more batch details if needed
            'batch_details' => new PurchaseItemResource($this->whenLoaded('issuedFromPurchaseItemBatch')),
            'status' => $this->status,
            'item_notes' => $this->item_notes,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}