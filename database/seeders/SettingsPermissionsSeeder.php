<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class SettingsPermissionsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 1. Reset cached roles and permissions
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // 2. Define permissions to add
        $permissions = [
            'view-settings',
            'update-settings',
        ];

        foreach ($permissions as $permissionName) {
            Permission::firstOrCreate(['name' => $permissionName, 'guard_name' => 'web']);
        }

        // 3. Assign to 'ادمن' role
        $adminRoleName = 'ادمن';
        $adminRole = Role::where('name', $adminRoleName)->first();

        if ($adminRole) {
            $adminRole->givePermissionTo($permissions);
            $this->command->info("Permissions assigned to role: {$adminRoleName}");
        } else {
            $this->command->error("Role not found: {$adminRoleName}");
        }

        // Also assign to 'admin' if it exists
        $englishAdminRole = Role::where('name', 'admin')->first();
        if ($englishAdminRole) {
            $englishAdminRole->givePermissionTo($permissions);
            $this->command->info("Permissions assigned to role: admin");
        }


        $this->command->info('Settings permissions seeded successfully.');
    }
}
