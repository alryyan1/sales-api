<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class ProductInventoryPermissionsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $permissions = [
            Permission::firstOrCreate(['name' => 'اضافة منتج', 'guard_name' => 'web']),
            Permission::firstOrCreate(['name' => 'تعديل منتج', 'guard_name' => 'web']),
            Permission::firstOrCreate(['name' => 'حذف منتج', 'guard_name' => 'web']),
            Permission::firstOrCreate(['name' => 'جرد المخزون', 'guard_name' => 'web']),
            Permission::firstOrCreate(['name' => 'تحويل مخزون', 'guard_name' => 'web']),
        ];

        foreach (['ادمن', 'admin'] as $adminRoleName) {
            $adminRole = Role::where('name', $adminRoleName)->first();
            if ($adminRole) {
                $adminRole->givePermissionTo($permissions);
                $this->command->info("Permissions assigned to role: {$adminRoleName}");
            }
        }

        $this->command->info('Product/inventory permissions seeded successfully.');
    }
}
