<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationPreference extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'notification_type',
        'enabled',
    ];

    protected $casts = [
        'enabled' => 'boolean',
    ];

    /**
     * Get the user that owns the preference.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get default preferences for a user.
     * Returns array of notification types with default enabled state.
     */
    public static function getDefaults(): array
    {
        return [
            'low_stock' => true,
            'out_of_stock' => true,
            'new_sale' => true,
            'purchase_received' => true,
            'stock_requisition' => true,
            'expiry_alert' => true,
            'system' => true,
            'warning' => true,
            'error' => true,
            'success' => true,
        ];
    }

    /**
     * Get or create default preferences for a user.
     */
    public static function initializeForUser(User $user): void
    {
        $defaults = self::getDefaults();
        
        foreach ($defaults as $type => $enabled) {
            self::firstOrCreate(
                [
                    'user_id' => $user->id,
                    'notification_type' => $type,
                ],
                [
                    'enabled' => $enabled,
                ]
            );
        }
    }

    /**
     * Check if user has enabled a specific notification type.
     */
    public static function isEnabled(User $user, string $notificationType): bool
    {
        $preference = self::where('user_id', $user->id)
            ->where('notification_type', $notificationType)
            ->first();

        // If no preference exists, default to enabled
        if (!$preference) {
            return true;
        }

        return $preference->enabled;
    }
}
