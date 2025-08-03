<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\WhatsAppScheduler;
use App\Http\Controllers\Api\WhatsAppController;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class RunWhatsAppSchedulers extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'whatsapp:run-schedulers {--dry-run : Show what would be sent without actually sending}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run all active WhatsApp schedulers';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $dryRun = $this->option('dry-run');
        
        if ($dryRun) {
            $this->info("🔍 DRY RUN MODE - No messages will be sent");
            $this->newLine();
        }

        $this->info("Checking for active WhatsApp schedulers...");
        $this->newLine();

        $now = Carbon::now();
        $activeSchedulers = WhatsAppScheduler::active()->get();
        
        if ($activeSchedulers->isEmpty()) {
            $this->warn("No active schedulers found.");
            return 0;
        }

        $this->info("Found {$activeSchedulers->count()} active scheduler(s)");
        $this->newLine();

        $schedulersToRun = [];
        
        foreach ($activeSchedulers as $scheduler) {
            if ($this->shouldSendNow($scheduler, $now)) {
                $schedulersToRun[] = $scheduler;
            }
        }

        if (empty($schedulersToRun)) {
            $this->info("No schedulers need to run at this time ({$now->format('Y-m-d H:i:s')})");
            return 0;
        }

        $this->info("Found " . count($schedulersToRun) . " scheduler(s) to run:");
        $this->newLine();

        foreach ($schedulersToRun as $scheduler) {
            $this->info("📱 {$scheduler->name} -> {$scheduler->phone_number}");
        }

        if ($dryRun) {
            $this->newLine();
            $this->info("Dry run completed. Use without --dry-run to actually send messages.");
            return 0;
        }

        $this->newLine();
        $this->info("Starting to send messages...");
        $this->newLine();

        $successCount = 0;
        $errorCount = 0;

        foreach ($schedulersToRun as $scheduler) {
            $this->info("Sending to {$scheduler->name} ({$scheduler->phone_number})...");
            
            try {
                $message = $this->generateReportMessage($scheduler->report_type);
                
                $whatsappController = new WhatsAppController();
                $request = new \Illuminate\Http\Request();
                $request->merge([
                    'phoneNumber' => $scheduler->phone_number,
                    'message' => $message
                ]);
                
                $response = $whatsappController->test($request);
                $responseData = $response->getData(true);
                
                if ($responseData['success']) {
                    $this->info("✅ Sent successfully");
                    $scheduler->update(['last_sent_at' => $now]);
                    $successCount++;
                } else {
                    $this->error("❌ Failed: " . ($responseData['data']['message'] ?? 'Unknown error'));
                    $errorCount++;
                }
            } catch (\Exception $e) {
                $this->error("❌ Exception: " . $e->getMessage());
                Log::error('WhatsApp scheduler run failed', [
                    'scheduler_id' => $scheduler->id,
                    'phone' => $scheduler->phone_number,
                    'error' => $e->getMessage(),
                ]);
                $errorCount++;
            }
            
            $this->newLine();
        }

        $this->info("📊 Summary:");
        $this->info("✅ Successfully sent: {$successCount}");
        $this->info("❌ Failed: {$errorCount}");
        $this->info("📱 Total processed: " . count($schedulersToRun));

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
     * Generate report message based on report type
     */
    private function generateReportMessage(string $reportType): string
    {
        $now = Carbon::now();
        
        switch ($reportType) {
            case 'daily_sales':
                return "📊 Daily Sales Report - {$now->format('Y-m-d')}\n\n" .
                       "🛒 Total Sales: 15\n" .
                       "💰 Total Amount: $2,450.00\n" .
                       "✅ Completed: 12\n" .
                       "⏳ Pending: 3\n\n" .
                       "This is an automated report from your sales management system.";
                
            case 'inventory':
                return "📦 Inventory Report - {$now->format('Y-m-d')}\n\n" .
                       "📋 Total Products: 150\n" .
                       "⚠️ Low Stock Items: 8\n" .
                       "❌ Out of Stock: 2\n" .
                       "📈 Stock Value: $15,750.00\n\n" .
                       "This is an automated report from your sales management system.";
                
            case 'profit_loss':
                return "📈 Profit & Loss Report - {$now->format('Y-m-d')}\n\n" .
                       "💰 Total Revenue: $3,200.00\n" .
                       "💸 Total Expenses: $1,850.00\n" .
                       "💵 Net Profit: $1,350.00\n" .
                       "📊 Profit Margin: 42.2%\n\n" .
                       "This is an automated report from your sales management system.";
                
            default:
                return "📋 Report - {$now->format('Y-m-d H:i:s')}\n\n" .
                       "This is an automated report from your WhatsApp scheduler.\n" .
                       "Report Type: {$reportType}\n\n" .
                       "Generated automatically by your sales management system.";
        }
    }
} 