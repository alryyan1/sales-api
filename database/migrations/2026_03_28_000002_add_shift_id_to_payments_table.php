<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('shift_id')
                  ->nullable()
                  ->constrained('shifts')
                  ->nullOnDelete()
                  ->after('sale_id');
        });

        // Backfill shift_id from the associated sale
        DB::statement('UPDATE payments p JOIN sales s ON s.id = p.sale_id SET p.shift_id = s.shift_id');
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign(['shift_id']);
            $table->dropColumn('shift_id');
        });
    }
};
