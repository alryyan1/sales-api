<?php // database/migrations/...create_sale_return_items_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('sale_return_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_return_id')->constrained('sale_returns')->onDelete('cascade');
            $table->foreignId('product_id')->constrained('products')->onDelete('restrict'); // Product being returned
            // Link to the specific original SaleItem being returned (optional but good for traceability)
            $table->foreignId('original_sale_item_id')->nullable()->constrained('sale_items')->onDelete('set null');
            // Link to the PurchaseItem (batch) the stock should be returned to (optional, if that specific)
            // Or if returning to a general "returned goods" virtual batch
            $table->foreignId('return_to_purchase_item_id')->nullable()->constrained('purchase_items')->onDelete('set null');

            $table->integer('quantity_returned');
            $table->decimal('unit_price', 10, 2); // Price at which it was originally sold (from original_sale_item_id)
            $table->decimal('total_returned_value', 12, 2); // quantity_returned * unit_price
            $table->string('condition')->nullable(); // e.g., 'resellable', 'damaged', 'defective'

            $table->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('sale_return_items'); }
};