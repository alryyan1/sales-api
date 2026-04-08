<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_transfer_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_transfer_id')->constrained()->onDelete('cascade');
            $table->foreignId('product_id')->constrained()->onDelete('cascade');
            $table->decimal('quantity', 12, 2);
            $table->timestamps();
        });

        // Migrate existing single-product transfers into items
        DB::statement('
            INSERT INTO stock_transfer_items (stock_transfer_id, product_id, quantity, created_at, updated_at)
            SELECT id, product_id, quantity, created_at, updated_at
            FROM stock_transfers
            WHERE product_id IS NOT NULL
        ');

        // Drop old columns from stock_transfers
        Schema::table('stock_transfers', function (Blueprint $table) {
            $table->dropForeign(['product_id']);
            $table->dropColumn(['product_id', 'quantity']);
        });
    }

    public function down(): void
    {
        Schema::table('stock_transfers', function (Blueprint $table) {
            $table->foreignId('product_id')->nullable()->constrained()->onDelete('cascade');
            $table->decimal('quantity', 12, 2)->nullable();
        });

        Schema::dropIfExists('stock_transfer_items');
    }
};
