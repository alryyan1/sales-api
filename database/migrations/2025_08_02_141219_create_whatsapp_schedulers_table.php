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
        Schema::create('whatsapp_schedulers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('phone_number');
            $table->enum('report_type', ['daily_sales', 'inventory', 'profit_loss']);
            $table->time('schedule_time');
            $table->boolean('is_active')->default(true);
            $table->json('days_of_week')->nullable(); // Array of days (0=Sunday, 1=Monday, etc.)
            $table->text('notes')->nullable();
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('whatsapp_schedulers');
    }
};
