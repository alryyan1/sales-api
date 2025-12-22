<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ShiftResource;
use App\Models\Shift;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * @OA\Tag(
 *     name="Shifts",
 *     description="User shift management for POS"
 * )
 */
class ShiftController extends Controller
{
    /**
     * @OA\Get(
     *     path="/api/shifts/current",
     *     summary="Get current shift",
     *     description="Retrieve the current active shift for the authenticated user.",
     *     operationId="getCurrentShift",
     *     tags={"Shifts"},
     *     security={{"bearerAuth": {}}},
     *     @OA\Response(
     *         response=200,
     *         description="Current shift details",
     *         @OA\JsonContent(
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="id", type="integer"),
     *                 @OA\Property(property="user_id", type="integer"),
     *                 @OA\Property(property="opened_at", type="string", format="date-time"),
     *                 @OA\Property(property="closed_at", type="string", format="date-time", nullable=true),
     *                 @OA\Property(property="is_open", type="boolean")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=204,
     *         description="No active shift found"
     *     )
     * )
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
     * @OA\Post(
     *     path="/api/shifts/open",
     *     summary="Open new shift",
     *     description="Open a new shift for the authenticated user if one isn't already open.",
     *     operationId="openShift",
     *     tags={"Shifts"},
     *     security={{"bearerAuth": {}}},
     *     @OA\Response(
     *         response=201,
     *         description="Shift opened",
     *         @OA\JsonContent(
     *             @OA\Property(property="data", type="object")
     *         )
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Shift already open"
     *     )
     * )
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
     * @OA\Post(
     *     path="/api/shifts/close",
     *     summary="Close current shift",
     *     description="Close the currently active shift for the authenticated user.",
     *     operationId="closeShift",
     *     tags={"Shifts"},
     *     security={{"bearerAuth": {}}},
     *     @OA\Response(
     *         response=200,
     *         description="Shift closed",
     *         @OA\JsonContent(
     *             @OA\Property(property="data", type="object")
     *         )
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="No open shift to close"
     *     )
     * )
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
