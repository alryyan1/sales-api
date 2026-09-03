<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Brings a fresh/behind deployment's permissions up to date with what this
 * install currently has configured, in one idempotent run. Safe to re-run —
 * only adds missing permissions/role-grants, never removes anything, so it
 * won't clobber further manual changes made via the Roles page.
 *
 * Run with: php artisan db:seed --class=SyncCurrentPermissionsSeeder
 */
class SyncCurrentPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // Every permission name this install currently has, whether it came
        // from the base RolesAndPermissionsSeeder or a later incremental one.
        $allPermissions = [
            'سداد',
            'الغاء سداد',
            'تخفيض',
            'حذف منتج مضاف في عمليه بيع',
            'فتح ورديه',
            'اغلاق ورديه',
            'تعديل سعر الدولار',
            'حذف فاتورة',
            'اضافة منتج',
            'تعديل منتج',
            'حذف منتج',
            'جرد المخزون',
            'تحويل مخزون',
            'view-purchases',
        ];

        foreach ($allPermissions as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        // Role => permission names currently granted on this install.
        $roleGrants = [
            'كاشير' => [
                'سداد',
                'الغاء سداد',
                'تخفيض',
                'حذف منتج مضاف في عمليه بيع',
                'حذف فاتورة',
            ],
            'مسوول المبيعات' => [
                'سداد',
                'الغاء سداد',
                'تخفيض',
                'حذف منتج مضاف في عمليه بيع',
                'فتح ورديه',
                'اغلاق ورديه',
                'تعديل سعر الدولار',
                'حذف فاتورة',
                'view-purchases',
            ],
            'مسوول المخزن' => [
                'اضافة منتج',
                'تعديل منتج',
                'حذف منتج',
                'جرد المخزون',
                'تحويل مخزون',
            ],
        ];

        foreach ($roleGrants as $roleName => $permissionNames) {
            $role = Role::where('name', $roleName)->first();
            if (! $role) {
                $this->command->warn("Role not found, skipping: {$roleName}");
                continue;
            }
            $role->givePermissionTo($permissionNames);
            $this->command->info("Synced " . count($permissionNames) . " permissions to role: {$roleName}");
        }

        // ادمن always gets everything.
        foreach (['ادمن', 'admin'] as $adminRoleName) {
            $adminRole = Role::where('name', $adminRoleName)->first();
            $adminRole?->givePermissionTo(Permission::all());
        }

        $this->command->info('Permissions synced successfully.');
    }
}
