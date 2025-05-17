<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ... (use statements)

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained('sales')->onDelete('cascade'); // Link to the sale header
            $table->foreignId('product_id')->constrained('products')->onDelete('cascade'); // Link to the product
            $table->integer('quantity');
            $table->decimal('unit_price', 10, 2); // Selling price per unit at the time of sale
            $table->decimal('total_price', 12, 2); // quantity * unit_price
            $table->decimal('cost_price_at_sale', 10, 2)->default(0.00);

            $table->timestamps();

            // Optional: Ensure a product isn't added twice to the same sale
            // $table->unique(['sale_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_items');
    }
};
