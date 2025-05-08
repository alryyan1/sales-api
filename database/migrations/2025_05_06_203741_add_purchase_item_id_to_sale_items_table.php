<?php // database/migrations/...add_purchase_item_id_to_sale_items_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            // Foreign key to the specific purchase_item (batch) this sale item came from
            // onDelete('restrict') or 'set null' if a purchase_item might be deleted but sale item retained (less common)
            $table->foreignId('purchase_item_id')->nullable()->after('product_id')->constrained('purchase_items')->onDelete('restrict');
            // You might also want to store the batch_number directly for easier display, though redundant
            $table->string('batch_number_sold')->nullable()->after('purchase_item_id');
        });
    }
    public function down(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            if (Schema::hasColumn('sale_items', 'purchase_item_id')) {
                // Need to drop foreign key constraint first if it was named
                // $table->dropForeign(['purchase_item_id']);
                $table->dropColumn('purchase_item_id');
            }
            if (Schema::hasColumn('sale_items', 'batch_number_sold')) {
                $table->dropColumn('batch_number_sold');
            }
        });
    }
};
