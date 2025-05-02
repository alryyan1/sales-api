<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany; // Import HasMany

class Client extends Model
{
    use HasFactory;

    // Allow mass assignment for these fields
    protected $fillable = [
        'name',
        'email',
        'phone',
        'address',
    ];

    /**
     * Get the sales associated with the client.
     */
    public function sales(): HasMany // Define the relationship
    {
        return $this->hasMany(Sale::class);
    }
}