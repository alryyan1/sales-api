<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ScheduledSaleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'client_id' => $this->client_id,
            'client' => $this->whenLoaded('client', fn () => [
                'id' => $this->client?->id,
                'name' => $this->client?->name,
                'phone' => $this->client?->phone,
            ]),
            'user_id' => $this->user_id,
            'user_name' => $this->whenLoaded('user', fn () => $this->user?->name),
            'scheduled_at' => $this->scheduled_at?->toISOString(),
            'status' => $this->status,
            'discount_amount' => $this->discount_amount !== null ? (float) $this->discount_amount : null,
            'discount_type' => $this->discount_type,
            'notes' => $this->notes,

            'sale_id' => $this->sale_id,
            'sale' => $this->whenLoaded('sale', fn () => $this->sale ? [
                'id' => $this->sale->id,
                'number' => $this->sale->number,
            ] : null),

            'error_message' => $this->error_message,
            'executed_at' => $this->executed_at?->toISOString(),

            'whatsapp_customer_status' => $this->whatsapp_customer_status,
            'whatsapp_customer_error' => $this->whatsapp_customer_error,
            'whatsapp_customer_sent_at' => $this->whatsapp_customer_sent_at?->toISOString(),

            'whatsapp_owner_status' => $this->whatsapp_owner_status,
            'whatsapp_owner_error' => $this->whatsapp_owner_error,
            'whatsapp_owner_sent_at' => $this->whatsapp_owner_sent_at?->toISOString(),

            'items' => ScheduledSaleItemResource::collection($this->whenLoaded('items')),

            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
