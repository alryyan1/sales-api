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
        Schema::table('units', function (Blueprint $table) {
            $table->boolean('is_default')->default(false)->after('is_active');
        });

        // Set one default for each type if none exists
        $stockingDefault = DB::table('units')
            ->where('type', 'stocking')
            ->where('is_active', true)
            ->orderBy('id')
            ->first();

        if ($stockingDefault) {
            DB::table('units')
                ->where('id', $stockingDefault->id)
                ->update(['is_default' => true]);
        }

        $sellableDefault = DB::table('units')
            ->where('type', 'sellable')
            ->where('is_active', true)
            ->orderBy('id')
            ->first();

        if ($sellableDefault) {
            DB::table('units')
                ->where('id', $sellableDefault->id)
                ->update(['is_default' => true]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('units', function (Blueprint $table) {
            $table->dropColumn('is_default');
        });
    }
};
