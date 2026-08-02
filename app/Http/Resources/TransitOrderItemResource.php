<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TransitOrderItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'transit_order_id' => $this->transit_order_id,
            'product_id' => $this->product_id,
            'quantity' => (float) $this->quantity,
            'is_received' => (bool) $this->is_received,
            'product' => new ProductResource($this->whenLoaded('product')),
        ];
    }
}
