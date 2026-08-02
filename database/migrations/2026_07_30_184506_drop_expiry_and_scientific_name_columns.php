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
        if (Schema::hasColumn('purchase_items', 'expiry_date')) {
            Schema::table('purchase_items', function (Blueprint $table) {
                $table->dropIndex(['expiry_date']);
                $table->dropColumn('expiry_date');
            });
        }

        if (Schema::hasColumn('purchase_items', 'is_moved_to_expired')) {
            Schema::table('purchase_items', function (Blueprint $table) {
                $table->dropColumn('is_moved_to_expired');
            });
        }

        Schema::table('products', function (Blueprint $table) {
            if (Schema::hasColumn('products', 'expire_date')) {
                $table->dropColumn('expire_date');
            }
            if (Schema::hasColumn('products', 'has_expiry_date')) {
                $table->dropColumn('has_expiry_date');
            }
            if (Schema::hasColumn('products', 'scientific_name')) {
                $table->dropColumn('scientific_name');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('scientific_name')->nullable()->after('name');
            $table->boolean('has_expiry_date')->default(false);
            $table->date('expire_date')->nullable();
        });

        Schema::table('purchase_items', function (Blueprint $table) {
            $table->date('expiry_date')->nullable();
            $table->boolean('is_moved_to_expired')->default(false);
        });

        Schema::table('purchase_items', function (Blueprint $table) {
            $table->index('expiry_date');
        });
    }
};
