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
        // Drop child table first to avoid foreign key issues
        Schema::dropIfExists('sale_return_items');
        Schema::dropIfExists('sale_returns');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Intentionally left empty; sale return feature has been removed.
    }
};

