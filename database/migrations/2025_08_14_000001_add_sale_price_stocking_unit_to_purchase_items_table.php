<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('purchase_items', function (Blueprint $table) {
            if (!Schema::hasColumn('purchase_items', 'sale_price_stocking_unit')) {
                $table->decimal('sale_price_stocking_unit', 10, 2)->nullable()->after('sale_price');
            }
        });
    }

    public function down(): void
    {
        Schema::table('purchase_items', function (Blueprint $table) {
            if (Schema::hasColumn('purchase_items', 'sale_price_stocking_unit')) {
                $table->dropColumn('sale_price_stocking_unit');
            }
        });
    }
};


