<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Data Migration: Merge duplicate units (same name, different types)
        $duplicateUnits = DB::table('units')
            ->select('name')
            ->groupBy('name')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('name');

        foreach ($duplicateUnits as $name) {
            $units = DB::table('units')
                ->where('name', $name)
                ->orderBy('id')
                ->get();

            $master = $units->first();
            $duplicates = $units->slice(1);

            foreach ($duplicates as $duplicate) {
                // Update products using the duplicate as stocking unit
                DB::table('products')
                    ->where('stocking_unit_id', $duplicate->id)
                    ->update(['stocking_unit_id' => $master->id]);

                // Update products using the duplicate as sellable unit
                DB::table('products')
                    ->where('sellable_unit_id', $duplicate->id)
                    ->update(['sellable_unit_id' => $master->id]);

                // Update other potential tables if they exist (e.g. PurchaseItem)
                // PurchaseItem in this system doesn't directly link to Unit IDs, 
                // but if it did, we would update them here.

                // Delete the duplicate
                DB::table('units')->where('id', $duplicate->id)->delete();
            }
        }

        // 2. Schema Changes
        Schema::table('units', function (Blueprint $table) {
            // Drop unique constraint on ['name', 'type']
            $table->dropUnique(['name', 'type']);
            
            // Drop the type column
            $table->dropColumn('type');
            
            // Add unique constraint on name
            $table->unique('name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('units', function (Blueprint $table) {
            $table->dropUnique(['name']);
            $table->enum('type', ['stocking', 'sellable'])->default('sellable');
            $table->unique(['name', 'type']);
        });
    }
};
