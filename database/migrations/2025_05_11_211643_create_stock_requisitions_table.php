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
        Schema::create('stock_requisitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('requester_user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->onDelete('set null');
            $table->string('department_or_reason')->nullable();
            $table->text('notes')->nullable();
            $table->enum('status', ['pending_approval', 'approved', 'rejected', 'partially_issued', 'issued', 'cancelled'])->default('pending_approval');
            $table->date('request_date');
            $table->date('issue_date')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_requisitions');
    }
};
