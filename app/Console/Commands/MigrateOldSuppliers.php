<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;   // <-- Import DB facade
use Illuminate\Support\Facades\Log;   // <-- Import Log facade
use App\Models\Supplier;             // <-- Import your NEW Supplier model
use Carbon\Carbon;                   // <-- For handling timestamps

class MigrateOldSuppliers extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'migrate:old-suppliers {--chunk=100 : The number of records to process at a time}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Migrate suppliers data from the old system database to the new one.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting supplier data migration...');

        // Use raw SQL to access the one_care database
        $totalOldSuppliers = DB::select('SELECT COUNT(*) as count FROM one_care.suppliers')[0]->count;
        if ($totalOldSuppliers === 0) {
            $this->warn('No suppliers found in the old database. Nothing to migrate.');
            return 0; // Command::SUCCESS equivalent for older Laravel versions
        }

        $bar = $this->output->createProgressBar($totalOldSuppliers);
        $bar->start();

        $migratedCount = 0;
        $skippedCount = 0;

        // Process in chunks to avoid memory issues with large datasets
        $offset = 0;
        $chunkSize = $this->option('chunk');
        
        while ($offset < $totalOldSuppliers) {
            $oldSuppliers = DB::select("SELECT * FROM one_care.suppliers ORDER BY id LIMIT {$chunkSize} OFFSET {$offset}");
            
            foreach ($oldSuppliers as $oldSupplier) {
                try {
                    // Check if a supplier with the same name or email already exists in the new system
                    // to avoid creating duplicates. Adjust this logic as needed.
                    $exists = Supplier::where('name', $oldSupplier->name)
                                      ->orWhere(function ($query) use ($oldSupplier) {
                                          // Only check email if it's not empty
                                          if (!empty($oldSupplier->email)) {
                                              $query->where('email', $oldSupplier->email);
                                          }
                                      })
                                      ->exists();

                    if ($exists) {
                        $this->line("\nSkipping existing supplier: {$oldSupplier->name}");
                        $skippedCount++;
                        $bar->advance();
                        continue; // Skip to the next supplier
                    }

                    // --- Create the new supplier in the default database connection ---
                    // Map old columns to new columns if names are different
                    // In this case, names seem to match, but we'll add 'contact_person' as null
                    Supplier::create([
                        'name' => $oldSupplier->name,
                        'phone' => $oldSupplier->phone,
                        'address' => $oldSupplier->address,
                        'email' => !empty($oldSupplier->email) ? $oldSupplier->email : null, // Handle empty email
                        'contact_person' => null, // Your new table has this, but old one doesn't. Set to null.
                        // Preserve original timestamps
                        'created_at' => Carbon::parse($oldSupplier->created_at),
                        'updated_at' => Carbon::parse($oldSupplier->updated_at),
                    ]);

                    $migratedCount++;
                } catch (\Exception $e) {
                    $this->error("\nFailed to migrate supplier with ID {$oldSupplier->id}: {$oldSupplier->name}");
                    $this->error("Error: " . $e->getMessage());
                    Log::error("Supplier Migration Failed: ID={$oldSupplier->id}, Name={$oldSupplier->name}, Error={$e->getMessage()}");
                }

                $bar->advance();
            }
            $offset += $chunkSize; // Increment offset for the next chunk
        }

        $bar->finish();

        $this->info("\n\nSupplier data migration complete!");
        $this->info("Successfully migrated: {$migratedCount} suppliers.");
        $this->warn("Skipped (already exist): {$skippedCount} suppliers.");

        return 0; // Command::SUCCESS
    }
}