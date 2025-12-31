<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Notifications\DatabaseNotification;

class NotificationController extends Controller
{
    /**
     * Get paginated notifications for the authenticated user.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $perPage = $request->input('per_page', 15);
        $page = $request->input('page', 1);

        $notifications = $user->notifications()
            ->orderBy('created_at', 'desc')
            ->paginate($perPage, ['*'], 'page', $page);

        // Transform notifications to include title, message, type, and data from the data field
        $transformedNotifications = $notifications->getCollection()->map(function ($notification) {
            // Laravel stores notification data in the 'data' column as JSON
            $notificationData = is_array($notification->data) ? $notification->data : json_decode($notification->data, true) ?? [];
            
            return [
                'id' => $notification->id,
                'type' => $notificationData['type'] ?? 'system',
                'title' => $notificationData['title'] ?? 'إشعار',
                'message' => $notificationData['message'] ?? '',
                'data' => $notificationData['data'] ?? [],
                'read_at' => $notification->read_at ? $notification->read_at->toIso8601String() : null,
                'created_at' => $notification->created_at->toIso8601String(),
            ];
        });

        return response()->json([
            'data' => $transformedNotifications->values()->all(),
            'pagination' => [
                'current_page' => $notifications->currentPage(),
                'last_page' => $notifications->lastPage(),
                'per_page' => $notifications->perPage(),
                'total' => $notifications->total(),
                'from' => $notifications->firstItem(),
                'to' => $notifications->lastItem(),
            ],
        ]);
    }

    /**
     * Get unread notifications count.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function unreadCount(Request $request)
    {
        $user = $request->user();
        $count = $user->unreadNotifications()->count();

        return response()->json([
            'count' => $count,
        ]);
    }

    /**
     * Mark a notification as read.
     *
     * @param Request $request
     * @param string $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function markAsRead(Request $request, string $id)
    {
        $user = $request->user();
        $notification = $user->notifications()->find($id);

        if (!$notification) {
            return response()->json([
                'message' => 'الإشعار غير موجود',
            ], Response::HTTP_NOT_FOUND);
        }

        $notification->markAsRead();

        // Transform notification data
        $notificationData = is_array($notification->data) ? $notification->data : json_decode($notification->data, true) ?? [];
        $transformedNotification = [
            'id' => $notification->id,
            'type' => $notificationData['type'] ?? 'system',
            'title' => $notificationData['title'] ?? 'إشعار',
            'message' => $notificationData['message'] ?? '',
            'data' => $notificationData['data'] ?? [],
            'read_at' => $notification->read_at ? $notification->read_at->toIso8601String() : null,
            'created_at' => $notification->created_at->toIso8601String(),
        ];

        return response()->json([
            'message' => 'تم تحديد الإشعار كمقروء',
            'notification' => $transformedNotification,
        ]);
    }

    /**
     * Mark all notifications as read.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function markAllAsRead(Request $request)
    {
        $user = $request->user();
        $user->unreadNotifications->markAsRead();

        return response()->json([
            'message' => 'تم تحديد جميع الإشعارات كمقروءة',
        ]);
    }

    /**
     * Delete a notification.
     *
     * @param Request $request
     * @param string $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy(Request $request, string $id)
    {
        $user = $request->user();
        $notification = $user->notifications()->find($id);

        if (!$notification) {
            return response()->json([
                'message' => 'الإشعار غير موجود',
            ], Response::HTTP_NOT_FOUND);
        }

        $notification->delete();

        return response()->json([
            'message' => 'تم حذف الإشعار',
        ]);
    }
}

