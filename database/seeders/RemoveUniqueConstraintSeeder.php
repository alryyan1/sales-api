<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

class RemoveUniqueConstraintSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            $table->dropUnique('unique_sale_product');
        });
    }
}
