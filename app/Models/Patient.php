<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Patient extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'insurance_card_number', 'insurance_company_id'];

    public function insuranceCompany(): BelongsTo
    {
        return $this->belongsTo(InsuranceCompany::class);
    }
}
