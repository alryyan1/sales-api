<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pdf_report_settings', function (Blueprint $table) {
            $table->id();
            $table->string('report_key')->unique();
            $table->string('report_name');
            $table->enum('branding_type', ['logo', 'header', 'none'])->nullable(); // null = use global
            $table->enum('logo_position', ['left', 'right', 'center'])->nullable(); // null = use global
            $table->unsignedInteger('logo_height')->nullable(); // null = use global
            $table->unsignedInteger('logo_width')->nullable();  // null = use global
            $table->boolean('show_watermark')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pdf_report_settings');
    }
};
