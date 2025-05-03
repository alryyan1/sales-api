<?php

namespace Database\Seeders;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;

use App\Models\Client;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\Supplier;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // \App\Models\User::factory(10)->create();

        // \App\Models\User::factory()->create([
        //     'name' => 'Test User',
        //     'email' => 'test@example.com',
        // ]);

        $this->call([
            AdminUserSeeder::class, // Create an admin user
            // Add other seeders here if needed
        ]);

        // Create 50 fake clients
        Client::factory()->count(50)->create();
        Supplier::factory()->count(50)->create();
        Purchase::factory()->count(20)->create();
        Product::factory()->count(50)->create();
        Sale::factory()->count(30)->create();



    }
}
