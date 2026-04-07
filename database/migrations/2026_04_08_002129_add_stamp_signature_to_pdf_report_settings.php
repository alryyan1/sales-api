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
        Schema::table('pdf_report_settings', function (Blueprint $table) {
            $table->boolean('show_stamp')->default(false)->after('show_watermark');
            $table->boolean('show_signature')->default(false)->after('show_stamp');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pdf_report_settings', function (Blueprint $table) {
            $table->dropColumn(['show_stamp', 'show_signature']);
        });
    }
};
