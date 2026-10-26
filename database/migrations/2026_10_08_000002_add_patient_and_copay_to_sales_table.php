<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Links an insured patient to a sale. When `copay_percentage` is set, the
     * patient only pays that share of (subtotal - discount); the insurance
     * company covers the rest.
     */
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->foreignId('patient_id')->nullable()->after('client_id')->constrained('patients')->nullOnDelete();
            $table->decimal('copay_percentage', 5, 2)->nullable()->after('discount_amount');
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropConstrainedForeignId('patient_id');
            $table->dropColumn('copay_percentage');
        });
    }
};
