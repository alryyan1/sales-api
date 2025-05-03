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
        Schema::create('purchases', function (Blueprint $table) {
            $table->id();

            // Foreign key to suppliers table
            // onDelete('restrict') prevents deleting a supplier if they have purchases.
            // Use 'cascade' if you want purchases deleted when supplier is deleted (less common).
            // Use 'set null' if supplier_id can be nullable and you want to keep the purchase record.
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->onDelete('set null');

            // Optional: Foreign key to the user who recorded the purchase
            $table->foreignId('user_id')->nullable()->constrained('users')->onDelete('set null');

            $table->date('purchase_date'); // Date the purchase was made/received
            $table->string('reference_number')->nullable()->unique(); // Optional PO/Invoice number, unique if provided
            $table->enum('status', ['received', 'pending', 'ordered'])->default('pending'); // Status of the purchase

            // Total amount calculated from purchase items. Set precision/scale appropriately.
            $table->decimal('total_amount', 12, 2)->default(0.00);

            $table->text('notes')->nullable(); // Optional notes for the purchase
            $table->timestamps(); // created_at and updated_at
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('purchases');
    }
};