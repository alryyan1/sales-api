<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockRequisitionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'requester_user_id' => $this->requester_user_id,
            'requester_name' => $this->whenLoaded('requesterUser', fn() => $this->requesterUser?->name),
            'approved_by_user_id' => $this->approved_by_user_id,
            'approver_name' => $this->whenLoaded('approvedByUser', fn() => $this->approvedByUser?->name),
            'department_or_reason' => $this->department_or_reason,
            'notes' => $this->notes,
            'status' => $this->status,
            'request_date' => $this->request_date?->format('Y-m-d'),
            'issue_date' => $this->issue_date?->format('Y-m-d'),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'items' => StockRequisitionItemResource::collection($this->whenLoaded('items')),
        ];
    }
}