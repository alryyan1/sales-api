<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WhatsAppScheduler;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class WhatsAppSchedulerController extends Controller
{
    /**
     * Get all WhatsApp schedulers
     */
    public function index()
    {
        $this->checkAuthorization('manage-whatsapp-schedulers');

        try {
            $schedulers = WhatsAppScheduler::orderBy('created_at', 'desc')->get();

            return response()->json([
                'success' => true,
                'data' => $schedulers
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching WhatsApp schedulers', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch WhatsApp schedulers',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create a new WhatsApp scheduler
     */
    public function store(Request $request)
    {
        $this->checkAuthorization('manage-whatsapp-schedulers');

        $request->validate([
            'name' => 'required|string|max:255',
            'phone_number' => 'required|string|max:20',
            'report_type' => ['required', Rule::in(['daily_sales', 'inventory', 'profit_loss'])],
            'schedule_time' => 'required|date_format:H:i',
            'is_active' => 'boolean',
            'days_of_week' => 'nullable|array',
            'days_of_week.*' => 'integer|min:0|max:6',
            'notes' => 'nullable|string|max:1000',
        ]);

        try {
            $scheduler = WhatsAppScheduler::create([
                'name' => $request->name,
                'phone_number' => $request->phone_number,
                'report_type' => $request->report_type,
                'schedule_time' => $request->schedule_time,
                'is_active' => $request->boolean('is_active', true),
                'days_of_week' => $request->days_of_week ?? [],
                'notes' => $request->notes,
            ]);

            Log::info('WhatsApp scheduler created', [
                'scheduler_id' => $scheduler->id,
                'name' => $scheduler->name,
                'created_by' => Auth::id()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'WhatsApp scheduler created successfully',
                'data' => $scheduler
            ], 201);
        } catch (\Exception $e) {
            Log::error('Error creating WhatsApp scheduler', [
                'error' => $e->getMessage(),
                'data' => $request->all()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to create WhatsApp scheduler',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get a specific WhatsApp scheduler
     */
    public function show($id)
    {
        $this->checkAuthorization('manage-whatsapp-schedulers');

        try {
            $scheduler = WhatsAppScheduler::findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => $scheduler
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching WhatsApp scheduler', [
                'error' => $e->getMessage(),
                'scheduler_id' => $id
            ]);

            return response()->json([
                'success' => false,
                'message' => 'WhatsApp scheduler not found',
                'error' => $e->getMessage()
            ], 404);
        }
    }

    /**
     * Update a WhatsApp scheduler
     */
    public function update(Request $request, $id)
    {
        $this->checkAuthorization('manage-whatsapp-schedulers');

        $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'phone_number' => 'sometimes|required|string|max:20',
            'report_type' => ['sometimes', 'required', Rule::in(['daily_sales', 'inventory', 'profit_loss'])],
            'schedule_time' => 'sometimes|required|date_format:H:i',
            'is_active' => 'sometimes|boolean',
            'days_of_week' => 'sometimes|nullable|array',
            'days_of_week.*' => 'integer|min:0|max:6',
            'notes' => 'sometimes|nullable|string|max:1000',
        ]);

        try {
            $scheduler = WhatsAppScheduler::findOrFail($id);

            $scheduler->update($request->only([
                'name', 'phone_number', 'report_type', 'schedule_time',
                'is_active', 'days_of_week', 'notes'
            ]));

            Log::info('WhatsApp scheduler updated', [
                'scheduler_id' => $scheduler->id,
                'name' => $scheduler->name,
                'updated_by' => Auth::id()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'WhatsApp scheduler updated successfully',
                'data' => $scheduler
            ]);
        } catch (\Exception $e) {
            Log::error('Error updating WhatsApp scheduler', [
                'error' => $e->getMessage(),
                'scheduler_id' => $id,
                'data' => $request->all()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to update WhatsApp scheduler',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete a WhatsApp scheduler
     */
    public function destroy($id)
    {
        $this->checkAuthorization('manage-whatsapp-schedulers');

        try {
            $scheduler = WhatsAppScheduler::findOrFail($id);
            $schedulerName = $scheduler->name;
            
            $scheduler->delete();

            Log::info('WhatsApp scheduler deleted', [
                'scheduler_id' => $id,
                'name' => $schedulerName,
                'deleted_by' => Auth::id()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'WhatsApp scheduler deleted successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Error deleting WhatsApp scheduler', [
                'error' => $e->getMessage(),
                'scheduler_id' => $id
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to delete WhatsApp scheduler',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Toggle scheduler active status
     */
    public function toggle($id)
    {
        $this->checkAuthorization('manage-whatsapp-schedulers');

        try {
            $scheduler = WhatsAppScheduler::findOrFail($id);
            $scheduler->is_active = !$scheduler->is_active;
            $scheduler->save();

            Log::info('WhatsApp scheduler status toggled', [
                'scheduler_id' => $scheduler->id,
                'name' => $scheduler->name,
                'is_active' => $scheduler->is_active,
                'toggled_by' => Auth::id()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'WhatsApp scheduler status updated successfully',
                'data' => $scheduler
            ]);
        } catch (\Exception $e) {
            Log::error('Error toggling WhatsApp scheduler status', [
                'error' => $e->getMessage(),
                'scheduler_id' => $id
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to update WhatsApp scheduler status',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get scheduler options (report types, days of week)
     */
    public function options()
    {
        $this->checkAuthorization('manage-whatsapp-schedulers');

        try {
            return response()->json([
                'success' => true,
                'data' => [
                    'report_types' => WhatsAppScheduler::getReportTypes(),
                    'days_of_week' => WhatsAppScheduler::getDaysOfWeek(),
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching WhatsApp scheduler options', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch scheduler options',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Helper to authorize based on permission string
     */
    private function checkAuthorization(string $permission): void
    {
        if (Auth::user() && !Auth::user()->can($permission)) {
            abort(403, 'This action is unauthorized.');
        } elseif (!Auth::user()) {
            abort(401, 'Unauthenticated.');
        }
    }
} 