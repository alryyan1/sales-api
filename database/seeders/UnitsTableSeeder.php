<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class UnitsTableSeeder extends Seeder
{

    /**
     * Auto generated seed file
     *
     * @return void
     */
    public function run()
    {
        

        \DB::table('units')->delete();
        
        \DB::table('units')->insert(array (
            0 => 
            array (
                'id' => 1,
                'name' => 'Pieces',
                'type' => 'stocking',
                'description' => NULL,
                'is_active' => 1,
                'is_default' => 1,
                'created_at' => '2026-01-30 17:09:09',
                'updated_at' => '2026-01-30 17:09:09',
            ),
            1 => 
            array (
                'id' => 2,
                'name' => 'Piece',
                'type' => 'sellable',
                'description' => NULL,
                'is_active' => 1,
                'is_default' => 1,
                'created_at' => '2026-01-30 17:09:35',
                'updated_at' => '2026-01-30 17:09:35',
            ),
            2 => 
            array (
                'id' => 3,
                'name' => 'Tube',
                'type' => 'stocking',
                'description' => NULL,
                'is_active' => 1,
                'is_default' => 0,
                'created_at' => '2026-01-30 17:19:26',
                'updated_at' => '2026-01-30 17:19:26',
            ),
            3 => 
            array (
                'id' => 4,
                'name' => 'Tube',
                'type' => 'sellable',
                'description' => NULL,
                'is_active' => 1,
                'is_default' => 0,
                'created_at' => '2026-01-30 17:20:47',
                'updated_at' => '2026-01-30 17:20:47',
            ),
            4 => 
            array (
                'id' => 5,
                'name' => 'Bottle',
                'type' => 'stocking',
                'description' => NULL,
                'is_active' => 1,
                'is_default' => 0,
                'created_at' => '2026-01-30 17:34:50',
                'updated_at' => '2026-01-30 17:34:50',
            ),
            5 => 
            array (
                'id' => 6,
                'name' => 'Bottle',
                'type' => 'sellable',
                'description' => NULL,
                'is_active' => 1,
                'is_default' => 0,
                'created_at' => '2026-01-30 17:34:57',
                'updated_at' => '2026-01-30 17:34:57',
            ),
            6 => 
            array (
                'id' => 7,
                'name' => 'Stripes',
                'type' => 'stocking',
                'description' => NULL,
                'is_active' => 1,
                'is_default' => 0,
                'created_at' => '2026-01-30 17:47:02',
                'updated_at' => '2026-01-30 17:47:02',
            ),
            7 => 
            array (
                'id' => 8,
                'name' => 'Stripe',
                'type' => 'sellable',
                'description' => NULL,
                'is_active' => 1,
                'is_default' => 0,
                'created_at' => '2026-01-30 17:47:31',
                'updated_at' => '2026-01-30 17:47:31',
            ),
            8 => 
            array (
                'id' => 9,
                'name' => 'Pessaries',
                'type' => 'stocking',
                'description' => NULL,
                'is_active' => 1,
                'is_default' => 0,
                'created_at' => '2026-01-30 17:50:16',
                'updated_at' => '2026-01-30 17:50:16',
            ),
            9 => 
            array (
                'id' => 10,
                'name' => 'Pessaries',
                'type' => 'sellable',
                'description' => NULL,
                'is_active' => 1,
                'is_default' => 0,
                'created_at' => '2026-01-30 17:50:36',
                'updated_at' => '2026-01-30 17:50:36',
            ),
            10 => 
            array (
                'id' => 11,
                'name' => 'Ointment',
                'type' => 'stocking',
                'description' => NULL,
                'is_active' => 1,
                'is_default' => 0,
                'created_at' => '2026-01-30 17:59:12',
                'updated_at' => '2026-01-30 17:59:12',
            ),
            11 => 
            array (
                'id' => 12,
                'name' => 'Cream',
                'type' => 'stocking',
                'description' => NULL,
                'is_active' => 1,
                'is_default' => 0,
                'created_at' => '2026-01-30 18:00:41',
                'updated_at' => '2026-01-30 18:00:41',
            ),
            12 => 
            array (
                'id' => 13,
                'name' => 'Box',
                'type' => 'stocking',
                'description' => NULL,
                'is_active' => 1,
                'is_default' => 0,
                'created_at' => '2026-01-30 18:46:08',
                'updated_at' => '2026-01-30 18:46:08',
            ),
            13 => 
            array (
                'id' => 14,
                'name' => 'Vial',
                'type' => 'sellable',
                'description' => NULL,
                'is_active' => 1,
                'is_default' => 0,
                'created_at' => '2026-01-30 18:47:52',
                'updated_at' => '2026-01-30 18:47:52',
            ),
            14 => 
            array (
                'id' => 15,
                'name' => 'Ampoul',
                'type' => 'sellable',
                'description' => NULL,
                'is_active' => 1,
                'is_default' => 0,
                'created_at' => '2026-01-30 18:48:53',
                'updated_at' => '2026-01-30 18:48:53',
            ),
            15 => 
            array (
                'id' => 16,
                'name' => 'Sachet',
                'type' => 'sellable',
                'description' => NULL,
                'is_active' => 1,
                'is_default' => 0,
                'created_at' => '2026-01-30 19:40:02',
                'updated_at' => '2026-01-30 19:40:02',
            ),
            16 => 
            array (
                'id' => 17,
                'name' => 'Drop',
                'type' => 'sellable',
                'description' => NULL,
                'is_active' => 1,
                'is_default' => 0,
                'created_at' => '2026-01-30 20:36:42',
                'updated_at' => '2026-01-30 20:36:42',
            ),
            17 => 
            array (
                'id' => 18,
                'name' => 'Spray',
                'type' => 'sellable',
                'description' => NULL,
                'is_active' => 1,
                'is_default' => 0,
                'created_at' => '2026-01-30 20:58:00',
                'updated_at' => '2026-01-30 20:58:00',
            ),
            18 => 
            array (
                'id' => 19,
                'name' => 'Tab',
                'type' => 'sellable',
                'description' => NULL,
                'is_active' => 1,
                'is_default' => 0,
                'created_at' => '2026-01-30 21:29:55',
                'updated_at' => '2026-01-30 21:29:55',
            ),
            19 => 
            array (
                'id' => 20,
                'name' => 'Effervescent',
                'type' => 'sellable',
                'description' => NULL,
                'is_active' => 1,
                'is_default' => 0,
                'created_at' => '2026-01-31 18:03:01',
                'updated_at' => '2026-01-31 18:03:01',
            ),
            20 => 
            array (
                'id' => 21,
                'name' => 'Box',
                'type' => 'sellable',
                'description' => NULL,
                'is_active' => 1,
                'is_default' => 0,
                'created_at' => '2026-01-31 21:01:16',
                'updated_at' => '2026-01-31 21:01:16',
            ),
            21 => 
            array (
                'id' => 22,
                'name' => 'Vial',
                'type' => 'stocking',
                'description' => 'VIAL',
                'is_active' => 1,
                'is_default' => 0,
                'created_at' => '2026-02-13 00:14:57',
                'updated_at' => '2026-02-13 00:14:57',
            ),
        ));
        
        
    }
}