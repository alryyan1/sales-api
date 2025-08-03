<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\WhatsAppScheduler;
use App\Http\Controllers\Api\WhatsAppController;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class TestWhatsAppScheduler extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'whatsapp:test-scheduler {phone? : Phone number to test with} {--force : Force send even if not scheduled time}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test WhatsApp scheduler functionality';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $phoneNumber = $this->argument('phone') ?? '249991961111';
        $force = $this->option('force');

        $this->info("Testing WhatsApp Scheduler for phone: {$phoneNumber}");
        $this->newLine();

        // Check if scheduler exists for this phone number
        $scheduler = WhatsAppScheduler::where('phone_number', $phoneNumber)->first();

        if (!$scheduler) {
            $this->warn("No scheduler found for phone number: {$phoneNumber}");
            $this->info("Creating a test scheduler...");
            
            $scheduler = WhatsAppScheduler::create([
                'name' => 'Test Scheduler',
                'phone_number' => $phoneNumber,
                'report_type' => 'daily_sales',
                'schedule_time' => Carbon::now()->format('H:i:s'),
                'is_active' => true,
                'days_of_week' => [Carbon::now()->dayOfWeek],
                'notes' => 'Test scheduler created by command',
            ]);
            
            $this->info("Test scheduler created with ID: {$scheduler->id}");
        } else {
            $this->info("Found existing scheduler: {$scheduler->name}");
        }

        $this->newLine();
        $this->info("Scheduler Details:");
        $this->table(
            ['Field', 'Value'],
            [
                ['Name', $scheduler->name],
                ['Phone', $scheduler->phone_number],
                ['Report Type', $scheduler->report_type_label],
                ['Schedule Time', $scheduler->formatted_schedule_time],
                ['Active', $scheduler->is_active ? 'Yes' : 'No'],
                ['Days of Week', $scheduler->formatted_days_of_week],
                ['Last Sent', $scheduler->last_sent_at ? $scheduler->last_sent_at->format('Y-m-d H:i:s') : 'Never'],
            ]
        );

        if (!$scheduler->is_active) {
            $this->warn("Scheduler is not active. Activating it for testing...");
            $scheduler->update(['is_active' => true]);
        }

        // Check if it's the right time to send
        $now = Carbon::now();
        $scheduledTime = Carbon::createFromFormat('H:i:s', $scheduler->schedule_time->format('H:i:s'));
        $currentTime = Carbon::createFromFormat('H:i:s', $now->format('H:i:s'));
        
        $this->newLine();
        $this->info("Time Check:");
        $this->table(
            ['Field', 'Value'],
            [
                ['Current Time', $currentTime->format('H:i:s')],
                ['Scheduled Time', $scheduledTime->format('H:i:s')],
                ['Current Day', $now->format('l')],
                ['Scheduled Days', $scheduler->formatted_days_of_week],
                ['Should Send', $this->shouldSendNow($scheduler, $now) ? 'Yes' : 'No'],
            ]
        );

        if (!$force && !$this->shouldSendNow($scheduler, $now)) {
            $this->warn("Not the scheduled time. Use --force to send anyway.");
            return 1;
        }

        // Generate test message based on report type
        $message = $this->generateTestMessage($scheduler->report_type);
        
        $this->newLine();
        $this->info("Sending test message...");
        $this->info("Message: {$message}");

        try {
            // Direct API call to avoid authentication issues
            $settings = config('app_settings');
            
            if (!$settings['whatsapp_enabled']) {
                $this->error("❌ WhatsApp integration is not enabled");
                return 1;
            }

            $apiUrl = $settings['whatsapp_api_url'];
            $apiToken = $settings['whatsapp_api_token'];
            $instanceId = $settings['whatsapp_instance_id'];

            if (empty($apiToken) || empty($instanceId)) {
                $this->error("❌ WhatsApp API configuration is incomplete");
                return 1;
            }

            // Format phone number (remove + if present)
            $phoneNumber = preg_replace('/[^0-9]/', '', $scheduler->phone_number);
            $chatId = $phoneNumber . '@c.us';
            
            $endpoint = "{$apiUrl}/instances/{$instanceId}/client/action/send-message";
            
            $payload = [
                'chatId' => $chatId,
                'message' => $message,
            ];

            $this->info("Sending to API endpoint: {$endpoint}");
            $this->info("Chat ID: {$chatId}");

            $response = \Illuminate\Support\Facades\Http::withHeaders([
                'Authorization' => 'Bearer ' . $apiToken,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])->post($endpoint, $payload);

            if ($response->successful()) {
                $responseData = $response->json();
                $this->info("✅ Message sent successfully!");
                
                // Update last sent time
                $scheduler->update(['last_sent_at' => $now]);
                
                $this->newLine();
                $this->info("Response Details:");
                $this->table(
                    ['Field', 'Value'],
                    [
                        ['Status Code', $response->status()],
                        ['Response', json_encode($responseData, JSON_PRETTY_PRINT)],
                    ]
                );
            } else {
                $this->error("❌ Failed to send message");
                $this->error("Status Code: " . $response->status());
                $this->error("Response: " . $response->body());
            }
        } catch (\Exception $e) {
            $this->error("❌ Exception occurred: " . $e->getMessage());
            Log::error('WhatsApp scheduler test failed', [
                'phone' => $scheduler->phone_number,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }

        return 0;
    }

    /**
     * Check if the scheduler should send now
     */
    private function shouldSendNow(WhatsAppScheduler $scheduler, Carbon $now): bool
    {
        // Check if today is in the scheduled days
        if (!in_array($now->dayOfWeek, $scheduler->days_of_week)) {
            return false;
        }

        // Check if it's the scheduled time (within 5 minutes)
        $scheduledTime = Carbon::createFromFormat('H:i:s', $scheduler->schedule_time->format('H:i:s'));
        $currentTime = Carbon::createFromFormat('H:i:s', $now->format('H:i:s'));
        
        $diffInMinutes = abs($currentTime->diffInMinutes($scheduledTime));
        
        return $diffInMinutes <= 5;
    }

    /**
     * Generate test message based on report type
     */
    private function generateTestMessage(string $reportType): string
    {
        $now = Carbon::now();
        
        switch ($reportType) {
            case 'daily_sales':
                return "📊 Daily Sales Report - {$now->format('Y-m-d')}\n\n" .
                       "🛒 Total Sales: 15\n" .
                       "💰 Total Amount: $2,450.00\n" .
                       "✅ Completed: 12\n" .
                       "⏳ Pending: 3\n\n" .
                       "This is a test message from your sales management system.";
                
            case 'inventory':
                return "📦 Inventory Report - {$now->format('Y-m-d')}\n\n" .
                       "📋 Total Products: 150\n" .
                       "⚠️ Low Stock Items: 8\n" .
                       "❌ Out of Stock: 2\n" .
                       "📈 Stock Value: $15,750.00\n\n" .
                       "This is a test message from your sales management system.";
                
            case 'profit_loss':
                return "📈 Profit & Loss Report - {$now->format('Y-m-d')}\n\n" .
                       "💰 Total Revenue: $3,200.00\n" .
                       "💸 Total Expenses: $1,850.00\n" .
                       "💵 Net Profit: $1,350.00\n" .
                       "📊 Profit Margin: 42.2%\n\n" .
                       "This is a test message from your sales management system.";
                
            default:
                return "🧪 Test Message - {$now->format('Y-m-d H:i:s')}\n\n" .
                       "This is a test message from your WhatsApp scheduler.\n" .
                       "Report Type: {$reportType}\n\n" .
                       "If you receive this, the WhatsApp integration is working correctly!";
        }
    }
} 