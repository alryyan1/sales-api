<?php // database/migrations/...create_sale_returns_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('sale_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('original_sale_id')->constrained('sales')->onDelete('cascade'); // Link to the original sale
            $table->foreignId('client_id')->nullable()->constrained('clients')->onDelete('set null'); // Client from original sale
            $table->foreignId('user_id')->nullable()->constrained('users')->onDelete('set null'); // User processing return

            $table->date('return_date');
            $table->string('return_reason')->nullable(); // e.g., damaged, wrong item, customer changed mind
            $table->text('notes')->nullable();
            $table->decimal('total_returned_amount', 12, 2)->default(0.00); // Sum of returned items' value
            $table->enum('status', ['pending', 'completed', 'cancelled'])->default('pending'); // Status of the return process

            // How the credit is handled
            $table->enum('credit_action', ['refund', 'store_credit', 'none'])->default('store_credit');
            $table->decimal('refunded_amount', 12, 2)->default(0.00); // If a direct refund was issued

            $table->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('sale_returns'); }
};