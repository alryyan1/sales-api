<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InsuranceCompany extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'phone', 'email'];

    public function patients(): HasMany
    {
        return $this->hasMany(Patient::class);
    }
}
