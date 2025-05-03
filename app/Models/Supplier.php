<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany; // Import HasMany

class Supplier extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     * Make sure these match the columns you want to fill from requests.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'contact_person',
        'email',
        'phone',
        'address',
        // 'website', // Add if you included these fields
        // 'notes',
    ];

    /**
     * Get the purchases made from this supplier.
     * (Relationship to Purchase model - define Purchase model later)
     */
    public function purchases(): HasMany
    {
        // Make sure the Purchase model exists and has a 'supplier_id' foreign key
        return $this->hasMany(Purchase::class);
    }
}