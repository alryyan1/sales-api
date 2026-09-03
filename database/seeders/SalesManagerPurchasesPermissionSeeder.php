<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class SalesManagerPurchasesPermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // "view-purchases" is the exact string PermissionGuard checks in router.tsx
        // to allow opening the /purchases page (creating a purchase invoice itself
        // has no backend restriction, so page access is the only thing to unlock).
        $permission = Permission::firstOrCreate(['name' => 'view-purchases', 'guard_name' => 'web']);

        foreach (['ادمن', 'admin', 'مسوول المبيعات'] as $roleName) {
            $role = Role::where('name', $roleName)->first();
            if ($role) {
                $role->givePermissionTo($permission);
                $this->command->info("Permission assigned to role: {$roleName}");
            }
        }

        $this->command->info('view-purchases permission seeded successfully.');
    }
}
