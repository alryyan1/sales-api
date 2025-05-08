<?php // database/migrations/...add_remaining_quantity_to_purchase_items_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('purchase_items', function (Blueprint $table) {
            // Represents the quantity still available from this specific batch
            $table->integer('remaining_quantity')->default(0)->after('quantity');
        });
    }
    public function down(): void {
        Schema::table('purchase_items', function (Blueprint $table) {
            if (Schema::hasColumn('purchase_items', 'remaining_quantity')) {
                $table->dropColumn('remaining_quantity');
            }
        });
    }
};