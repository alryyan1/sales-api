<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Drop old FK on purchase_id, make it nullable, add supplier_id and type
        // We use raw SQL because Doctrine DBAL has issues modifying FK columns on MySQL
        
        // First: find existing FK constraint name for purchase_id
        $fkName = DB::selectOne("
            SELECT CONSTRAINT_NAME
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'purchase_payments'
              AND COLUMN_NAME = 'purchase_id'
              AND REFERENCED_TABLE_NAME = 'purchases'
        ");

        if ($fkName) {
            DB::statement("ALTER TABLE `purchase_payments` DROP FOREIGN KEY `{$fkName->CONSTRAINT_NAME}`");
        }

        // Make purchase_id nullable
        DB::statement("ALTER TABLE `purchase_payments` MODIFY COLUMN `purchase_id` BIGINT UNSIGNED NULL");

        // Re-add the FK as nullable
        DB::statement("ALTER TABLE `purchase_payments` ADD CONSTRAINT `purchase_payments_purchase_id_foreign` FOREIGN KEY (`purchase_id`) REFERENCES `purchases` (`id`) ON DELETE CASCADE");

        // Add supplier_id (nullable FK)
        Schema::table('purchase_payments', function (Blueprint $table) {
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->onDelete('cascade')->after('purchase_id');
            $table->enum('type', ['payment', 'credit', 'adjustment'])->default('payment')->after('supplier_id');
        });

        // 2. Drop the old supplier_payments table
        Schema::dropIfExists('supplier_payments');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Drop columns added
        Schema::table('purchase_payments', function (Blueprint $table) {
            $table->dropForeign(['supplier_id']);
            $table->dropColumn(['supplier_id', 'type']);
        });

        // Restore purchase_id as non-nullable
        $fkName = DB::selectOne("
            SELECT CONSTRAINT_NAME
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'purchase_payments'
              AND COLUMN_NAME = 'purchase_id'
        ");
        if ($fkName) {
            DB::statement("ALTER TABLE `purchase_payments` DROP FOREIGN KEY `{$fkName->CONSTRAINT_NAME}`");
        }
        DB::statement("ALTER TABLE `purchase_payments` MODIFY COLUMN `purchase_id` BIGINT UNSIGNED NOT NULL");
        DB::statement("ALTER TABLE `purchase_payments` ADD CONSTRAINT `purchase_payments_purchase_id_foreign` FOREIGN KEY (`purchase_id`) REFERENCES `purchases` (`id`) ON DELETE CASCADE");

        // Restore supplier_payments table
        Schema::create('supplier_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->decimal('amount', 15, 2);
            $table->enum('type', ['payment', 'credit', 'adjustment']);
            $table->enum('method', ['cash', 'bank_transfer', 'check', 'credit_card', 'other']);
            $table->string('reference_number')->nullable();
            $table->text('notes')->nullable();
            $table->date('payment_date');
            $table->timestamps();
        });
    }
};
