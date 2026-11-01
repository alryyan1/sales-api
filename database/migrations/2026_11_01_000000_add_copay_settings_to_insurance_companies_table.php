<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Co-pay mode per insurance company: `fixed` applies `copay_percentage`
     * automatically to every sale of its patients (not editable at the POS);
     * `variable` lets the cashier enter it per sale.
     */
    public function up(): void
    {
        Schema::table('insurance_companies', function (Blueprint $table) {
            $table->string('copay_type', 20)->default('variable')->after('invoice_cap');
            $table->decimal('copay_percentage', 5, 2)->nullable()->after('copay_type');
        });
    }

    public function down(): void
    {
        Schema::table('insurance_companies', function (Blueprint $table) {
            $table->dropColumn(['copay_type', 'copay_percentage']);
        });
    }
};
