<?php
namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ExpenseResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'amount' => $this->amount,
            'expense_date' => optional($this->expense_date)->toDateString(),
            'payment_method' => $this->payment_method,
            'reference' => $this->reference,
            'expense_category_id' => $this->expense_category_id,
            'expense_category_name' => optional($this->category)->name,
            'user_id' => $this->user_id,
            'user_name' => optional($this->user)->name,
            'created_at' => optional($this->created_at)->toDateTimeString(),
            'updated_at' => optional($this->updated_at)->toDateTimeString(),
        ];
    }
}


