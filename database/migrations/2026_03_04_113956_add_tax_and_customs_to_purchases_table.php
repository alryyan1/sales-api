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
        Schema::table('purchases', function (Blueprint $table) {
            $table->decimal('tax_amount', 15, 2)->default(0)->after('total_amount');
            $table->decimal('customs_amount', 15, 2)->default(0)->after('tax_amount');
            $table->json('tax_details')->nullable()->after('customs_amount');
            $table->json('customs_details')->nullable()->after('tax_details');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->dropColumn(['tax_amount', 'customs_amount', 'tax_details', 'customs_details']);
        });
    }
};
