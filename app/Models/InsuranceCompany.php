<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InsuranceCompany extends Model
{
    use HasFactory;

    public const COPAY_FIXED = 'fixed';

    public const COPAY_VARIABLE = 'variable';

    protected $fillable = ['name', 'phone', 'email', 'invoice_cap', 'copay_type', 'copay_percentage'];

    protected $casts = [
        'invoice_cap' => 'float',
        'copay_percentage' => 'float',
    ];

    public function hasFixedCopay(): bool
    {
        return $this->copay_type === self::COPAY_FIXED;
    }

    public function patients(): HasMany
    {
        return $this->hasMany(Patient::class);
    }

    public function subCompanies(): HasMany
    {
        return $this->hasMany(InsuranceSubCompany::class)->orderBy('name');
    }
}
