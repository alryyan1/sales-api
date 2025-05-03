<?php

namespace Database\Factories;

// Ensure the correct Client model is imported
use App\Models\Client;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Client>
 */
class ClientFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = Client::class; // This should be automatically set by the --model flag

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed> defines the structure of the fake data
     */
    public function definition(): array
    {
        return [
            // Attribute name => Faker data generation
            'name' => fake()->name(), // Generates a realistic full name
            'email' => fake()->unique()->safeEmail(), // Generates a unique, valid-looking email address
            'phone' => fake()->optional(0.8)->phoneNumber(), // 80% chance of having a phone number, otherwise null
            'address' => fake()->optional(0.7)->address(), // 70% chance of having an address, otherwise null

            // 'created_at' and 'updated_at' are usually handled automatically
            // by Eloquent's timestamps, so no need to define them here unless
            // you need specific dates.
            // 'created_at' => fake()->dateTimeBetween('-1 year', 'now'),
            // 'updated_at' => fake()->dateTimeBetween('-1 year', 'now'),
        ];
    }

    /**
     * Indicate that the client should not have an email (Example State).
     * You can define different states for specific scenarios.
     * Usage: Client::factory()->withNoEmail()->create();
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function withNoEmail(): Factory
    {
        return $this->state(function (array $attributes) {
            return [
                'email' => null,
            ];
        });
    }

     /**
      * Indicate that the client should not have a phone (Example State).
      *
      * @return \Illuminate\Database\Eloquent\Factories\Factory
      */
     public function withNoPhone(): Factory
     {
         return $this->state(fn (array $attributes) => [ // Using arrow function syntax
             'phone' => null,
         ]);
     }
}