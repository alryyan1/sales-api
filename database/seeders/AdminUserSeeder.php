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
     */
    public function run(): void
    {
        // Superadmin user credentials
        $superadminName = 'Super Admin';
        $superadminUsername = 'superadmin';
        $superadminPassword = '12345678';

        // Check if superadmin user already exists by username
        $superadminUser = User::where('username', $superadminUsername)->first();

        if (!$superadminUser) {
            try {
                // Create the superadmin user
                $superadminUser = User::create([
                    'name' => $superadminName,
                    'username' => $superadminUsername,
                    'password' => Hash::make($superadminPassword),
                    'allowed_navs' => null, // null means all navigation access
                ]);

                $this->command->info("✓ Superadmin user created successfully!");
                $this->command->line("  Username: {$superadminUsername}");
                $this->command->line("  Password: {$superadminPassword}");
            } catch (\Exception $e) {
                $this->command->error("Failed to create superadmin user: {$e->getMessage()}");
                return;
            }
        } else {
            $this->command->warn("Superadmin user already exists ({$superadminUsername}).");
        }

        // Assign 'ادمن' role (Arabic Admin Role)
        if ($superadminUser && method_exists($superadminUser, 'assignRole')) {
            try {
                // Force sync the 'ادمن' role to ensure they have it.
                // Using syncRoles ensures they only have this role, or use assignRole to append.
                // Given "superadmin" status, we want to make sure they definitely have the highest privs.
                $superadminUser->assignRole('ادمن');
                $this->command->info("Role 'ادمن' assigned to user: {$superadminUsername}");
            } catch (\Exception $e) {
                $this->command->error("Could not assign role 'ادمن'. Ensure RolesAndPermissionsSeeder has been run.");
                Log::warning("AdminUserSeeder: Could not assign role - {$e->getMessage()}");
            }
        }
    }
}
