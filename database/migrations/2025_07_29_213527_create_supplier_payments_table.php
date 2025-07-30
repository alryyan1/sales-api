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
        Schema::create('supplier_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade'); // User who made the payment
            $table->decimal('amount', 15, 2); // Payment amount
            $table->enum('type', ['payment', 'credit', 'adjustment']); // Payment type
            $table->enum('method', ['cash', 'bank_transfer', 'check', 'credit_card', 'other']); // Payment method
            $table->string('reference_number')->nullable(); // Check number, transaction ID, etc.
            $table->text('notes')->nullable(); // Additional notes
            $table->date('payment_date'); // Date of payment
            $table->timestamps();
            
            // Indexes for better performance
            $table->index(['supplier_id', 'payment_date']);
            $table->index(['payment_date']);
            $table->index(['type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('supplier_payments');
    }
};
