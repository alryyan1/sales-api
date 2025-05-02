<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ... (use statements)

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_id')->constrained('purchases')->onDelete('cascade'); // Link to the purchase header
            $table->foreignId('product_id')->constrained('products')->onDelete('cascade');   // Link to the product
            $table->integer('quantity');
            $table->decimal('unit_cost', 10, 2); // Cost per unit at the time of purchase
            $table->decimal('total_cost', 12, 2); // quantity * unit_cost
            $table->timestamps(); // Often not needed for line items, but can be useful

            // Optional: Ensure a product isn't added twice to the same purchase
            // $table->unique(['purchase_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_items');
    }
};