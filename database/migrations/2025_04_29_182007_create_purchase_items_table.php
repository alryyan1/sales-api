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
        Schema::create('purchase_items', function (Blueprint $table) {
            $table->id();

            // Foreign key to the purchases table
            // onDelete('cascade') means if the parent Purchase record is deleted, these items are also deleted.
            $table->foreignId('purchase_id')->constrained('purchases')->onDelete('cascade');

            // Foreign key to the products table
            // onDelete('restrict') prevents deleting a product if it exists in purchase items.
            // Consider implications carefully. Maybe you want to allow product deletion but keep the record?
            $table->foreignId('product_id')->constrained('products')->onDelete('restrict');

            $table->integer('quantity'); // Quantity purchased
            // Cost per unit at the time of purchase. Set precision/scale.
            $table->decimal('unit_cost', 10, 2);
            // Total cost for this line item (quantity * unit_cost). Set precision/scale.
            $table->decimal('total_cost', 12, 2);

            $table->timestamps(); // Optional for line items, but can be useful

            // Optional: Prevent adding the same product twice to the same purchase order
            // $table->unique(['purchase_id', 'product_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('purchase_items');
    }
};