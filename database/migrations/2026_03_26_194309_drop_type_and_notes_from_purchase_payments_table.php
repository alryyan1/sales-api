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
        Schema::table('purchase_payments', function (Blueprint $table) {
            if (Schema::hasColumn('purchase_payments', 'type')) {
                $table->dropColumn('type');
            }
            if (Schema::hasColumn('purchase_payments', 'notes')) {
                $table->dropColumn('notes');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('purchase_payments', function (Blueprint $table) {
            $table->string('type')->default('payment')->after('amount');
            $table->text('notes')->nullable()->after('reference_number');
        });
    }
};
