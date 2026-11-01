<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `invoice_cap` is the maximum invoice amount per patient for the company
     * (null = no cap). Sub-companies are optional subsidiaries of a company.
     */
    public function up(): void
    {
        Schema::table('insurance_companies', function (Blueprint $table) {
            $table->decimal('invoice_cap', 15, 2)->nullable()->after('email');
        });

        Schema::create('insurance_sub_companies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('insurance_company_id')->constrained('insurance_companies')->cascadeOnDelete();
            $table->string('name');
            $table->timestamps();

            $table->unique(['insurance_company_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('insurance_sub_companies');

        Schema::table('insurance_companies', function (Blueprint $table) {
            $table->dropColumn('invoice_cap');
        });
    }
};
