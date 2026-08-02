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
        if (Schema::hasColumn('purchases', 'currency')) {
            Schema::table('purchases', function (Blueprint $table) {
                $table->dropColumn('currency');
            });
        }

        if (Schema::hasColumn('products', 'preferred_currency')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropColumn('preferred_currency');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->enum('currency', ['SDG', 'USD'])->default('SDG')->after('notes');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->enum('preferred_currency', ['SDG', 'USD'])->nullable()->after('cost_price');
        });
    }
};
