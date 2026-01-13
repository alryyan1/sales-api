<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Client;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Unit;
use App\Models\Category;
use App\Models\Supplier;
use App\Models\User;
use Carbon\Carbon;

class MigrateMirganiDatabase extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'migrate:mirgani 
                            {--table= : Migrate specific table only} 
                            {--chunk=100 : Records per chunk} 
                            {--dry-run : Preview without migrating} 
                            {--skip-inspection : Skip table structure inspection}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Migrate data from mirgani database to current system';

    /**
     * ID mapping caches
     */
    private $supplierIdMap = [];
    private $userIdMap = [];
    private $productIdMap = [];
    private $categoryIdMap = [];
    private $unitIdMap = [];
    private $clientIdMap = [];
    private $purchaseIdMap = [];
    private $saleIdMap = [];

    /**
     * Migration statistics
     */
    private $stats = [
        'migrated' => 0,
        'skipped' => 0,
        'errors' => 0,
    ];

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting Mirgani database migration...');

        // Check connection
        try {
            DB::connection('mirgani')->getPdo();
        } catch (\Exception $e) {
            $this->error('Cannot connect to mirgani database: ' . $e->getMessage());
            $this->info('Please check your .env file for DB_HOST_MIRGANI, DB_DATABASE_MIRGANI, etc.');
            return 1;
        }

        $isDryRun = $this->option('dry-run');
        if ($isDryRun) {
            $this->warn('DRY RUN MODE - No data will be actually migrated');
        }

        $skipInspection = $this->option('skip-inspection');
        $specificTable = $this->option('table');

        // Define migration order
        $migrationOrder = [
            'units' => ['old_table' => 'pharmacy_types', 'new_table' => 'units', 'method' => 'migrateUnits'],
            'categories' => ['old_table' => 'sections', 'new_table' => 'categories', 'method' => 'migrateCategories'],
            'suppliers' => ['old_table' => 'suppliers', 'new_table' => 'suppliers', 'method' => 'migrateSuppliers'],
            'users' => ['old_table' => 'users', 'new_table' => 'users', 'method' => 'migrateUsers'],
            'products' => ['old_table' => 'items', 'new_table' => 'products', 'method' => 'migrateProducts'],
            'clients' => ['old_table' => 'clients', 'new_table' => 'clients', 'method' => 'migrateClients'],
            'purchases' => ['old_table' => 'deposits', 'new_table' => 'purchases', 'method' => 'migratePurchases'],
            'purchase_items' => ['old_table' => 'deposit_items', 'new_table' => 'purchase_items', 'method' => 'migratePurchaseItems'],
            'sales' => ['old_table' => 'deducts', 'new_table' => 'sales', 'method' => 'migrateSales'],
            'sale_items' => ['old_table' => 'deducted_items', 'new_table' => 'sales_items', 'method' => 'migrateSaleItems'],
        ];

        // Filter to specific table if requested
        if ($specificTable) {
            if (!isset($migrationOrder[$specificTable])) {
                $this->error("Unknown table: {$specificTable}");
                $this->info('Available tables: ' . implode(', ', array_keys($migrationOrder)));
                return 1;
            }
            $migrationOrder = [$specificTable => $migrationOrder[$specificTable]];
        }

        // Inspect table structures if not skipped
        if (!$skipInspection && !$isDryRun) {
            $this->info("\nInspecting table structures...");
            foreach ($migrationOrder as $key => $config) {
                $this->inspectTableStructure($config['old_table']);
            }
            
            if (!$this->confirm('Proceed with migration?', true)) {
                $this->info('Migration cancelled.');
                return 0;
            }
        }

        // Execute migrations in order
        foreach ($migrationOrder as $key => $config) {
            $this->info("\n" . str_repeat('=', 60));
            $this->info("Migrating {$config['old_table']} → {$config['new_table']}");
            $this->info(str_repeat('=', 60));
            
            try {
                $this->{$config['method']}($isDryRun);
            } catch (\Exception $e) {
                $this->error("Error migrating {$key}: " . $e->getMessage());
                Log::error("Mirgani Migration Error - {$key}: " . $e->getMessage());
                $this->stats['errors']++;
            }
        }

        // Summary
        $this->displaySummary($isDryRun);

        return 0;
    }

    /**
     * Inspect table structure
     */
    private function inspectTableStructure($tableName)
    {
        try {
            $columns = DB::connection('mirgani')
                ->select("SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE, COLUMN_DEFAULT 
                          FROM INFORMATION_SCHEMA.COLUMNS 
                          WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? 
                          ORDER BY ORDINAL_POSITION", 
                          [config('database.connections.mirgani.database'), $tableName]);
            
            if (empty($columns)) {
                $this->warn("  Table '{$tableName}' not found or has no columns");
                return;
            }

            $this->info("  Table: {$tableName}");
            $this->table(
                ['Column', 'Type', 'Nullable', 'Default'],
                array_map(function($col) {
                    return [
                        $col->COLUMN_NAME,
                        $col->DATA_TYPE,
                        $col->IS_NULLABLE === 'YES' ? 'YES' : 'NO',
                        $col->COLUMN_DEFAULT ?? 'NULL'
                    ];
                }, $columns)
            );
        } catch (\Exception $e) {
            $this->warn("  Could not inspect table '{$tableName}': " . $e->getMessage());
        }
    }

    /**
     * Migrate Units (pharmacy_types → units)
     */
    private function migrateUnits($isDryRun)
    {
        $oldTable = 'pharmacy_types';
        $total = DB::connection('mirgani')->table($oldTable)->count();
        
        if ($total === 0) {
            $this->warn("No records found in {$oldTable}");
            return;
        }

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $migrated = 0;
        $skipped = 0;

        DB::connection('mirgani')->table($oldTable)->orderBy('id')->chunk($this->option('chunk'), function ($oldUnits) use (&$migrated, &$skipped, $isDryRun, $bar) {
            foreach ($oldUnits as $oldUnit) {
                try {
                    // Check if exists by name
                    $exists = Unit::where('name', $oldUnit->name ?? '')->exists();
                    if ($exists) {
                        $skipped++;
                        $bar->advance();
                        continue;
                    }

                    if (!$isDryRun) {
                        // Determine type - try to infer from name or default to 'sellable'
                        $type = 'sellable';
                        $name = $oldUnit->name ?? 'Piece';
                        
                        // Common stocking unit names
                        $stockingUnits = ['box', 'carton', 'pack', 'case'];
                        foreach ($stockingUnits as $stocking) {
                            if (stripos($name, $stocking) !== false) {
                                $type = 'stocking';
                                break;
                            }
                        }

                        $unit = Unit::create([
                            'name' => $name,
                            'type' => $type,
                            'description' => $oldUnit->description ?? null,
                            'is_active' => isset($oldUnit->is_active) ? (bool)$oldUnit->is_active : true,
                            'created_at' => isset($oldUnit->created_at) ? Carbon::parse($oldUnit->created_at) : now(),
                            'updated_at' => isset($oldUnit->updated_at) ? Carbon::parse($oldUnit->updated_at) : now(),
                        ]);

                        $this->unitIdMap[$oldUnit->id] = $unit->id;
                    }

                    $migrated++;
                } catch (\Exception $e) {
                    $this->error("\nFailed to migrate unit ID {$oldUnit->id}: " . $e->getMessage());
                    Log::error("Unit Migration Failed: ID={$oldUnit->id}, Error={$e->getMessage()}");
                }
                $bar->advance();
            }
        });

        $bar->finish();
        $this->info("\nMigrated: {$migrated}, Skipped: {$skipped}");
        $this->stats['migrated'] += $migrated;
        $this->stats['skipped'] += $skipped;
    }

    /**
     * Migrate Categories (sections → categories)
     */
    private function migrateCategories($isDryRun)
    {
        $oldTable = 'sections';
        $total = DB::connection('mirgani')->table($oldTable)->count();
        
        if ($total === 0) {
            $this->warn("No records found in {$oldTable}");
            return;
        }

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $migrated = 0;
        $skipped = 0;

        DB::connection('mirgani')->table($oldTable)->orderBy('id')->chunk($this->option('chunk'), function ($oldCategories) use (&$migrated, &$skipped, $isDryRun, $bar) {
            foreach ($oldCategories as $oldCat) {
                try {
                    // Check if exists by name
                    $exists = Category::where('name', $oldCat->name ?? '')->exists();
                    if ($exists) {
                        $skipped++;
                        $bar->advance();
                        continue;
                    }

                    if (!$isDryRun) {
                        $category = Category::create([
                            'name' => $oldCat->name ?? 'Unnamed Category',
                            'description' => $oldCat->description ?? null,
                            'parent_id' => isset($oldCat->parent_id) && $oldCat->parent_id ? ($this->categoryIdMap[$oldCat->parent_id] ?? null) : null,
                            'created_at' => isset($oldCat->created_at) ? Carbon::parse($oldCat->created_at) : now(),
                            'updated_at' => isset($oldCat->updated_at) ? Carbon::parse($oldCat->updated_at) : now(),
                        ]);

                        $this->categoryIdMap[$oldCat->id] = $category->id;
                    }

                    $migrated++;
                } catch (\Exception $e) {
                    $this->error("\nFailed to migrate category ID {$oldCat->id}: " . $e->getMessage());
                    Log::error("Category Migration Failed: ID={$oldCat->id}, Error={$e->getMessage()}");
                }
                $bar->advance();
            }
        });

        $bar->finish();
        $this->info("\nMigrated: {$migrated}, Skipped: {$skipped}");
        $this->stats['migrated'] += $migrated;
        $this->stats['skipped'] += $skipped;
    }

    /**
     * Migrate Suppliers
     */
    private function migrateSuppliers($isDryRun)
    {
        $oldTable = 'suppliers';
        $total = DB::connection('mirgani')->table($oldTable)->count();
        
        if ($total === 0) {
            $this->warn("No records found in {$oldTable}");
            return;
        }

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $migrated = 0;
        $skipped = 0;

        DB::connection('mirgani')->table($oldTable)->orderBy('id')->chunk($this->option('chunk'), function ($oldSuppliers) use (&$migrated, &$skipped, $isDryRun, $bar) {
            foreach ($oldSuppliers as $oldSupplier) {
                try {
                    // Check if exists by name or email
                    $exists = Supplier::where('name', $oldSupplier->name ?? '')
                        ->orWhere(function($q) use ($oldSupplier) {
                            if (!empty($oldSupplier->email)) {
                                $q->where('email', $oldSupplier->email);
                            }
                        })
                        ->exists();
                    
                    if ($exists) {
                        // Still map the ID if found
                        $found = Supplier::where('name', $oldSupplier->name ?? '')->first();
                        if ($found) {
                            $this->supplierIdMap[$oldSupplier->id] = $found->id;
                        }
                        $skipped++;
                        $bar->advance();
                        continue;
                    }

                    if (!$isDryRun) {
                        $supplier = Supplier::create([
                            'name' => $oldSupplier->name ?? 'Unnamed Supplier',
                            'contact_person' => $oldSupplier->contact_person ?? null,
                            'email' => !empty($oldSupplier->email) ? $oldSupplier->email : null,
                            'phone' => $oldSupplier->phone ?? null,
                            'address' => $oldSupplier->address ?? null,
                            'created_at' => isset($oldSupplier->created_at) ? Carbon::parse($oldSupplier->created_at) : now(),
                            'updated_at' => isset($oldSupplier->updated_at) ? Carbon::parse($oldSupplier->updated_at) : now(),
                        ]);

                        $this->supplierIdMap[$oldSupplier->id] = $supplier->id;
                    }

                    $migrated++;
                } catch (\Exception $e) {
                    $this->error("\nFailed to migrate supplier ID {$oldSupplier->id}: " . $e->getMessage());
                    Log::error("Supplier Migration Failed: ID={$oldSupplier->id}, Error={$e->getMessage()}");
                }
                $bar->advance();
            }
        });

        $bar->finish();
        $this->info("\nMigrated: {$migrated}, Skipped: {$skipped}");
        $this->stats['migrated'] += $migrated;
        $this->stats['skipped'] += $skipped;
    }

    /**
     * Migrate Users
     */
    private function migrateUsers($isDryRun)
    {
        $oldTable = 'users';
        $total = DB::connection('mirgani')->table($oldTable)->count();
        
        if ($total === 0) {
            $this->warn("No records found in {$oldTable}");
            return;
        }

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $migrated = 0;
        $skipped = 0;

        DB::connection('mirgani')->table($oldTable)->orderBy('id')->chunk($this->option('chunk'), function ($oldUsers) use (&$migrated, &$skipped, $isDryRun, $bar) {
            foreach ($oldUsers as $oldUser) {
                try {
                    // Check if exists by username or name
                    $exists = User::where('username', $oldUser->username ?? '')
                        ->orWhere('name', $oldUser->name ?? '')
                        ->exists();
                    
                    if ($exists) {
                        $found = User::where('username', $oldUser->username ?? '')
                            ->orWhere('name', $oldUser->name ?? '')
                            ->first();
                        if ($found) {
                            $this->userIdMap[$oldUser->id] = $found->id;
                        }
                        $skipped++;
                        $bar->advance();
                        continue;
                    }

                    if (!$isDryRun) {
                        $user = User::create([
                            'name' => $oldUser->name ?? 'Unnamed User',
                            'username' => $oldUser->username ?? ($oldUser->name ?? 'user_' . $oldUser->id),
                            'password' => $oldUser->password ?? bcrypt('password'), // Default password
                            'created_at' => isset($oldUser->created_at) ? Carbon::parse($oldUser->created_at) : now(),
                            'updated_at' => isset($oldUser->updated_at) ? Carbon::parse($oldUser->updated_at) : now(),
                        ]);

                        $this->userIdMap[$oldUser->id] = $user->id;
                    }

                    $migrated++;
                } catch (\Exception $e) {
                    $this->error("\nFailed to migrate user ID {$oldUser->id}: " . $e->getMessage());
                    Log::error("User Migration Failed: ID={$oldUser->id}, Error={$e->getMessage()}");
                }
                $bar->advance();
            }
        });

        $bar->finish();
        $this->info("\nMigrated: {$migrated}, Skipped: {$skipped}");
        $this->stats['migrated'] += $migrated;
        $this->stats['skipped'] += $skipped;
    }

    /**
     * Migrate Products (items → products)
     */
    private function migrateProducts($isDryRun)
    {
        $oldTable = 'items';
        $total = DB::connection('mirgani')->table($oldTable)->count();
        
        if ($total === 0) {
            $this->warn("No records found in {$oldTable}");
            return;
        }

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $migrated = 0;
        $skipped = 0;

        DB::connection('mirgani')->table($oldTable)->orderBy('id')->chunk($this->option('chunk'), function ($oldItems) use (&$migrated, &$skipped, $isDryRun, $bar) {
            foreach ($oldItems as $oldItem) {
                try {
                    $productName = $oldItem->market_name ?? $oldItem->name ?? null;
                    if (empty($productName)) {
                        $skipped++;
                        $bar->advance();
                        continue;
                    }

                    // Check if exists by name or SKU
                    $exists = Product::where('name', $productName)
                        ->orWhere(function($q) use ($oldItem) {
                            if (!empty($oldItem->barcode)) {
                                $q->where('sku', $oldItem->barcode);
                            }
                        })
                        ->exists();
                    
                    if ($exists) {
                        $found = Product::where('name', $productName)->first();
                        if ($found) {
                            $this->productIdMap[$oldItem->id] = $found->id;
                        }
                        $skipped++;
                        $bar->advance();
                        continue;
                    }

                    // Map category
                    $categoryId = null;
                    if (!empty($oldItem->section_id) && isset($this->categoryIdMap[$oldItem->section_id])) {
                        $categoryId = $this->categoryIdMap[$oldItem->section_id];
                    }

                    // Map units
                    $sellableUnitId = null;
                    $stockingUnitId = null;
                    $unitsPerStockingUnit = 1;

                    if (!empty($oldItem->pharmacy_type_id) && isset($this->unitIdMap[$oldItem->pharmacy_type_id])) {
                        $sellableUnitId = $this->unitIdMap[$oldItem->pharmacy_type_id];
                    }

                    // Default to Box for stocking unit if not specified
                    $stockingUnit = Unit::where('name', 'Box')->where('type', 'stocking')->first();
                    if ($stockingUnit) {
                        $stockingUnitId = $stockingUnit->id;
                    }

                    // Calculate units per stocking unit from pack_size
                    if (!empty($oldItem->pack_size) && is_numeric($oldItem->pack_size) && (int)$oldItem->pack_size > 0) {
                        $unitsPerStockingUnit = (int)$oldItem->pack_size;
                    }

                    if (!$isDryRun) {
                        $product = Product::create([
                            'name' => $productName,
                            'scientific_name' => $oldItem->scientific_name ?? $oldItem->sc_name ?? null,
                            'sku' => !empty($oldItem->barcode) ? $oldItem->barcode : null,
                            'description' => $this->buildProductDescription($oldItem),
                            'category_id' => $categoryId,
                            'stocking_unit_id' => $stockingUnitId,
                            'sellable_unit_id' => $sellableUnitId,
                            'units_per_stocking_unit' => $unitsPerStockingUnit,
                            'stock_quantity' => (int)($oldItem->initial_balance ?? $oldItem->stock_quantity ?? 0),
                            'stock_alert_level' => !empty($oldItem->require_amount) ? (int)$oldItem->require_amount : 10,
                            'has_expiry_date' => isset($oldItem->has_expiry_date) ? (bool)$oldItem->has_expiry_date : false,
                            'created_at' => isset($oldItem->created_at) ? Carbon::parse($oldItem->created_at) : now(),
                            'updated_at' => isset($oldItem->updated_at) ? Carbon::parse($oldItem->updated_at) : now(),
                        ]);

                        $this->productIdMap[$oldItem->id] = $product->id;
                    }

                    $migrated++;
                } catch (\Exception $e) {
                    $this->error("\nFailed to migrate product ID {$oldItem->id}: " . $e->getMessage());
                    Log::error("Product Migration Failed: ID={$oldItem->id}, Error={$e->getMessage()}");
                }
                $bar->advance();
            }
        });

        $bar->finish();
        $this->info("\nMigrated: {$migrated}, Skipped: {$skipped}");
        $this->stats['migrated'] += $migrated;
        $this->stats['skipped'] += $skipped;
    }

    /**
     * Build product description from multiple fields
     */
    private function buildProductDescription($oldItem)
    {
        $parts = [];
        
        if (!empty($oldItem->scientific_name)) {
            $parts[] = "Scientific Name: " . $oldItem->scientific_name;
        } elseif (!empty($oldItem->sc_name)) {
            $parts[] = "Scientific Name: " . $oldItem->sc_name;
        }

        if (!empty($oldItem->active1)) {
            $parts[] = "Active 1: " . $oldItem->active1;
        }
        if (!empty($oldItem->active2)) {
            $parts[] = "Active 2: " . $oldItem->active2;
        }
        if (!empty($oldItem->active3)) {
            $parts[] = "Active 3: " . $oldItem->active3;
        }
        if (!empty($oldItem->batch)) {
            $parts[] = "Batch: " . $oldItem->batch;
        }
        if (!empty($oldItem->pack_size)) {
            $parts[] = "Pack Size: " . $oldItem->pack_size;
        }

        return !empty($parts) ? implode("\n", $parts) : null;
    }

    /**
     * Migrate Clients
     */
    private function migrateClients($isDryRun)
    {
        $oldTable = 'clients';
        $total = DB::connection('mirgani')->table($oldTable)->count();
        
        if ($total === 0) {
            $this->warn("No records found in {$oldTable}");
            return;
        }

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $migrated = 0;
        $skipped = 0;

        DB::connection('mirgani')->table($oldTable)->orderBy('id')->chunk($this->option('chunk'), function ($oldClients) use (&$migrated, &$skipped, $isDryRun, $bar) {
            foreach ($oldClients as $oldClient) {
                try {
                    // Check if exists by name or email
                    $exists = Client::where('name', $oldClient->name ?? '')
                        ->orWhere(function($q) use ($oldClient) {
                            if (!empty($oldClient->email)) {
                                $q->where('email', $oldClient->email);
                            }
                        })
                        ->exists();
                    
                    if ($exists) {
                        $found = Client::where('name', $oldClient->name ?? '')->first();
                        if ($found) {
                            $this->clientIdMap[$oldClient->id] = $found->id;
                        }
                        $skipped++;
                        $bar->advance();
                        continue;
                    }

                    if (!$isDryRun) {
                        $client = Client::create([
                            'name' => $oldClient->name ?? 'Unnamed Client',
                            'email' => !empty($oldClient->email) ? $oldClient->email : null,
                            'phone' => $oldClient->phone ?? null,
                            'address' => $oldClient->address ?? null,
                            'created_at' => isset($oldClient->created_at) ? Carbon::parse($oldClient->created_at) : now(),
                            'updated_at' => isset($oldClient->updated_at) ? Carbon::parse($oldClient->updated_at) : now(),
                        ]);

                        $this->clientIdMap[$oldClient->id] = $client->id;
                    }

                    $migrated++;
                } catch (\Exception $e) {
                    $this->error("\nFailed to migrate client ID {$oldClient->id}: " . $e->getMessage());
                    Log::error("Client Migration Failed: ID={$oldClient->id}, Error={$e->getMessage()}");
                }
                $bar->advance();
            }
        });

        $bar->finish();
        $this->info("\nMigrated: {$migrated}, Skipped: {$skipped}");
        $this->stats['migrated'] += $migrated;
        $this->stats['skipped'] += $skipped;
    }

    /**
     * Migrate Purchases (deposits → purchases)
     */
    private function migratePurchases($isDryRun)
    {
        $oldTable = 'deposits';
        $total = DB::connection('mirgani')->table($oldTable)->count();
        
        if ($total === 0) {
            $this->warn("No records found in {$oldTable}");
            return;
        }

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $migrated = 0;
        $skipped = 0;

        DB::connection('mirgani')->table($oldTable)->orderBy('id')->chunk($this->option('chunk'), function ($oldDeposits) use (&$migrated, &$skipped, $isDryRun, $bar) {
            foreach ($oldDeposits as $oldDeposit) {
                try {
                    // Check if exists by reference number
                    $referenceNumber = $oldDeposit->bill_number ?? $oldDeposit->reference_number ?? null;
                    if ($referenceNumber && Purchase::where('reference_number', $referenceNumber)->exists()) {
                        $skipped++;
                        $bar->advance();
                        continue;
                    }

                    // Map supplier
                    $supplierId = null;
                    if (!empty($oldDeposit->supplier_id) && isset($this->supplierIdMap[$oldDeposit->supplier_id])) {
                        $supplierId = $this->supplierIdMap[$oldDeposit->supplier_id];
                    }

                    // Map user
                    $userId = null;
                    if (!empty($oldDeposit->user_id) && isset($this->userIdMap[$oldDeposit->user_id])) {
                        $userId = $this->userIdMap[$oldDeposit->user_id];
                    }

                    // Determine status
                    $status = 'pending';
                    if (isset($oldDeposit->complete) && $oldDeposit->complete == 1) {
                        $status = 'received';
                    } elseif (isset($oldDeposit->paid) && $oldDeposit->paid == 1) {
                        $status = 'ordered';
                    }

                    // Calculate total from items
                    $totalAmount = $this->calculateDepositTotal($oldDeposit->id);

                    // Validate date
                    $purchaseDate = $this->validateDate($oldDeposit->bill_date ?? $oldDeposit->purchase_date ?? null);

                    if (!$isDryRun) {
                        $purchase = Purchase::create([
                            'supplier_id' => $supplierId,
                            'user_id' => $userId,
                            'purchase_date' => $purchaseDate,
                            'reference_number' => $referenceNumber,
                            'status' => $status,
                            'total_amount' => $totalAmount,
                            'notes' => $this->buildPurchaseNotes($oldDeposit),
                            'created_at' => isset($oldDeposit->created_at) ? Carbon::parse($oldDeposit->created_at) : now(),
                            'updated_at' => isset($oldDeposit->updated_at) ? Carbon::parse($oldDeposit->updated_at) : now(),
                        ]);

                        $this->purchaseIdMap[$oldDeposit->id] = $purchase->id;
                    }

                    $migrated++;
                } catch (\Exception $e) {
                    $this->error("\nFailed to migrate purchase ID {$oldDeposit->id}: " . $e->getMessage());
                    Log::error("Purchase Migration Failed: ID={$oldDeposit->id}, Error={$e->getMessage()}");
                }
                $bar->advance();
            }
        });

        $bar->finish();
        $this->info("\nMigrated: {$migrated}, Skipped: {$skipped}");
        $this->stats['migrated'] += $migrated;
        $this->stats['skipped'] += $skipped;
    }

    /**
     * Calculate total amount from deposit items
     */
    private function calculateDepositTotal($depositId)
    {
        try {
            $result = DB::connection('mirgani')
                ->select("SELECT SUM(cost * quantity) as total FROM deposit_items WHERE deposit_id = ?", [$depositId]);
            return $result[0]->total ?? 0;
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Build purchase notes from old deposit data
     */
    private function buildPurchaseNotes($oldDeposit)
    {
        $notes = "Migrated from mirgani database.\n";
        if (isset($oldDeposit->payment_method)) {
            $notes .= "Payment Method: {$oldDeposit->payment_method}\n";
        }
        if (isset($oldDeposit->discount)) {
            $notes .= "Discount: {$oldDeposit->discount}\n";
        }
        return trim($notes);
    }

    /**
     * Migrate Purchase Items (deposit_items → purchase_items)
     */
    private function migratePurchaseItems($isDryRun)
    {
        $oldTable = 'deposit_items';
        $total = DB::connection('mirgani')->table($oldTable)->count();
        
        if ($total === 0) {
            $this->warn("No records found in {$oldTable}");
            return;
        }

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $migrated = 0;
        $skipped = 0;

        DB::connection('mirgani')->table($oldTable)->orderBy('id')->chunk($this->option('chunk'), function ($oldItems) use (&$migrated, &$skipped, $isDryRun, $bar) {
            foreach ($oldItems as $oldItem) {
                try {
                    // Map purchase
                    if (empty($oldItem->deposit_id) || !isset($this->purchaseIdMap[$oldItem->deposit_id])) {
                        $skipped++;
                        $bar->advance();
                        continue;
                    }
                    $purchaseId = $this->purchaseIdMap[$oldItem->deposit_id];

                    // Map product
                    if (empty($oldItem->item_id) || !isset($this->productIdMap[$oldItem->item_id])) {
                        $skipped++;
                        $bar->advance();
                        continue;
                    }
                    $productId = $this->productIdMap[$oldItem->item_id];

                    // Get product for unit calculations
                    $product = Product::find($productId);
                    if (!$product) {
                        $skipped++;
                        $bar->advance();
                        continue;
                    }

                    $quantity = (int)($oldItem->quantity ?? 0);
                    $unitCost = (float)($oldItem->cost ?? 0);
                    $totalCost = $quantity * $unitCost;

                    // Calculate remaining quantity (initially same as quantity)
                    $remainingQuantity = $quantity;
                    if ($product->units_per_stocking_unit > 0) {
                        $remainingQuantity = $quantity * $product->units_per_stocking_unit;
                    }

                    // Validate expiry date
                    $expiryDate = $this->validateDate($oldItem->expire ?? $oldItem->expiry_date ?? null);

                    if (!$isDryRun) {
                        PurchaseItem::create([
                            'purchase_id' => $purchaseId,
                            'product_id' => $productId,
                            'batch_number' => $oldItem->batch ?? $oldItem->batch_number ?? null,
                            'quantity' => $quantity,
                            'remaining_quantity' => $remainingQuantity,
                            'unit_cost' => $unitCost,
                            'total_cost' => $totalCost,
                            'sale_price' => !empty($oldItem->sell_price) ? (float)$oldItem->sell_price : null,
                            'expiry_date' => $expiryDate,
                            'cost_per_sellable_unit' => $product->units_per_stocking_unit > 0 
                                ? round($unitCost / $product->units_per_stocking_unit, 2) 
                                : $unitCost,
                            'created_at' => isset($oldItem->created_at) ? Carbon::parse($oldItem->created_at) : now(),
                            'updated_at' => isset($oldItem->updated_at) ? Carbon::parse($oldItem->updated_at) : now(),
                        ]);
                    }

                    $migrated++;
                } catch (\Exception $e) {
                    $this->error("\nFailed to migrate purchase item ID {$oldItem->id}: " . $e->getMessage());
                    Log::error("Purchase Item Migration Failed: ID={$oldItem->id}, Error={$e->getMessage()}");
                }
                $bar->advance();
            }
        });

        $bar->finish();
        $this->info("\nMigrated: {$migrated}, Skipped: {$skipped}");
        $this->stats['migrated'] += $migrated;
        $this->stats['skipped'] += $skipped;
    }

    /**
     * Migrate Sales (deducts → sales)
     */
    private function migrateSales($isDryRun)
    {
        $oldTable = 'deducts';
        $total = DB::connection('mirgani')->table($oldTable)->count();
        
        if ($total === 0) {
            $this->warn("No records found in {$oldTable}");
            return;
        }

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $migrated = 0;
        $skipped = 0;

        DB::connection('mirgani')->table($oldTable)->orderBy('id')->chunk($this->option('chunk'), function ($oldSales) use (&$migrated, &$skipped, $isDryRun, $bar) {
            foreach ($oldSales as $oldSale) {
                try {
                    // Check if exists by invoice number
                    $invoiceNumber = $oldSale->invoice_number ?? null;
                    if ($invoiceNumber && Sale::where('invoice_number', $invoiceNumber)->exists()) {
                        $skipped++;
                        $bar->advance();
                        continue;
                    }

                    // Map client
                    $clientId = null;
                    if (!empty($oldSale->client_id) && isset($this->clientIdMap[$oldSale->client_id])) {
                        $clientId = $this->clientIdMap[$oldSale->client_id];
                    }

                    // Map user
                    $userId = null;
                    if (!empty($oldSale->user_id) && isset($this->userIdMap[$oldSale->user_id])) {
                        $userId = $this->userIdMap[$oldSale->user_id];
                    }

                    // Validate date
                    $saleDate = $this->validateDate($oldSale->date ?? $oldSale->sale_date ?? null);

                    // Calculate totals from items
                    $totalAmount = $this->calculateDeductTotal($oldSale->id);
                    $paidAmount = (float)($oldSale->paid ?? $oldSale->paid_amount ?? 0);

                    if (!$isDryRun) {
                        $sale = Sale::create([
                            'client_id' => $clientId,
                            'user_id' => $userId,
                            'sale_date' => $saleDate,
                            'invoice_number' => $invoiceNumber,
                            'total_amount' => $totalAmount,
                            'paid_amount' => $paidAmount,
                            'discount_amount' => (float)($oldSale->discount ?? $oldSale->discount_amount ?? 0),
                            'discount_type' => $oldSale->discount_type ?? null,
                            'notes' => $oldSale->notes ?? null,
                            'status' => 'completed',
                            'is_returned' => isset($oldSale->is_returned) ? (bool)$oldSale->is_returned : false,
                            'created_at' => isset($oldSale->created_at) ? Carbon::parse($oldSale->created_at) : now(),
                            'updated_at' => isset($oldSale->updated_at) ? Carbon::parse($oldSale->updated_at) : now(),
                        ]);

                        $this->saleIdMap[$oldSale->id] = $sale->id;
                    }

                    $migrated++;
                } catch (\Exception $e) {
                    $this->error("\nFailed to migrate sale ID {$oldSale->id}: " . $e->getMessage());
                    Log::error("Sale Migration Failed: ID={$oldSale->id}, Error={$e->getMessage()}");
                }
                $bar->advance();
            }
        });

        $bar->finish();
        $this->info("\nMigrated: {$migrated}, Skipped: {$skipped}");
        $this->stats['migrated'] += $migrated;
        $this->stats['skipped'] += $skipped;
    }

    /**
     * Calculate total amount from deducted items
     */
    private function calculateDeductTotal($deductId)
    {
        try {
            $result = DB::connection('mirgani')
                ->select("SELECT SUM(price * quantity) as total FROM deducted_items WHERE deduct_id = ?", [$deductId]);
            return $result[0]->total ?? 0;
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Migrate Sale Items (deducted_items → sales_items)
     */
    private function migrateSaleItems($isDryRun)
    {
        $oldTable = 'deducted_items';
        $total = DB::connection('mirgani')->table($oldTable)->count();
        
        if ($total === 0) {
            $this->warn("No records found in {$oldTable}");
            return;
        }

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $migrated = 0;
        $skipped = 0;

        DB::connection('mirgani')->table($oldTable)->orderBy('id')->chunk($this->option('chunk'), function ($oldItems) use (&$migrated, &$skipped, $isDryRun, $bar) {
            foreach ($oldItems as $oldItem) {
                try {
                    // Map sale
                    if (empty($oldItem->deduct_id) || !isset($this->saleIdMap[$oldItem->deduct_id])) {
                        $skipped++;
                        $bar->advance();
                        continue;
                    }
                    $saleId = $this->saleIdMap[$oldItem->deduct_id];

                    // Map product
                    if (empty($oldItem->item_id) || !isset($this->productIdMap[$oldItem->item_id])) {
                        $skipped++;
                        $bar->advance();
                        continue;
                    }
                    $productId = $this->productIdMap[$oldItem->item_id];

                    $quantity = (int)($oldItem->box ?? 0);
                    $unitPrice = (float)($oldItem->price ?? $oldItem->unit_price ?? 0);
                    $totalPrice = $quantity * $unitPrice;
                    $costPrice = (float)($oldItem->cost ?? $oldItem->cost_price ?? 0);

                    if (!$isDryRun) {
                        SaleItem::create([
                            'sale_id' => $saleId,
                            'product_id' => $productId,
                            'quantity' => $quantity,
                            'unit_price' => $unitPrice,
                            'total_price' => $totalPrice,
                            'cost_price_at_sale' => $costPrice,
                            'batch_number_sold' => $oldItem->batch ?? $oldItem->batch_number ?? null,
                            'created_at' => isset($oldItem->created_at) ? Carbon::parse($oldItem->created_at) : now(),
                            'updated_at' => isset($oldItem->updated_at) ? Carbon::parse($oldItem->updated_at) : now(),
                        ]);
                    }

                    $migrated++;
                } catch (\Exception $e) {
                    $this->error("\nFailed to migrate sale item ID {$oldItem->id}: " . $e->getMessage());
                    Log::error("Sale Item Migration Failed: ID={$oldItem->id}, Error={$e->getMessage()}");
                }
                $bar->advance();
            }
        });

        $bar->finish();
        $this->info("\nMigrated: {$migrated}, Skipped: {$skipped}");
        $this->stats['migrated'] += $migrated;
        $this->stats['skipped'] += $skipped;
    }

    /**
     * Validate and return a date string
     */
    private function validateDate($dateString)
    {
        if (empty($dateString) || $dateString === '0000-00-00' || $dateString === '0000-00-00 00:00:00') {
            return Carbon::today()->format('Y-m-d');
        }

        try {
            $carbonDate = Carbon::parse($dateString);
            if ($carbonDate->year < 1900) {
                return Carbon::today()->format('Y-m-d');
            }
            return $carbonDate->format('Y-m-d');
        } catch (\Exception $e) {
            return Carbon::today()->format('Y-m-d');
        }
    }

    /**
     * Display migration summary
     */
    private function displaySummary($isDryRun)
    {
        $this->info("\n" . str_repeat('=', 60));
        $this->info("Migration Summary");
        $this->info(str_repeat('=', 60));
        $this->info("Migrated: {$this->stats['migrated']}");
        $this->info("Skipped: {$this->stats['skipped']}");
        $this->info("Errors: {$this->stats['errors']}");
        
        if ($isDryRun) {
            $this->warn("\nThis was a dry run. No actual data was migrated.");
        }
    }
}
