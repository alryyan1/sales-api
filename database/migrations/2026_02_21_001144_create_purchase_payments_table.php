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
        Schema::create('purchase_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_id')->constrained('purchases')->onDelete('cascade');
            $table->foreignId('user_id')->nullable()->constrained('users')->onDelete('set null');

            // Payment method enum matching sales: 'cash', 'bankak', 'fawry', 'ocash', 'other'. Include 'visa', 'mastercard', 'bank_transfer', 'mada', 'refund' for backward compatibility or future use if needed, but the UI requested same as sale. We will use the same ENUM as the updated payments table from 2026_02_08.
            $table->enum('method', ['cash', 'visa', 'mastercard', 'bank_transfer', 'mada', 'refund', 'other', 'bankak', 'fawry', 'ocash'])->default('cash');

            $table->decimal('amount', 12, 2);
            $table->date('payment_date');
            $table->string('reference_number')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('purchase_payments');
    }
};
