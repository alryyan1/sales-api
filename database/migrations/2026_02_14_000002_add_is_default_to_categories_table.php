<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->boolean('is_default')->default(false)->after('parent_id');
        });

        // Set one default category if none exists
        $defaultCategory = DB::table('categories')
            ->orderBy('id')
            ->first();

        if ($defaultCategory) {
            DB::table('categories')
                ->where('id', $defaultCategory->id)
                ->update(['is_default' => true]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn('is_default');
        });
    }
};
