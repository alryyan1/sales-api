<?php

namespace Database\Factories;

use App\Models\Supplier; // Ensure correct model import
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Supplier>
 */
class SupplierFactory extends Factory
{
     /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = Supplier::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company(), // Use company name for supplier
            'contact_person' => fake()->optional(0.9)->name(), // 90% chance of having a contact person
            'email' => fake()->optional(0.85)->unique()?->safeEmail(), // 85% chance, unique
            'phone' => fake()->optional(0.8)->phoneNumber(), // 80% chance
            'address' => fake()->optional(0.7)->address(), // 70% chance
            // 'website' => fake()->optional(0.5)->url(), // Example if added
            // 'notes' => fake()->optional(0.3)->paragraph(), // Example if added
        ];
    }
}