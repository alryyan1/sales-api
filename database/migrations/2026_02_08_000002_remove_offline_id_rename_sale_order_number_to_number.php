<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Remove offline_id; rename sale_order_number to number.
     */
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            if (Schema::hasColumn('sales', 'offline_id')) {
                $table->dropColumn('offline_id');
            }
        });

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql' || $driver === 'mariadb') {
            $dbName = Schema::getConnection()->getDatabaseName();
            $hasUnique = DB::selectOne("
                SELECT 1 FROM information_schema.statistics
                WHERE table_schema = ? AND table_name = 'sales' AND index_name = 'sales_shift_order_unique'
            ", [$dbName]);
            if ($hasUnique) {
                Schema::table('sales', function (Blueprint $table) {
                    $table->index('shift_id', 'sales_shift_id_index');
                });
                Schema::table('sales', function (Blueprint $table) {
                    $table->dropUnique('sales_shift_order_unique');
                });
            }
            if (Schema::hasColumn('sales', 'sale_order_number')) {
                DB::statement('ALTER TABLE sales CHANGE sale_order_number number INT UNSIGNED NULL');
            }
            if ($hasUnique) {
                Schema::table('sales', function (Blueprint $table) {
                    $table->unique(['shift_id', 'number'], 'sales_shift_order_unique');
                });
                Schema::table('sales', function (Blueprint $table) {
                    $table->dropIndex('sales_shift_id_index');
                });
            }
        } else {
            if (Schema::hasColumn('sales', 'sale_order_number')) {
                try {
                    Schema::table('sales', function (Blueprint $table) {
                        $table->dropUnique('sales_shift_order_unique');
                    });
                } catch (\Throwable $e) {
                    // ignore if index does not exist
                }
                Schema::table('sales', function (Blueprint $table) {
                    $table->renameColumn('sale_order_number', 'number');
                });
                Schema::table('sales', function (Blueprint $table) {
                    $table->unique(['shift_id', 'number'], 'sales_shift_order_unique');
                });
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'mysql' || $driver === 'mariadb') {
            $dbName = Schema::getConnection()->getDatabaseName();
            $hasUnique = DB::selectOne("
                SELECT 1 FROM information_schema.statistics
                WHERE table_schema = ? AND table_name = 'sales' AND index_name = 'sales_shift_order_unique'
            ", [$dbName]);
            if ($hasUnique && Schema::hasColumn('sales', 'number')) {
                Schema::table('sales', function (Blueprint $table) {
                    $table->index('shift_id', 'sales_shift_id_index');
                });
                Schema::table('sales', function (Blueprint $table) {
                    $table->dropUnique('sales_shift_order_unique');
                });
                DB::statement('ALTER TABLE sales CHANGE number sale_order_number INT UNSIGNED NULL');
                Schema::table('sales', function (Blueprint $table) {
                    $table->unique(['shift_id', 'sale_order_number'], 'sales_shift_order_unique');
                });
                Schema::table('sales', function (Blueprint $table) {
                    $table->dropIndex('sales_shift_id_index');
                });
            }
        } else {
            Schema::table('sales', function (Blueprint $table) {
                $table->dropUnique('sales_shift_order_unique');
            });
            Schema::table('sales', function (Blueprint $table) {
                $table->renameColumn('number', 'sale_order_number');
            });
            Schema::table('sales', function (Blueprint $table) {
                $table->unique(['shift_id', 'sale_order_number'], 'sales_shift_order_unique');
            });
        }
        if (!Schema::hasColumn('sales', 'offline_id')) {
            Schema::table('sales', function (Blueprint $table) {
                $table->string('offline_id')->nullable()->unique()->after('id');
            });
        }
    }
};
