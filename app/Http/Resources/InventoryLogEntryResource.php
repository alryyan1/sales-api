<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;
use Str;

class InventoryLogEntryResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     * The $this->resource here will be an object/stdClass from the DB::raw query.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            // Ensure keys match the SELECT aliases in your InventoryLogController query
            'transaction_date' => Carbon::parse($this->transaction_date)->toISOString(), // Standardize date format
            'type' => $this->type,
            'type_display' => $this->getDisplayType($this->type), // Add a helper for display type
            'product_id' => $this->product_id,
            'product_name' => $this->product_name,
            'product_sku' => $this->product_sku,
            'batch_number' => $this->batch_number,
            'quantity_change' => (int) $this->quantity_change,
            'document_reference' => $this->document_reference,
            'document_id' => $this->document_id, // ID of Sale, Purchase, Requisition, Adjustment
            'user_name' => $this->user_name,
            'reason_notes' => $this->reason_notes,
            // Add any other fields selected in your UNION query
        ];
    }

    /**
     * Get a display-friendly type name.
     */
    protected function getDisplayType(string $type): string
    {
        // This could also use translations if needed on the backend,
        // but usually, the frontend handles display translations.
        return match ($type) {
            'purchase' => 'Purchase Receipt',
            'sale' => 'Sale Issue',
            'adjustment' => 'Stock Adjustment',
            'requisition_issue' => 'Requisition Issue',
            default => Str::title(str_replace('_', ' ', $type)),
        };
    }
}