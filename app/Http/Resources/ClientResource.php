<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ClientResource extends JsonResource
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
            'name' => $this->name,
            'email' => $this->email, // Return null if null in DB
            'phone' => $this->phone, // Return null if null in DB
            'address' => $this->address, // Return null if null in DB
            'created_at' => $this->created_at?->toISOString(), // Handle when not selected in relations
            'updated_at' => $this->updated_at?->toISOString(),

            // Example: Conditionally load relationships if needed later
            // 'sales_count' => $this->whenCounted('sales'),
            // 'sales' => SaleResource::collection($this->whenLoaded('sales')),
        ];
    }
}