<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Payments added from the purchases list were saved without supplier_id,
     * so they were missing from the suppliers summary (credit column).
     */
    public function up(): void
    {
        DB::table('purchase_payments')
            ->join('purchases', 'purchases.id', '=', 'purchase_payments.purchase_id')
            ->whereNull('purchase_payments.supplier_id')
            ->update(['purchase_payments.supplier_id' => DB::raw('purchases.supplier_id')]);
    }

    public function down(): void
    {
        // Irreversible data fix
    }
};
