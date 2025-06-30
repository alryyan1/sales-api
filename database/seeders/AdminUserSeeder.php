<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\User; // Import the User model
use Illuminate\Support\Facades\Hash; // Import Hash facade for password hashing
use Illuminate\Support\Facades\Log;   // Optional: for logging

class AdminUserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     * Creates a default administrator user if one doesn't exist.
     */
    public function run(): void
    {
        // Define admin credentials (use environment variables for sensitive data in production)
        $adminEmail = config('admin.admin_email');
        $adminPassword = config('admin.admin_password');
        $adminName = config('admin.admin_name');

        // Check if the admin user already exists
        if (User::where('email', $adminEmail)->doesntExist()) {
            // Create the admin user
            User::create([
                'name' => $adminName,
                'email' => $adminEmail,
                'email_verified_at' => now(), // Optionally verify the email immediately
                'password' => Hash::make($adminPassword), // Hash the password securely
                // Add any other fields specific to your User model if needed
                // 'is_admin' => true, // Example if you have an admin flag
            ]);

            // Optional: Log or output a message
            $this->command->info("Admin user created: {$adminEmail}");
            Log::info("Admin user created via seeder: {$adminEmail}");
        } else {
            // Optional: Log or output a message if admin already exists
            $this->command->warn("Admin user ({$adminEmail}) already exists. Seeder skipped creation.");
            Log::info("AdminUserSeeder: Admin user ({$adminEmail}) already exists.");
        }

        // Note: You could also use the User factory if you prefer:
        // User::factory()->create([
        //     'name' => $adminName,
        //     'email' => $adminEmail,
        //     'password' => Hash::make($adminPassword),
        //     'email_verified_at' => now(),
        // ]);
        // However, using create() directly is often clearer for a specific admin user.
    }
}
