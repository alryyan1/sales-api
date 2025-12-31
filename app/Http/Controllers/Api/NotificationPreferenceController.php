<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\NotificationPreference;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class NotificationPreferenceController extends Controller
{
    /**
     * Get all notification preferences for the authenticated user.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $user = $request->user();
        
        // Initialize preferences if they don't exist
        NotificationPreference::initializeForUser($user);
        
        $preferences = NotificationPreference::where('user_id', $user->id)
            ->get()
            ->mapWithKeys(function ($pref) {
                return [$pref->notification_type => $pref->enabled];
            });

        // Include all notification types with defaults for missing ones
        $defaults = NotificationPreference::getDefaults();
        $allPreferences = [];
        
        foreach ($defaults as $type => $defaultEnabled) {
            $allPreferences[$type] = $preferences->get($type, $defaultEnabled);
        }

        return response()->json([
            'preferences' => $allPreferences,
        ]);
    }

    /**
     * Update notification preferences for the authenticated user.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request)
    {
        $user = $request->user();
        $validated = $request->validate([
            'preferences' => 'required|array',
            'preferences.*' => 'boolean',
        ]);

        foreach ($validated['preferences'] as $type => $enabled) {
            NotificationPreference::updateOrCreate(
                [
                    'user_id' => $user->id,
                    'notification_type' => $type,
                ],
                [
                    'enabled' => $enabled,
                ]
            );
        }

        Log::info("Notification preferences updated for user {$user->id}");

        return response()->json([
            'message' => 'تم تحديث تفضيلات الإشعارات بنجاح',
            'preferences' => $this->index($request)->getData()->preferences,
        ]);
    }

    /**
     * Toggle a single notification preference.
     *
     * @param Request $request
     * @param string $type
     * @return \Illuminate\Http\JsonResponse
     */
    public function toggle(Request $request, string $type)
    {
        $user = $request->user();
        
        $preference = NotificationPreference::firstOrCreate(
            [
                'user_id' => $user->id,
                'notification_type' => $type,
            ],
            [
                'enabled' => true,
            ]
        );

        $preference->enabled = !$preference->enabled;
        $preference->save();

        return response()->json([
            'message' => 'تم تحديث التفضيل بنجاح',
            'preference' => [
                'type' => $type,
                'enabled' => $preference->enabled,
            ],
        ]);
    }
}
