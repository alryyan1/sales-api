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
        Schema::table('products', function (Blueprint $table) {
            // Add foreign key columns
            $table->foreignId('stocking_unit_id')->nullable()->constrained('units')->onDelete('set null');
            $table->foreignId('sellable_unit_id')->nullable()->constrained('units')->onDelete('set null');
            
            // Drop the old string columns
            $table->dropColumn(['stocking_unit_name', 'sellable_unit_name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Recreate the old string columns
            $table->string('stocking_unit_name')->default('Unit');
            $table->string('sellable_unit_name')->default('Piece');
            
            // Drop the foreign key columns
            $table->dropForeign(['stocking_unit_id']);
            $table->dropForeign(['sellable_unit_id']);
            $table->dropColumn(['stocking_unit_id', 'sellable_unit_id']);
        });
    }
};
