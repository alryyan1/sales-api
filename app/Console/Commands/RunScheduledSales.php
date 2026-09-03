<?php

namespace App\Console\Commands;

use App\Models\ScheduledSale;
use App\Services\ScheduledSaleExecutor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RunScheduledSales extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'scheduled-sales:run {--dry-run : Show what would run without actually running it}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create the real Sale (and send WhatsApp invoices) for any scheduled sales that are due';

    public function handle(ScheduledSaleExecutor $executor): int
    {
        $dryRun = $this->option('dry-run');

        // Stuck-row recovery: if a previous run crashed mid-execution, don't let a
        // 'processing' row become invisible to the pending query forever.
        $stuck = ScheduledSale::where('status', ScheduledSale::STATUS_PROCESSING)
            ->where('updated_at', '<', now()->subMinutes(15))
            ->get();

        foreach ($stuck as $row) {
            $row->update([
                'status' => ScheduledSale::STATUS_FAILED,
                'error_message' => 'Execution did not complete (previous run may have crashed).',
                'executed_at' => now(),
            ]);
            Log::warning("RunScheduledSales: recovered stuck row {$row->id} (was stuck in 'processing').");
        }

        $dueIds = ScheduledSale::where('status', ScheduledSale::STATUS_PENDING)
            ->where('scheduled_at', '<=', now())
            ->orderBy('scheduled_at')
            ->pluck('id');

        if ($dueIds->isEmpty()) {
            $this->info("No scheduled sales due at this time ({$this->now()}).");

            return self::SUCCESS;
        }

        $this->info("Found {$dueIds->count()} due scheduled sale(s).");

        if ($dryRun) {
            foreach (ScheduledSale::with('client:id,name')->whereIn('id', $dueIds)->get() as $row) {
                $this->info("  #{$row->id} -> {$row->client?->name} @ {$row->scheduled_at}");
            }
            $this->info('Dry run completed. Use without --dry-run to actually execute.');

            return self::SUCCESS;
        }

        $succeeded = 0;
        $failed = 0;

        foreach ($dueIds as $id) {
            try {
                $row = $this->claim($id);

                if (! $row) {
                    // Already claimed by a concurrent run.
                    continue;
                }

                $executor->execute($row);

                $row->refresh();
                if ($row->status === ScheduledSale::STATUS_COMPLETED) {
                    $succeeded++;
                    $this->info("  #{$row->id} completed.");
                } else {
                    $failed++;
                    $this->error("  #{$row->id} failed: {$row->error_message}");
                }
            } catch (\Throwable $e) {
                $failed++;
                Log::error("RunScheduledSales: unhandled exception for scheduled sale {$id}: ".$e->getMessage());
                $this->error("  #{$id} unhandled exception: {$e->getMessage()}");
            }
        }

        $this->info("Summary: {$succeeded} succeeded, {$failed} failed.");

        return self::SUCCESS;
    }

    private function claim(int $id): ?ScheduledSale
    {
        return DB::transaction(function () use ($id) {
            $row = ScheduledSale::where('id', $id)
                ->where('status', ScheduledSale::STATUS_PENDING)
                ->lockForUpdate()
                ->first();

            if (! $row) {
                return null;
            }

            $row->update(['status' => ScheduledSale::STATUS_PROCESSING]);

            return $row;
        });
    }

    private function now(): string
    {
        return now()->format('Y-m-d H:i:s');
    }
}
