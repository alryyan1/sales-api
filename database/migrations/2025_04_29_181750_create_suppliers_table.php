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
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // Supplier/Company Name
            $table->string('contact_person')->nullable(); // Optional contact person
            $table->string('email')->nullable()->unique(); // Optional, but unique if provided
            $table->string('phone')->nullable(); // Optional phone number
            $table->text('address')->nullable(); // Optional address
            // Add any other relevant fields like website, notes, etc.
            // $table->string('website')->nullable();
            // $table->text('notes')->nullable();
            $table->timestamps(); // created_at and updated_at
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('suppliers');
    }
};