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
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // Product Name - Required
            $table->string('sku')->unique()->nullable(); // Stock Keeping Unit - Optional but unique if provided
            $table->text('description')->nullable(); // Optional product description

            // Use decimal for prices to handle currency accurately
            // 10 total digits, 2 after the decimal point. Adjust precision/scale as needed.
            $table->decimal('purchase_price', 10, 2)->default(0.00); // Price bought from supplier
            $table->decimal('sale_price', 10, 2)->default(0.00);     // Price sold to client

            // Use integer for quantities. Default to 0.
            $table->integer('stock_quantity')->default(0);
            $table->integer('stock_alert_level')->default(10)->nullable(); // Threshold for low stock alerts

            // Optional: Foreign key for categories if you plan to add them later
            // $table->foreignId('category_id')->nullable()->constrained('categories')->onDelete('set null');

            // Optional: Track unit of measurement (e.g., piece, kg, liter)
            // $table->string('unit')->nullable()->default('piece');

            $table->timestamps(); // created_at, updated_at
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};