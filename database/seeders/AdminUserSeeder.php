<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class AdminUserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     * 
     * Creates a default administrator user if one doesn't exist.
     * Uses configuration values from config/admin.php or environment variables.
     */
    public function run(): void
    {
        // Superadmin user credentials
        $superadminName = 'Super Admin';
        $superadminUsername = 'superadmin';
        $superadminEmail = 'superadmin@example.com';
        $superadminPassword = '12345678';

        // Check if superadmin user already exists by username or email
        $existingSuperadmin = User::where('username', $superadminUsername)
            ->orWhere('email', $superadminEmail)
            ->first();

        if ($existingSuperadmin) {
            $identifier = $existingSuperadmin->username ?? $existingSuperadmin->email;
            $this->command->warn("Superadmin user already exists ({$identifier}). Skipping creation.");
            Log::info("AdminUserSeeder: Superadmin user already exists ({$identifier}).");
            
            // Optionally update username if missing
            if ($existingSuperadmin->username !== $superadminUsername && !User::where('username', $superadminUsername)->exists()) {
                $existingSuperadmin->update(['username' => $superadminUsername]);
                $this->command->info("Updated superadmin username to: {$superadminUsername}");
                Log::info("AdminUserSeeder: Updated superadmin username to {$superadminUsername}.");
            }
            
            // Update password if needed (optional - remove if you don't want to reset password)
            // $existingSuperadmin->update(['password' => Hash::make($superadminPassword)]);
            
            return;
        }

        try {
            // Create the superadmin user
            $superadminUser = User::create([
                'name' => $superadminName,
                'username' => $superadminUsername,
                'email' => $superadminEmail,
                'email_verified_at' => now(),
                'password' => Hash::make($superadminPassword),
            ]);

            // Assign superadmin role if Spatie Permission is configured
            if (method_exists($superadminUser, 'assignRole')) {
                try {
                    // Try to assign 'superadmin' role first, fallback to 'admin' if it doesn't exist
                    try {
                        $superadminUser->assignRole('superadmin');
                        $this->command->info("Superadmin role assigned to user: {$superadminUsername}");
                    } catch (\Exception $e) {
                        // If 'superadmin' role doesn't exist, try 'admin' role
                        $superadminUser->assignRole('admin');
                        $this->command->info("Admin role assigned to user: {$superadminUsername} (superadmin role not found)");
                    }
                } catch (\Exception $e) {
                    $this->command->warn("Could not assign role. Make sure roles exist in the database.");
                    Log::warning("AdminUserSeeder: Could not assign role - {$e->getMessage()}");
        }
            }

            $this->command->info("✓ Superadmin user created successfully!");
            $this->command->line("  Username: {$superadminUsername}");
            $this->command->line("  Email: {$superadminEmail}");
            $this->command->line("  Name: {$superadminName}");
            $this->command->line("  Password: {$superadminPassword}");
            
            Log::info("AdminUserSeeder: Superadmin user created successfully", [
                'username' => $superadminUsername,
                'email' => $superadminEmail,
            ]);

        } catch (\Exception $e) {
            $this->command->error("Failed to create superadmin user: {$e->getMessage()}");
            Log::error("AdminUserSeeder: Failed to create superadmin user", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }
}
