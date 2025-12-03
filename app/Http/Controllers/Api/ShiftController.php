<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ShiftResource;
use App\Models\Shift;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ShiftController extends Controller
{
    /**
     * Get the current user's active (open) shift, if any.
     */
    public function current(Request $request)
    {
        $user = $request->user();

        $shift = Shift::with(['user', 'closedByUser'])
            ->where('user_id', $user->id)
            ->orderBy('id', 'desc')
            ->first();

        return $shift
            ? new ShiftResource($shift)
            : response()->json(null, 204);
    }

    /**
     * Open a new shift for the current user.
     */
    public function open(Request $request)
    {
        $user = $request->user();

        // Prevent opening multiple shifts
        $existing = Shift::where('user_id', $user->id)->open()->first();
        if ($existing) {
            return response()->json([
                'message' => 'هناك وردية مفتوحة بالفعل لهذا المستخدم.',
            ], 422);
        }

        $shift = Shift::create([
            'user_id' => $user->id,
            'opened_at' => now(),
        ]);

        $shift->load(['user', 'closedByUser']);

        return new ShiftResource($shift);
    }

    /**
     * Close the current user's active shift.
     */
    public function close(Request $request)
    {
        $user = $request->user();

        $shift = Shift::where('user_id', $user->id)
            ->orderBy('id', 'desc')
            ->first();

        if (! $shift) {
            return response()->json([
                'message' => 'لا توجد وردية مفتوحة لإغلاقها.',
            ], 422);
        }

        $shift->update([
            'closed_at' => now(),
            'closed_by_user_id' => $user->id,
        ]);

        $shift->load(['user', 'closedByUser']);

        return new ShiftResource($shift);
    }
}


