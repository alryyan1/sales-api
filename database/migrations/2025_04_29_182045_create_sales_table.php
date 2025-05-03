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
        Schema::create('sales', function (Blueprint $table) {
            $table->id();

            // Foreign key to clients table
            // Using nullable() and onDelete('set null') allows keeping sales records
            // even if the client is deleted. Choose 'restrict' if you want to prevent client deletion.
            $table->foreignId('client_id')->nullable()->constrained('clients')->onDelete('set null');

            // Foreign key to the user (salesperson) who made the sale
            $table->foreignId('user_id')->nullable()->constrained('users')->onDelete('set null');

            $table->date('sale_date'); // Date the sale was made
            $table->string('invoice_number')->nullable()->unique(); // Optional invoice number, unique if provided

            // Total amount calculated from sale items. Set precision/scale.
            $table->decimal('total_amount', 12, 2)->default(0.00);
            // Amount paid against this sale (useful for tracking partial payments)
            $table->decimal('paid_amount', 12, 2)->default(0.00);

            // Status of the sale
            $table->enum('status', ['completed', 'pending', 'draft', 'cancelled'])->default('completed');

            $table->text('notes')->nullable(); // Optional notes for the sale
            $table->timestamps(); // created_at and updated_at
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sales');
    }
};