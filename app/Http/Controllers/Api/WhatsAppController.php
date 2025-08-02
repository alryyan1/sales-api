<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WhatsAppScheduler;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

class WhatsAppController extends Controller
{
    /**
     * Get all WhatsApp schedulers
     */
    public function getSchedulers(): JsonResponse
    {
        try {
            $schedulers = WhatsAppScheduler::orderBy('created_at', 'desc')->get();
            
            // Transform the data to match frontend expectations
            $transformedSchedulers = $schedulers->map(function ($scheduler) {
                return [
                    'id' => $scheduler->id,
                    'name' => $scheduler->name,
                    'phone_numbers' => [$scheduler->phone_number], // Convert single phone to array
                    'report_type' => $scheduler->report_type,
                    'schedule_type' => $this->determineScheduleType($scheduler->days_of_week),
                    'schedule_time' => $scheduler->schedule_time,
                    'schedule_days' => $scheduler->days_of_week, // Convert days_of_week to schedule_days
                    'is_active' => $scheduler->is_active,
                    'notes' => $scheduler->notes,
                    'created_at' => $scheduler->created_at,
                    'updated_at' => $scheduler->updated_at,
                ];
            });
            
            return response()->json([
                'data' => $transformedSchedulers,
                'message' => 'WhatsApp schedulers retrieved successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching WhatsApp schedulers: ' . $e->getMessage());
            return response()->json([
                'message' => 'Failed to fetch WhatsApp schedulers',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Determine schedule type based on days of week
     */
    private function determineScheduleType($daysOfWeek): string
    {
        if (empty($daysOfWeek)) {
            return 'daily';
        }
        
        if (count($daysOfWeek) === 7) {
            return 'daily';
        }
        
        if (count($daysOfWeek) === 5 && in_array(1, $daysOfWeek) && in_array(2, $daysOfWeek) && 
            in_array(3, $daysOfWeek) && in_array(4, $daysOfWeek) && in_array(5, $daysOfWeek)) {
            return 'weekly';
        }
        
        return 'weekly';
    }

    /**
     * Create a new WhatsApp scheduler
     */
    public function createScheduler(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:255',
                'phone_numbers' => 'required|array|min:1',
                'phone_numbers.*' => 'required|string|max:20',
                'report_type' => 'required|string|in:daily_sales,inventory,profit_loss',
                'schedule_time' => 'required|string',
                'schedule_type' => 'required|string|in:daily,weekly,monthly',
                'schedule_days' => 'array',
                'schedule_days.*' => 'integer|between:0,6',
                'is_active' => 'boolean',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Convert phone_numbers array to single phone_number (take first one for now)
            $phoneNumber = $request->phone_numbers[0] ?? '';
            
            // Convert schedule_days to days_of_week format
            $daysOfWeek = $request->schedule_days ?? [];

            $scheduler = WhatsAppScheduler::create([
                'name' => $request->name,
                'phone_number' => $phoneNumber,
                'report_type' => $request->report_type,
                'schedule_time' => $request->schedule_time,
                'is_active' => $request->boolean('is_active', true),
                'days_of_week' => $daysOfWeek,
                'notes' => $request->notes,
            ]);

            // Transform the data to match frontend expectations
            $transformedScheduler = [
                'id' => $scheduler->id,
                'name' => $scheduler->name,
                'phone_numbers' => [$scheduler->phone_number],
                'report_type' => $scheduler->report_type,
                'schedule_type' => $this->determineScheduleType($scheduler->days_of_week),
                'schedule_time' => $scheduler->schedule_time,
                'schedule_days' => $scheduler->days_of_week,
                'is_active' => $scheduler->is_active,
                'notes' => $scheduler->notes,
                'created_at' => $scheduler->created_at,
                'updated_at' => $scheduler->updated_at,
            ];

            return response()->json([
                'data' => $transformedScheduler,
                'message' => 'WhatsApp scheduler created successfully'
            ], 201);
        } catch (\Exception $e) {
            Log::error('Error creating WhatsApp scheduler: ' . $e->getMessage());
            return response()->json([
                'message' => 'Failed to create WhatsApp scheduler',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update a WhatsApp scheduler
     */
    public function updateScheduler(Request $request, $id): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'name' => 'sometimes|required|string|max:255',
                'phone_numbers' => 'sometimes|required|array|min:1',
                'phone_numbers.*' => 'required|string|max:20',
                'report_type' => 'sometimes|required|string|in:daily_sales,inventory,profit_loss',
                'schedule_time' => 'sometimes|required|string',
                'schedule_type' => 'sometimes|required|string|in:daily,weekly,monthly',
                'schedule_days' => 'sometimes|array',
                'schedule_days.*' => 'integer|between:0,6',
                'is_active' => 'sometimes|boolean',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $scheduler = WhatsAppScheduler::findOrFail($id);
            
            // Convert phone_numbers array to single phone_number if provided
            $phoneNumber = $scheduler->phone_number;
            if ($request->has('phone_numbers') && !empty($request->phone_numbers)) {
                $phoneNumber = $request->phone_numbers[0];
            }
            
            // Convert schedule_days to days_of_week format if provided
            $daysOfWeek = $scheduler->days_of_week;
            if ($request->has('schedule_days')) {
                $daysOfWeek = $request->schedule_days;
            }

            $scheduler->update([
                'name' => $request->name ?? $scheduler->name,
                'phone_number' => $phoneNumber,
                'report_type' => $request->report_type ?? $scheduler->report_type,
                'schedule_time' => $request->schedule_time ?? $scheduler->schedule_time,
                'is_active' => $request->has('is_active') ? $request->boolean('is_active') : $scheduler->is_active,
                'days_of_week' => $daysOfWeek,
                'notes' => $request->notes ?? $scheduler->notes,
            ]);

            $updatedScheduler = $scheduler->fresh();
            
            // Transform the data to match frontend expectations
            $transformedScheduler = [
                'id' => $updatedScheduler->id,
                'name' => $updatedScheduler->name,
                'phone_numbers' => [$updatedScheduler->phone_number],
                'report_type' => $updatedScheduler->report_type,
                'schedule_type' => $this->determineScheduleType($updatedScheduler->days_of_week),
                'schedule_time' => $updatedScheduler->schedule_time,
                'schedule_days' => $updatedScheduler->days_of_week,
                'is_active' => $updatedScheduler->is_active,
                'notes' => $updatedScheduler->notes,
                'created_at' => $updatedScheduler->created_at,
                'updated_at' => $updatedScheduler->updated_at,
            ];

            return response()->json([
                'data' => $transformedScheduler,
                'message' => 'WhatsApp scheduler updated successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Error updating WhatsApp scheduler: ' . $e->getMessage());
            return response()->json([
                'message' => 'Failed to update WhatsApp scheduler',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete a WhatsApp scheduler
     */
    public function deleteScheduler($id): JsonResponse
    {
        try {
            $scheduler = WhatsAppScheduler::findOrFail($id);
            $scheduler->delete();
            
            return response()->json([
                'message' => 'WhatsApp scheduler deleted successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Error deleting WhatsApp scheduler: ' . $e->getMessage());
            return response()->json([
                'message' => 'Failed to delete WhatsApp scheduler',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Toggle scheduler active status
     */
    public function toggleScheduler(Request $request, $id): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'is_active' => 'required|boolean',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $scheduler = WhatsAppScheduler::findOrFail($id);
            $scheduler->update([
                'is_active' => $request->boolean('is_active'),
            ]);

            return response()->json([
                'data' => $scheduler->fresh(),
                'message' => 'WhatsApp scheduler status updated successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Error toggling WhatsApp scheduler: ' . $e->getMessage());
            return response()->json([
                'message' => 'Failed to toggle WhatsApp scheduler',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Test WhatsApp scheduler
     */
    public function testScheduler(Request $request): JsonResponse
    {
        try {
            // Handle both old format (phone_number) and new format (phone_numbers)
            $phoneNumbers = [];
            if ($request->has('phone_numbers')) {
                $phoneNumbers = $request->phone_numbers;
            } elseif ($request->has('phone_number')) {
                $phoneNumbers = [$request->phone_number];
            }

            $validator = Validator::make($request->all(), [
                'phone_number' => 'required_without:phone_numbers|string|max:20',
                'phone_numbers' => 'required_without:phone_number|array|min:1',
                'phone_numbers.*' => 'required|string|max:20',
                'report_type' => 'required|string|in:daily_sales,inventory,profit_loss',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            // TODO: Implement actual WhatsApp sending logic
            $phoneNumber = $phoneNumbers[0];
            Log::info('WhatsApp test message would be sent to: ' . $phoneNumber . ' with report type: ' . $request->report_type);

            return response()->json([
                'message' => 'Test message sent successfully',
                'data' => [
                    'phone_numbers' => $phoneNumbers,
                    'report_type' => $request->report_type,
                    'sent_at' => now(),
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Error testing WhatsApp scheduler: ' . $e->getMessage());
            return response()->json([
                'message' => 'Failed to send test message',
                'error' => $e->getMessage()
            ], 500);
        }
    }
} 