<?php // database/migrations/...create_categories_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique(); // Category names should be unique
            $table->text('description')->nullable();
            // For hierarchical categories (optional)
            $table->foreignId('parent_id')->nullable()->constrained('categories')->onDelete('cascade'); // Self-referencing for subcategories
            $table->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('categories'); }
};