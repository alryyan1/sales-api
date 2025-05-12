<?php

namespace Database\Seeders;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;

use App\Models\Client;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\Supplier;
use App\Models\User;
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
            RolesAndPermissionsSeeder::class, // Creates roles & permissions
            AdminUserSeeder::class, // Create an admin user
            // ProductSeeder::class, // Create an admin user
            // Add other seeders here if needed
        ]);
        // Assign 'admin' role to the admin user AFTER roles are created
        $adminUser = User::where('email', env('ADMIN_EMAIL', 'admin@example.com'))->first();
        if ($adminUser && !$adminUser->hasRole('admin')) {
            $adminUser->assignRole('admin');
            $this->command->info('Admin role assigned to admin user.');
        }

        // Optionally create some non-admin users with other roles using factory
        // User::factory()->count(5)->create()->each(function ($user) {
        //     $user->assignRole('salesperson');
        // });
        // User::factory()->count(2)->create()->each(function ($user) {
        //     $user->assignRole('inventory_manager');
        // });r

        // Seed transactional data if needed
        // \App\Models\Purchase::factory(25)->create();
        // \App\Models\Sale::factory(40)->create();
        // Create 50 fake clients
        // Client::factory()->count(500)->create();
        // Supplier::factory()->count(500)->create();
        // Purchase::factory()->count(20)->create();
        // Product::factory()->count(50)->create();
        // Sale::factory()->count(30)->create();



    }
}
