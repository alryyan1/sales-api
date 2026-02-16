<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class CategoriesTableSeeder extends Seeder
{

    /**
     * Auto generated seed file
     *
     * @return void
     */
    public function run()
    {
        

        \DB::table('categories')->delete();
        
        \DB::table('categories')->insert(array (
            0 => 
            array (
                'id' => 1,
                'name' => 'منتجات صحية',
                'description' => NULL,
                'parent_id' => NULL,
                'is_default' => 1,
                'created_at' => '2026-01-30 17:05:14',
                'updated_at' => '2026-01-30 17:05:14',
            ),
            1 => 
            array (
                'id' => 2,
                'name' => 'Corticosteroid',
                'description' => NULL,
                'parent_id' => NULL,
                'is_default' => 0,
                'created_at' => '2026-01-30 17:18:37',
                'updated_at' => '2026-01-30 17:18:37',
            ),
            2 => 
            array (
                'id' => 3,
                'name' => 'Anti-fungal',
                'description' => NULL,
                'parent_id' => NULL,
                'is_default' => 0,
                'created_at' => '2026-01-30 17:24:32',
                'updated_at' => '2026-01-30 17:24:32',
            ),
            3 => 
            array (
                'id' => 4,
                'name' => 'Antibiotics',
                'description' => NULL,
                'parent_id' => NULL,
                'is_default' => 0,
                'created_at' => '2026-01-30 17:31:11',
                'updated_at' => '2026-01-30 17:31:11',
            ),
            4 => 
            array (
                'id' => 5,
                'name' => 'Infusion',
                'description' => NULL,
                'parent_id' => NULL,
                'is_default' => 0,
                'created_at' => '2026-01-30 17:33:38',
                'updated_at' => '2026-01-30 17:33:38',
            ),
            5 => 
            array (
                'id' => 6,
                'name' => 'Vitamin',
                'description' => NULL,
                'parent_id' => NULL,
                'is_default' => 0,
                'created_at' => '2026-01-30 17:46:27',
                'updated_at' => '2026-01-30 17:46:27',
            ),
            6 => 
            array (
                'id' => 7,
                'name' => 'Cosmetics',
                'description' => NULL,
                'parent_id' => NULL,
                'is_default' => 0,
                'created_at' => '2026-01-30 18:44:29',
                'updated_at' => '2026-01-30 18:44:29',
            ),
            7 => 
            array (
                'id' => 8,
                'name' => 'Anti-Virus',
                'description' => NULL,
                'parent_id' => NULL,
                'is_default' => 0,
                'created_at' => '2026-01-30 18:45:19',
                'updated_at' => '2026-01-30 18:45:19',
            ),
            8 => 
            array (
                'id' => 9,
                'name' => 'Anesthetics',
                'description' => NULL,
                'parent_id' => NULL,
                'is_default' => 0,
                'created_at' => '2026-01-30 18:57:24',
                'updated_at' => '2026-01-30 18:57:24',
            ),
            9 => 
            array (
                'id' => 10,
                'name' => 'Hemorrhoids',
                'description' => NULL,
                'parent_id' => NULL,
                'is_default' => 0,
                'created_at' => '2026-01-30 18:59:34',
                'updated_at' => '2026-01-30 18:59:34',
            ),
            10 => 
            array (
                'id' => 11,
                'name' => 'Wound-healing',
                'description' => NULL,
                'parent_id' => NULL,
                'is_default' => 0,
                'created_at' => '2026-01-30 19:30:19',
                'updated_at' => '2026-01-30 19:30:19',
            ),
            11 => 
            array (
                'id' => 12,
                'name' => 'Antiseptic-Disinfectants',
                'description' => NULL,
                'parent_id' => NULL,
                'is_default' => 0,
                'created_at' => '2026-01-30 19:35:20',
                'updated_at' => '2026-01-30 19:35:20',
            ),
            12 => 
            array (
                'id' => 13,
                'name' => 'Urinary Antiseptic',
                'description' => NULL,
                'parent_id' => NULL,
                'is_default' => 0,
                'created_at' => '2026-01-30 19:36:57',
                'updated_at' => '2026-01-30 19:36:57',
            ),
            13 => 
            array (
                'id' => 14,
                'name' => 'Urinary Alkalinizing agents',
                'description' => NULL,
                'parent_id' => NULL,
                'is_default' => 0,
                'created_at' => '2026-01-30 19:39:22',
                'updated_at' => '2026-01-30 19:39:22',
            ),
            14 => 
            array (
                'id' => 15,
                'name' => 'Urological Suport Agent',
                'description' => NULL,
                'parent_id' => NULL,
                'is_default' => 0,
                'created_at' => '2026-01-30 19:42:01',
                'updated_at' => '2026-01-30 19:42:01',
            ),
            15 => 
            array (
                'id' => 16,
                'name' => 'Xanthine oxidase Inhibitor',
                'description' => NULL,
                'parent_id' => NULL,
                'is_default' => 0,
                'created_at' => '2026-01-30 19:44:49',
                'updated_at' => '2026-01-30 19:44:49',
            ),
            16 => 
            array (
                'id' => 17,
                'name' => 'CNS',
                'description' => NULL,
                'parent_id' => NULL,
                'is_default' => 0,
                'created_at' => '2026-02-03 16:39:18',
                'updated_at' => '2026-02-03 16:39:18',
            ),
            17 => 
            array (
                'id' => 18,
                'name' => 'GIT',
                'description' => NULL,
                'parent_id' => NULL,
                'is_default' => 0,
                'created_at' => '2026-02-03 16:41:35',
                'updated_at' => '2026-02-03 16:41:35',
            ),
            18 => 
            array (
                'id' => 19,
                'name' => 'MUCOLYTIC',
                'description' => NULL,
                'parent_id' => NULL,
                'is_default' => 0,
                'created_at' => '2026-02-03 16:45:14',
                'updated_at' => '2026-02-03 16:45:14',
            ),
        ));
        
        
    }
}