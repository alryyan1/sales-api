<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('purchase_items', function (Blueprint $table) {
            $table->string('batch_number')->nullable()->after('product_id'); // Or make it unique per product/purchase if needed
            // Indexing batch_number can be useful if you query by it often
            // $table->index('batch_number');
        });
    }

    public function down(): void {
        Schema::table('purchase_items', function (Blueprint $table) {
            if (Schema::hasColumn('purchase_items', 'batch_number')) {
                // $table->dropIndex(['batch_number']); // Drop index if created
                $table->dropColumn('batch_number');
            }
        });
    }
};