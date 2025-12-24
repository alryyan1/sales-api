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
                'name' => 'مضاد للتقلصات',
                'description' => NULL,
                'parent_id' => NULL,
                'created_at' => '2025-11-25 23:05:29',
                'updated_at' => '2025-11-25 23:05:29',
            ),
            1 => 
            array (
                'id' => 2,
                'name' => 'فتمينات',
                'description' => NULL,
                'parent_id' => NULL,
                'created_at' => '2025-11-25 23:29:22',
                'updated_at' => '2025-11-25 23:29:22',
            ),
            2 => 
            array (
                'id' => 3,
                'name' => 'خافض للحرارة',
                'description' => NULL,
                'parent_id' => NULL,
                'created_at' => '2025-11-26 04:10:00',
                'updated_at' => '2025-11-26 04:10:00',
            ),
            3 => 
            array (
                'id' => 4,
                'name' => 'مضاد حيوي',
                'description' => NULL,
                'parent_id' => NULL,
                'created_at' => '2025-11-26 09:54:55',
                'updated_at' => '2025-11-26 09:54:55',
            ),
            4 => 
            array (
                'id' => 5,
                'name' => 'مضاد للنزلة',
                'description' => NULL,
                'parent_id' => NULL,
                'created_at' => '2025-11-26 10:16:34',
                'updated_at' => '2025-11-26 10:16:34',
            ),
            5 => 
            array (
                'id' => 6,
                'name' => 'طارد للبلغم',
                'description' => NULL,
                'parent_id' => NULL,
                'created_at' => '2025-11-27 01:17:01',
                'updated_at' => '2025-11-27 01:17:01',
            ),
            6 => 
            array (
                'id' => 7,
                'name' => 'حساسية',
                'description' => NULL,
                'parent_id' => NULL,
                'created_at' => '2025-11-27 01:19:21',
                'updated_at' => '2025-11-27 01:19:21',
            ),
            7 => 
            array (
                'id' => 8,
                'name' => 'مضاد حيوي شرب',
                'description' => NULL,
                'parent_id' => NULL,
                'created_at' => '2025-11-27 01:25:23',
                'updated_at' => '2025-11-27 01:25:23',
            ),
            8 => 
            array (
                'id' => 9,
                'name' => 'مضاد حيوي شراب',
                'description' => NULL,
                'parent_id' => NULL,
                'created_at' => '2025-11-27 01:25:41',
                'updated_at' => '2025-11-27 01:25:41',
            ),
            9 => 
            array (
                'id' => 10,
                'name' => 'فوار املح البول',
                'description' => NULL,
                'parent_id' => NULL,
                'created_at' => '2025-11-27 01:34:47',
                'updated_at' => '2025-11-27 01:34:47',
            ),
            10 => 
            array (
                'id' => 11,
                'name' => 'anti flatulence',
                'description' => NULL,
                'parent_id' => NULL,
                'created_at' => '2025-11-27 01:38:21',
                'updated_at' => '2025-11-27 01:38:21',
            ),
            11 => 
            array (
                'id' => 12,
                'name' => 'C-L-Agent',
                'description' => NULL,
                'parent_id' => NULL,
                'created_at' => '2025-11-27 02:34:49',
                'updated_at' => '2025-11-27 02:34:49',
            ),
            12 => 
            array (
                'id' => 13,
                'name' => 'DM',
                'description' => NULL,
                'parent_id' => NULL,
                'created_at' => '2025-11-27 02:53:58',
                'updated_at' => '2025-11-27 02:53:58',
            ),
            13 => 
            array (
                'id' => 14,
                'name' => 'مسكن للالم',
                'description' => NULL,
                'parent_id' => NULL,
                'created_at' => '2025-11-27 03:06:12',
                'updated_at' => '2025-11-27 03:06:12',
            ),
            14 => 
            array (
                'id' => 15,
                'name' => 'سكر',
                'description' => NULL,
                'parent_id' => NULL,
                'created_at' => '2025-11-27 03:19:02',
                'updated_at' => '2025-11-27 03:19:02',
            ),
            15 => 
            array (
                'id' => 16,
                'name' => 'Famila',
                'description' => NULL,
                'parent_id' => NULL,
                'created_at' => '2025-11-27 03:30:45',
                'updated_at' => '2025-11-27 03:30:45',
            ),
            16 => 
            array (
                'id' => 17,
                'name' => 'منظم سكري',
                'description' => NULL,
                'parent_id' => NULL,
                'created_at' => '2025-11-27 03:40:18',
                'updated_at' => '2025-11-27 03:40:18',
            ),
            17 => 
            array (
                'id' => 18,
                'name' => 'تجميل',
                'description' => NULL,
                'parent_id' => NULL,
                'created_at' => '2025-11-27 04:52:48',
                'updated_at' => '2025-11-27 04:52:48',
            ),
            18 => 
            array (
                'id' => 19,
                'name' => 'anti emit',
                'description' => NULL,
                'parent_id' => NULL,
                'created_at' => '2025-11-27 05:29:41',
                'updated_at' => '2025-11-27 05:29:41',
            ),
            19 => 
            array (
                'id' => 20,
                'name' => 'diuretic',
                'description' => NULL,
                'parent_id' => NULL,
                'created_at' => '2025-11-27 05:32:39',
                'updated_at' => '2025-11-27 05:32:39',
            ),
            20 => 
            array (
                'id' => 21,
                'name' => 'anti melaria',
                'description' => NULL,
                'parent_id' => NULL,
                'created_at' => '2025-11-27 05:48:35',
                'updated_at' => '2025-11-27 05:48:35',
            ),
            21 => 
            array (
                'id' => 22,
                'name' => 'relief of cold and flu',
                'description' => NULL,
                'parent_id' => NULL,
                'created_at' => '2025-11-27 06:38:43',
                'updated_at' => '2025-11-27 06:38:43',
            ),
            22 => 
            array (
                'id' => 23,
                'name' => 'امساك',
                'description' => NULL,
                'parent_id' => NULL,
                'created_at' => '2025-11-27 09:12:12',
                'updated_at' => '2025-11-27 09:12:12',
            ),
            23 => 
            array (
                'id' => 24,
                'name' => 'Anti hypertension',
                'description' => NULL,
                'parent_id' => NULL,
                'created_at' => '2025-11-27 09:50:51',
                'updated_at' => '2025-11-27 09:50:51',
            ),
            24 => 
            array (
                'id' => 25,
                'name' => 'thyroid gland',
                'description' => NULL,
                'parent_id' => NULL,
                'created_at' => '2025-11-28 00:48:02',
                'updated_at' => '2025-11-28 00:48:02',
            ),
            25 => 
            array (
                'id' => 26,
                'name' => 'ED antibiottic',
                'description' => NULL,
                'parent_id' => NULL,
                'created_at' => '2025-11-28 04:28:50',
                'updated_at' => '2025-11-28 04:28:50',
            ),
            26 => 
            array (
                'id' => 27,
                'name' => 'ED/ND',
                'description' => NULL,
                'parent_id' => NULL,
                'created_at' => '2025-11-28 04:39:57',
                'updated_at' => '2025-11-28 04:39:57',
            ),
            27 => 
            array (
                'id' => 28,
                'name' => 'طارد للحصاوي',
                'description' => NULL,
                'parent_id' => NULL,
                'created_at' => '2025-11-28 04:52:15',
                'updated_at' => '2025-11-28 04:52:15',
            ),
            28 => 
            array (
                'id' => 29,
                'name' => 'anti-fibrinolytic',
                'description' => NULL,
                'parent_id' => NULL,
                'created_at' => '2025-11-28 04:56:43',
                'updated_at' => '2025-11-28 04:56:43',
            ),
            29 => 
            array (
                'id' => 30,
                'name' => 'anti Dandruff',
                'description' => NULL,
                'parent_id' => NULL,
                'created_at' => '2025-11-28 05:41:37',
                'updated_at' => '2025-11-28 05:41:37',
            ),
            30 => 
            array (
                'id' => 31,
                'name' => 'واقي شمس',
                'description' => NULL,
                'parent_id' => NULL,
                'created_at' => '2025-11-28 05:52:24',
                'updated_at' => '2025-11-28 05:52:24',
            ),
            31 => 
            array (
                'id' => 32,
                'name' => 'ppi',
                'description' => NULL,
                'parent_id' => NULL,
                'created_at' => '2025-11-28 06:55:59',
                'updated_at' => '2025-11-28 06:55:59',
            ),
            32 => 
            array (
                'id' => 33,
                'name' => 'antithroid',
                'description' => NULL,
                'parent_id' => NULL,
                'created_at' => '2025-11-28 09:12:30',
                'updated_at' => '2025-11-28 09:12:30',
            ),
            33 => 
            array (
                'id' => 34,
                'name' => 'N/Spray',
                'description' => NULL,
                'parent_id' => NULL,
                'created_at' => '2025-11-28 09:19:44',
                'updated_at' => '2025-11-28 09:19:44',
            ),
            34 => 
            array (
                'id' => 35,
                'name' => 'antiACID',
                'description' => NULL,
                'parent_id' => NULL,
                'created_at' => '2025-11-29 01:22:27',
                'updated_at' => '2025-11-29 01:22:27',
            ),
            35 => 
            array (
                'id' => 36,
                'name' => 'مثبط للمناعه',
                'description' => NULL,
                'parent_id' => NULL,
                'created_at' => '2025-11-29 01:30:34',
                'updated_at' => '2025-11-29 01:30:34',
            ),
            36 => 
            array (
                'id' => 37,
                'name' => 'Anaesthetic',
                'description' => NULL,
                'parent_id' => NULL,
                'created_at' => '2025-11-29 04:04:01',
                'updated_at' => '2025-11-29 04:04:01',
            ),
            37 => 
            array (
                'id' => 38,
                'name' => 'anti viral',
                'description' => NULL,
                'parent_id' => NULL,
                'created_at' => '2025-11-29 05:16:44',
                'updated_at' => '2025-11-29 05:16:44',
            ),
            38 => 
            array (
                'id' => 39,
                'name' => 'CNS',
                'description' => NULL,
                'parent_id' => NULL,
                'created_at' => '2025-11-29 05:29:47',
                'updated_at' => '2025-11-29 05:29:47',
            ),
            39 => 
            array (
                'id' => 40,
                'name' => 'Antiarrhythmi',
                'description' => NULL,
                'parent_id' => NULL,
                'created_at' => '2025-11-29 07:38:06',
                'updated_at' => '2025-11-29 07:38:06',
            ),
            40 => 
            array (
                'id' => 41,
                'name' => 'antiasthmatic',
                'description' => NULL,
                'parent_id' => NULL,
                'created_at' => '2025-11-29 08:58:49',
                'updated_at' => '2025-11-29 08:58:49',
            ),
            41 => 
            array (
                'id' => 42,
                'name' => 'mouthwash',
                'description' => NULL,
                'parent_id' => NULL,
                'created_at' => '2025-11-29 09:00:17',
                'updated_at' => '2025-11-29 09:00:17',
            ),
            42 => 
            array (
                'id' => 43,
                'name' => 'antifungal-ED',
                'description' => NULL,
                'parent_id' => NULL,
                'created_at' => '2025-11-29 09:03:42',
                'updated_at' => '2025-11-29 09:03:42',
            ),
            43 => 
            array (
                'id' => 44,
                'name' => 'antifungal',
                'description' => NULL,
                'parent_id' => NULL,
                'created_at' => '2025-11-29 10:09:10',
                'updated_at' => '2025-11-29 10:09:10',
            ),
            44 => 
            array (
                'id' => 45,
                'name' => 'Corticosteroid',
                'description' => NULL,
                'parent_id' => NULL,
                'created_at' => '2025-11-29 23:05:35',
                'updated_at' => '2025-11-29 23:05:35',
            ),
            45 => 
            array (
                'id' => 46,
                'name' => 'fluid',
                'description' => NULL,
                'parent_id' => NULL,
                'created_at' => '2025-11-29 23:11:28',
                'updated_at' => '2025-11-29 23:11:28',
            ),
            46 => 
            array (
                'id' => 47,
                'name' => 'Aromatase Inhibitor',
                'description' => NULL,
                'parent_id' => NULL,
                'created_at' => '2025-11-30 01:39:42',
                'updated_at' => '2025-11-30 01:39:42',
            ),
            47 => 
            array (
                'id' => 48,
                'name' => 'dopamine receptors',
                'description' => NULL,
                'parent_id' => NULL,
                'created_at' => '2025-11-30 01:43:29',
                'updated_at' => '2025-11-30 01:43:29',
            ),
            48 => 
            array (
                'id' => 49,
                'name' => 'progesterone',
                'description' => NULL,
                'parent_id' => NULL,
                'created_at' => '2025-11-30 01:55:24',
                'updated_at' => '2025-11-30 01:55:24',
            ),
            49 => 
            array (
                'id' => 50,
                'name' => 'ED',
                'description' => NULL,
                'parent_id' => NULL,
                'created_at' => '2025-11-30 06:16:50',
                'updated_at' => '2025-11-30 06:16:50',
            ),
            50 => 
            array (
                'id' => 51,
                'name' => 'Anti hypertensive',
                'description' => NULL,
                'parent_id' => NULL,
                'created_at' => '2025-12-04 14:34:22',
                'updated_at' => '2025-12-04 14:34:22',
            ),
        ));
        
        
    }
}