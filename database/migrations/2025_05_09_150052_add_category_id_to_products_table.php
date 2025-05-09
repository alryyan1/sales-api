<?php // database/migrations/...add_category_id_to_products_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('products', function (Blueprint $table) {
            // onDelete('set null'): if category is deleted, product's category_id becomes null.
            // onDelete('restrict'): prevent deleting category if products are assigned.
            $table->foreignId('category_id')->nullable()->after('description') // Or after another relevant column
                  ->constrained('categories')->onDelete('set null');
        });
    }
    public function down(): void {
        Schema::table('products', function (Blueprint $table) {
            if (Schema::hasColumn('products', 'category_id')) {
                // Ensure to drop foreign key if it has a custom name or default naming
                // $table->dropForeign(['category_id']); // Or specific foreign key name
                $table->dropColumn('category_id');
            }
        });
    }
};