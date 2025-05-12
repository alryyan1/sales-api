<?php // ...create_stock_requisition_items_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('stock_requisition_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_requisition_id')->constrained('stock_requisitions')->onDelete('cascade');
            $table->foreignId('product_id')->constrained('products')->onDelete('restrict');
            $table->integer('requested_quantity');
            $table->integer('issued_quantity')->default(0); // Quantity actually issued (can be less than requested)
            // If linking to specific batches for issuance:
            $table->foreignId('issued_from_purchase_item_id')->nullable()->constrained('purchase_items')->onDelete('set null'); // Which batch was it taken from
            $table->string('issued_batch_number')->nullable(); // Denormalized for easier display
            $table->string('status')->default('pending'); // e.g., 'pending', 'issued', 'rejected_item'
            $table->text('item_notes')->nullable(); // Notes specific to this item in the request
            $table->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('stock_requisition_items'); }
};