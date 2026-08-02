<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('transit_orders', function (Blueprint $table) {
            $table->dropColumn('supplier_name');
            $table->foreignId('supplier_id')->nullable()->after('warehouse_id')->constrained()->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transit_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('supplier_id');
            $table->string('supplier_name')->nullable();
        });
    }
};
