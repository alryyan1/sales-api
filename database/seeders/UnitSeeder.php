<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class UnitSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $stockingUnits = [
            ['name' => 'Box', 'type' => 'stocking', 'description' => 'Cardboard box container'],
            ['name' => 'Carton', 'type' => 'stocking', 'description' => 'Large cardboard container'],
            ['name' => 'Pack', 'type' => 'stocking', 'description' => 'Packaged items'],
            ['name' => 'Bundle', 'type' => 'stocking', 'description' => 'Grouped items tied together'],
            ['name' => 'Pallet', 'type' => 'stocking', 'description' => 'Wooden platform for stacking'],
            ['name' => 'Container', 'type' => 'stocking', 'description' => 'Large storage container'],
            ['name' => 'Bag', 'type' => 'stocking', 'description' => 'Flexible container'],
            ['name' => 'Case', 'type' => 'stocking', 'description' => 'Protective container'],
            ['name' => 'Crate', 'type' => 'stocking', 'description' => 'Wooden container'],
            ['name' => 'Drum', 'type' => 'stocking', 'description' => 'Cylindrical container'],
        ];

        $sellableUnits = [
            ['name' => 'Piece', 'type' => 'sellable', 'description' => 'Individual item'],
            ['name' => 'Item', 'type' => 'sellable', 'description' => 'Single product unit'],
            ['name' => 'Unit', 'type' => 'sellable', 'description' => 'Standard unit of measurement'],
            ['name' => 'Bottle', 'type' => 'sellable', 'description' => 'Container with liquid'],
            ['name' => 'Can', 'type' => 'sellable', 'description' => 'Metal container'],
            ['name' => 'Pack', 'type' => 'sellable', 'description' => 'Small package'],
            ['name' => 'Set', 'type' => 'sellable', 'description' => 'Group of related items'],
            ['name' => 'Pair', 'type' => 'sellable', 'description' => 'Two items together'],
            ['name' => 'Dozen', 'type' => 'sellable', 'description' => 'Twelve items'],
            ['name' => 'Meter', 'type' => 'sellable', 'description' => 'Length measurement'],
            ['name' => 'Kilogram', 'type' => 'sellable', 'description' => 'Weight measurement'],
            ['name' => 'Liter', 'type' => 'sellable', 'description' => 'Volume measurement'],
        ];

        $allUnits = array_merge($stockingUnits, $sellableUnits);

        foreach ($allUnits as $unit) {
            DB::table('units')->insert([
                'name' => $unit['name'],
                'type' => $unit['type'],
                'description' => $unit['description'],
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
