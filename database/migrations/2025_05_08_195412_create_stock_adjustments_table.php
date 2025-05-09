<?php // database/migrations/...create_stock_adjustments_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_adjustments', function (Blueprint $table) {
            $table->id();
            // Link to the product being adjusted
            $table->foreignId('product_id')->constrained('products')->onDelete('cascade'); // Cascade if product deleted? Or restrict? Cascade might be okay for logs.
             // Optional: Link to the specific batch adjusted (if applicable)
            $table->foreignId('purchase_item_id')->nullable()->constrained('purchase_items')->onDelete('set null'); // Link to batch, set null if batch deleted
            // Link to the user performing the adjustment
            $table->foreignId('user_id')->nullable()->constrained('users')->onDelete('set null');

            $table->integer('quantity_change'); // Positive for increase, negative for decrease
            $table->integer('quantity_before'); // Stock level BEFORE the adjustment
            $table->integer('quantity_after');  // Stock level AFTER the adjustment

            $table->string('reason')->nullable(); // Reason for adjustment (e.g., 'damaged', 'stock_take', 'initial')
            $table->text('notes')->nullable(); // Additional details

            $table->timestamps(); // When the adjustment was recorded
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_adjustments');
    }
};