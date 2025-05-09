<?php // database/factories/CategoryFactory.php
namespace Database\Factories;

use App\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;

class CategoryFactory extends Factory
{
    protected $model = Category::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(rand(1, 3), true), // Unique category name
            'description' => fake()->optional(0.7)->sentence(),
            // 'parent_id' => null, // Default to top-level category
            // To create subcategories: Category::factory()->state(['parent_id' => $parentCategory->id])->create();
        ];
    }

    // Optional state for creating a subcategory
    public function subCategory(Category $parentCategory): Factory
    {
        return $this->state(fn (array $attributes) => [
            'parent_id' => $parentCategory->id,
        ]);
    }
}