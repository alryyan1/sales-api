<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduled_sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->dateTime('scheduled_at');
            $table->enum('status', ['pending', 'processing', 'completed', 'failed', 'cancelled'])->default('pending');
            $table->decimal('discount_amount', 10, 2)->nullable();
            $table->enum('discount_type', ['percentage', 'fixed'])->nullable();
            $table->text('notes')->nullable();

            // Set once the real Sale is created. Presence/absence signals whether a
            // failure happened before or after the point of no return.
            $table->foreignId('sale_id')->nullable()->constrained('sales')->nullOnDelete();
            $table->text('error_message')->nullable();
            $table->dateTime('executed_at')->nullable();

            $table->enum('whatsapp_customer_status', ['pending', 'sent', 'failed', 'skipped_no_template', 'skipped_no_phone'])->default('pending');
            $table->text('whatsapp_customer_error')->nullable();
            $table->string('whatsapp_customer_message_id')->nullable();
            $table->dateTime('whatsapp_customer_sent_at')->nullable();

            $table->enum('whatsapp_owner_status', ['pending', 'sent', 'failed', 'skipped_no_template', 'skipped_no_phone'])->default('pending');
            $table->text('whatsapp_owner_error')->nullable();
            $table->string('whatsapp_owner_message_id')->nullable();
            $table->dateTime('whatsapp_owner_sent_at')->nullable();

            $table->timestamps();

            $table->index(['status', 'scheduled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_sales');
    }
};
