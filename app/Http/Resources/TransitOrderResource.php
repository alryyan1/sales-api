<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TransitOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_number' => $this->order_number,
            'order_date' => $this->order_date?->format('Y-m-d'),
            'warehouse_id' => $this->warehouse_id,
            'supplier_id' => $this->supplier_id,
            'eta_date' => $this->eta_date?->format('Y-m-d'),
            'notes' => $this->notes,
            'status' => $this->status,
            'user_id' => $this->user_id,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'warehouse' => new WarehouseResource($this->whenLoaded('warehouse')),
            'supplier' => new SupplierResource($this->whenLoaded('supplier')),
            'user' => $this->whenLoaded('user', fn() => [
                'id' => $this->user->id,
                'name' => $this->user->name,
            ]),
            'items' => TransitOrderItemResource::collection($this->whenLoaded('items')),
        ];
    }
}
