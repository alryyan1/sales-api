<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SaleResource extends JsonResource
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
            'sale_order_number' => $this->sale_order_number,
            // Client Info
            'client_id' => $this->client_id,
            'client_name' => $this->whenLoaded('client', fn() => $this->client?->name), // Use optional chaining
            // Include full client object when eager loaded so frontend can pre-select it in UI
            'client' => $this->whenLoaded('client', fn() => new ClientResource($this->client)),

             // User (Salesperson) Info
             'user_id' => $this->user_id,
             'user_name' => $this->whenLoaded('user', fn() => $this->user?->name),

            'sale_date' => $this->sale_date->format('Y-m-d'),
            'invoice_number' => $this->invoice_number,
            'is_returned' => $this->is_returned ?? false,

            // Computed financial fields
            // total_amount is now derived from items (sum of total_price) for backward compatibility
            'total_amount' => $this->items->sum('total_price'),
            'discount_amount' => $this->discount_amount,
            'discount_type' => $this->discount_type,
            'notes' => $this->notes,
            'created_at' => $this->created_at ? $this->created_at->toISOString() : null,
            'updated_at' => $this->updated_at ? $this->updated_at->toISOString() : null, // Include if needed

            // Conditionally include sale items
            'items' => SaleItemResource::collection($this->whenLoaded('items')),
            'paid_amount' => $this->getCalculatedPaidAmountAttribute(), // computed from payments
            'due_amount' => $this->getCalculatedDueAmountAttribute(),   // computed from items, discount, and payments
            'payments' => PaymentResource::collection($this->whenLoaded('payments')),
            
        ];
    }
}