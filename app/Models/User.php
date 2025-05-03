<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens; // <-- Make sure this is imported
use Illuminate\Database\Eloquent\Relations\HasMany; // Import HasMany

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable; // <-- Make sure HasApiTokens is used

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed', // Use 'hashed' for Laravel 10+
    ];

    /**
     * Get the sales associated with the user (as salesperson).
     */
    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }
    // Add this relationship method
    public function purchasesRecorded(): HasMany
    {
        return $this->hasMany(Purchase::class); // Assumes user_id foreign key in purchases
    }
}
