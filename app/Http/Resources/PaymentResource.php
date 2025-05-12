<?php // app/Http/Resources/PaymentResource.php
namespace App\Http\Resources;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
class PaymentResource extends JsonResource {
    public function toArray(Request $request): array {
        return [
            'id' => $this->id,
            'sale_id' => $this->sale_id,
            'user_name' => $this->whenLoaded('user', fn() => $this->user?->name),
            'method' => $this->method,
            'amount' => $this->amount,
            'payment_date' => $this->payment_date->format('Y-m-d'),
            'reference_number' => $this->reference_number,
            'notes' => $this->notes,
            'created_at' => $this->created_at->toISOString(),
        ];
    }
}