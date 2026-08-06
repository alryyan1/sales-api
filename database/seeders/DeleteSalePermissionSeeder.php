<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class DeleteSalePermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $permission = Permission::firstOrCreate(['name' => 'حذف فاتورة', 'guard_name' => 'web']);

        foreach (['ادمن', 'admin'] as $adminRoleName) {
            $adminRole = Role::where('name', $adminRoleName)->first();
            if ($adminRole) {
                $adminRole->givePermissionTo($permission);
                $this->command->info("Permission assigned to role: {$adminRoleName}");
            }
        }

        $this->command->info('Delete-sale permission seeded successfully.');
    }
}
