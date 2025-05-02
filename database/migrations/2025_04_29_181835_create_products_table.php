<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;


// ... (use statements)

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('sku')->unique()->nullable(); // Stock Keeping Unit
            $table->text('description')->nullable();
            $table->decimal('purchase_price', 10, 2)->default(0.00); // Price bought from supplier
            $table->decimal('sale_price', 10, 2)->default(0.00);     // Price sold to client
            $table->integer('stock_quantity')->default(0);
            $table->integer('stock_alert_level')->default(10); // Optional: quantity level to trigger low stock alert
            // $table->unsignedBigInteger('category_id')->nullable(); // Example: If you add categories later
            // $table->foreign('category_id')->references('id')->on('categories');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};