<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('products', function (Blueprint $table) {
            // Drop columns if they exist
            if (Schema::hasColumn('products', 'purchase_price')) {
                $table->dropColumn('purchase_price');
            }
            if (Schema::hasColumn('products', 'sale_price')) {
                $table->dropColumn('sale_price');
            }
        });
    }

    public function down(): void { // Re-add columns on rollback
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('purchase_price', 10, 2)->default(0.00)->after('description');
            $table->decimal('sale_price', 10, 2)->default(0.00)->after('purchase_price');
        });
    }
};