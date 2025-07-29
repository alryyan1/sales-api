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
        Schema::create('units', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // Unit name (e.g., "Box", "Piece", "Carton", "Item")
            $table->enum('type', ['stocking', 'sellable']); // Type of unit
            $table->text('description')->nullable(); // Optional description
            $table->boolean('is_active')->default(true); // Whether the unit is active
            $table->timestamps();
            
            // Ensure unique combination of name and type
            $table->unique(['name', 'type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('units');
    }
};
