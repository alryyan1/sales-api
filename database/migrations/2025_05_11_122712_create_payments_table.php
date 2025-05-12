<?php // database/migrations/...create_payments_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained('sales')->onDelete('cascade');
            $table->foreignId('user_id')->nullable()->constrained('users')->onDelete('set null'); // User who recorded payment

            // Option A: Enum for payment method
            $table->enum('method', ['cash', 'visa', 'mastercard', 'bank_transfer', 'mada', 'other'])->default('cash');
            // Option B: Foreign key to payment_methods table
            // $table->foreignId('payment_method_id')->constrained('payment_methods');

            $table->decimal('amount', 12, 2);
            $table->date('payment_date');
            $table->string('reference_number')->nullable(); // e.g., transaction ID for card, cheque no
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('payments'); }
};