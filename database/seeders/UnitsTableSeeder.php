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
                'name' => 'box',
                'type' => 'stocking',
                'description' => NULL,
                'is_active' => 1,
                'created_at' => '2025-11-25 23:05:56',
                'updated_at' => '2025-11-25 23:05:56',
            ),
            1 => 
            array (
                'id' => 2,
                'name' => 'strip',
                'type' => 'sellable',
                'description' => NULL,
                'is_active' => 1,
                'created_at' => '2025-11-25 23:06:17',
                'updated_at' => '2025-11-25 23:06:17',
            ),
            2 => 
            array (
                'id' => 3,
                'name' => 'bottle',
                'type' => 'sellable',
                'description' => NULL,
                'is_active' => 1,
                'created_at' => '2025-11-25 23:13:17',
                'updated_at' => '2025-11-25 23:13:17',
            ),
            3 => 
            array (
                'id' => 4,
                'name' => 'box',
                'type' => 'sellable',
                'description' => NULL,
                'is_active' => 1,
                'created_at' => '2025-11-27 01:35:42',
                'updated_at' => '2025-11-27 01:35:42',
            ),
            4 => 
            array (
                'id' => 5,
                'name' => 'bottle',
                'type' => 'stocking',
                'description' => NULL,
                'is_active' => 1,
                'created_at' => '2025-11-27 02:06:07',
                'updated_at' => '2025-11-27 02:06:07',
            ),
            5 => 
            array (
                'id' => 6,
                'name' => 'vial',
                'type' => 'sellable',
                'description' => NULL,
                'is_active' => 1,
                'created_at' => '2025-11-27 02:18:02',
                'updated_at' => '2025-11-27 02:18:02',
            ),
            6 => 
            array (
                'id' => 7,
                'name' => 'vial',
                'type' => 'stocking',
                'description' => NULL,
                'is_active' => 1,
                'created_at' => '2025-11-27 02:18:20',
                'updated_at' => '2025-11-27 02:18:20',
            ),
            7 => 
            array (
                'id' => 8,
                'name' => 'ampoule',
                'type' => 'sellable',
                'description' => NULL,
                'is_active' => 1,
                'created_at' => '2025-11-27 02:27:49',
                'updated_at' => '2025-11-27 02:27:49',
            ),
            8 => 
            array (
                'id' => 9,
                'name' => 'Sachets',
                'type' => 'sellable',
                'description' => NULL,
                'is_active' => 1,
                'created_at' => '2025-11-27 03:27:03',
                'updated_at' => '2025-11-27 03:27:03',
            ),
            9 => 
            array (
                'id' => 10,
                'name' => 'drop',
                'type' => 'stocking',
                'description' => NULL,
                'is_active' => 1,
                'created_at' => '2025-11-28 04:29:19',
                'updated_at' => '2025-11-28 04:29:19',
            ),
            10 => 
            array (
                'id' => 11,
                'name' => 'drop',
                'type' => 'sellable',
                'description' => NULL,
                'is_active' => 1,
                'created_at' => '2025-11-28 04:29:32',
                'updated_at' => '2025-11-28 04:29:32',
            ),
            11 => 
            array (
                'id' => 12,
                'name' => 'drip',
                'type' => 'stocking',
                'description' => NULL,
                'is_active' => 1,
                'created_at' => '2025-11-28 05:00:24',
                'updated_at' => '2025-11-28 05:00:24',
            ),
            12 => 
            array (
                'id' => 13,
                'name' => 'drip',
                'type' => 'sellable',
                'description' => NULL,
                'is_active' => 1,
                'created_at' => '2025-11-28 05:00:35',
                'updated_at' => '2025-11-28 05:00:35',
            ),
            13 => 
            array (
                'id' => 14,
                'name' => 'soap',
                'type' => 'stocking',
                'description' => NULL,
                'is_active' => 1,
                'created_at' => '2025-11-28 05:48:06',
                'updated_at' => '2025-11-28 05:48:06',
            ),
            14 => 
            array (
                'id' => 15,
                'name' => 'soap',
                'type' => 'sellable',
                'description' => NULL,
                'is_active' => 1,
                'created_at' => '2025-11-28 05:48:31',
                'updated_at' => '2025-11-28 05:48:31',
            ),
            15 => 
            array (
                'id' => 16,
                'name' => 'gel',
                'type' => 'stocking',
                'description' => NULL,
                'is_active' => 1,
                'created_at' => '2025-11-28 05:52:53',
                'updated_at' => '2025-11-28 05:52:53',
            ),
            16 => 
            array (
                'id' => 17,
                'name' => 'gel',
                'type' => 'sellable',
                'description' => NULL,
                'is_active' => 1,
                'created_at' => '2025-11-28 05:53:05',
                'updated_at' => '2025-11-28 05:53:05',
            ),
            17 => 
            array (
                'id' => 18,
                'name' => 'tablets',
                'type' => 'sellable',
                'description' => NULL,
                'is_active' => 1,
                'created_at' => '2025-11-28 06:24:42',
                'updated_at' => '2025-11-28 06:24:42',
            ),
            18 => 
            array (
                'id' => 19,
                'name' => 'cream',
                'type' => 'sellable',
                'description' => NULL,
                'is_active' => 1,
                'created_at' => '2025-11-29 01:38:29',
                'updated_at' => '2025-11-29 01:38:29',
            ),
            19 => 
            array (
                'id' => 20,
                'name' => 'mask',
                'type' => 'sellable',
                'description' => NULL,
                'is_active' => 1,
                'created_at' => '2025-11-29 01:41:57',
                'updated_at' => '2025-11-29 01:41:57',
            ),
            20 => 
            array (
                'id' => 21,
                'name' => 'mask',
                'type' => 'stocking',
                'description' => NULL,
                'is_active' => 1,
                'created_at' => '2025-11-29 01:42:08',
                'updated_at' => '2025-11-29 01:42:08',
            ),
            21 => 
            array (
                'id' => 22,
                'name' => 'pcs',
                'type' => 'sellable',
                'description' => NULL,
                'is_active' => 1,
                'created_at' => '2025-11-29 05:21:20',
                'updated_at' => '2025-11-29 05:21:20',
            ),
            22 => 
            array (
                'id' => 23,
                'name' => 'strip',
                'type' => 'stocking',
                'description' => NULL,
                'is_active' => 1,
                'created_at' => '2025-11-29 08:17:48',
                'updated_at' => '2025-11-29 08:17:48',
            ),
            23 => 
            array (
                'id' => 24,
                'name' => 'علبه',
                'type' => 'sellable',
                'description' => NULL,
                'is_active' => 1,
                'created_at' => '2025-11-29 23:59:29',
                'updated_at' => '2025-11-29 23:59:29',
            ),
            24 => 
            array (
                'id' => 25,
                'name' => 'علبه',
                'type' => 'stocking',
                'description' => NULL,
                'is_active' => 1,
                'created_at' => '2025-11-29 23:59:42',
                'updated_at' => '2025-11-29 23:59:42',
            ),
            25 => 
            array (
                'id' => 26,
                'name' => 'ampoule',
                'type' => 'stocking',
                'description' => NULL,
                'is_active' => 1,
                'created_at' => '2025-12-01 02:34:31',
                'updated_at' => '2025-12-01 02:34:31',
            ),
            26 => 
            array (
                'id' => 27,
                'name' => 'pcs',
                'type' => 'stocking',
                'description' => NULL,
                'is_active' => 1,
                'created_at' => '2025-12-06 06:48:30',
                'updated_at' => '2025-12-06 06:48:30',
            ),
        ));
        
        
    }
}