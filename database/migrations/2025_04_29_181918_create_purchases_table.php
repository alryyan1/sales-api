<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;


// ... (use statements)

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained('suppliers')->onDelete('cascade'); // Foreign key to suppliers table
            $table->date('purchase_date');
            $table->string('reference_number')->nullable()->unique(); // Optional invoice/PO number
            $table->decimal('total_amount', 12, 2)->default(0.00);
            $table->enum('status', ['received', 'pending', 'ordered'])->default('pending');
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchases');
    }
};