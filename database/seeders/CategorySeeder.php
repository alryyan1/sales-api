<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Category::factory()->count(5)->create(); // Create 5 top-level categories
        $parentCat = Category::factory()->create(['name' => 'Electronics']);
        Category::factory()->count(3)->state(['parent_id' => $parentCat->id])->create();
    }
}
