<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use App\Models\User;

class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 1. Clear Cache
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // 2. Wipe existing data (Truncate)
        $this->cleanupDatabase();

        // 3. Seed new data
        $this->createPermissions();
        $this->createRoles();

        $this->assignSuperAdminRole();

        $this->command->info('Existing data wiped and new Roles/Permissions seeded successfully!');
    }

    /**
     * Completely removes all roles and permissions from the database.
     */
    private function cleanupDatabase(): void
    {
        // Disable foreign key checks to allow truncation of linked tables
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');

        // Clear the pivot tables first
        DB::table('role_has_permissions')->truncate();
        DB::table('model_has_permissions')->truncate();
        DB::table('model_has_roles')->truncate();

        // Clear the main tables
        Permission::truncate();
        Role::truncate();

        // Re-enable foreign key checks
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');

        $this->command->warn('All existing roles and permissions have been deleted.');
    }

    /**
     * Create specific permissions based on Arabic terms and Sidebar Navigation.
     */
    private function createPermissions(): void
    {
        $permissions = [
            // Core POS / Cashier Permissions

            // POS Permissions
            'سداد',
            'الغاء سداد',
            'تخفيض',
            'حذف منتج مضاف في عمليه بيع',
            'فتح ورديه',
            'اغلاق ورديه',
        ];

        foreach ($permissions as $permission) {
            Permission::create(['name' => $permission, 'guard_name' => 'web']);
        }

        $this->command->info('New permissions created.');
    }

    /**
     * Create the specific roles.
     */
    private function createRoles(): void
    {
        $roles = ['ادمن', 'مسوول المبيعات', 'مسوول المخزن', 'كاشير'];

        foreach ($roles as $role) {
            Role::create(['name' => $role, 'guard_name' => 'web']);
        }

        $this->command->info('New roles created.');
    }


    /**
     * Assign the admin role to the superadmin user.
     */
    private function assignSuperAdminRole(): void
    {
        $adminRole = Role::where('name', 'ادمن')->first();
        $superAdmin = User::where('username', 'superadmin')->first();

        if ($adminRole && $superAdmin) {
            $superAdmin->assignRole($adminRole);
            $this->command->info('Assigned "ادمن" role to superadmin user.');
        }
    }
}
