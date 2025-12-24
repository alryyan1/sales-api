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

        $this->assignPermissionsToRoles();
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
            'فتح ورديه',      // Open Shift
            'قفل ورديه',     // Close Shift
            'سداد',          // Payment
            'الغاء السداد',   // Cancel Payment
            'الخصم',         // Discount

            // Sidebar Navigation Permissions (view-*)
            // Derived from navItems.ts and common access needs

            // Main Navigation
            'view-dashboard',           // لوحة التحكم
            'view-clients',             // العملاء
            'view-suppliers',           // الموردون
            'view-products',            // المنتجات
            'view-purchases',           // المشتريات
            'view-stock-adjustments',   // تعديلات المخزون
            'view-stock-transfers',     // تحويل المخزون (inventory/transfers)
            'view-pos',                 // نقطة البيع
            'view-pos-offline',         // نقطة البيع (Offline)

            // Reports Navigation
            'view-reports-sales',            // تقرير المبيعات
            'view-reports-purchases',        // تقرير المشتريات
            'view-reports-inventory',        // تقرير المخزون
            'view-reports-inventory-log',    // سجل المخزون
            'view-reports-monthly-revenue',  // الإيرادات الشهرية
            'view-reports-discounts',        // المبيعات المخفضة
            'view-reports-daily-income',     // تقرير الدخل اليومي

            // Admin Navigation
            'manage-users',                  // المستخدمون
            'manage-roles',                  // الأدوار
            'manage-categories',             // الفئات
            'manage-expenses',               // المصروفات
            'manage-settings',               // الإعدادات
            'manage-system',                 // النظام
            'manage-backups',                // النسخ الاحتياطي
            'manage-warehouses',             // المخازن
            'manage-whatsapp-schedulers',    // جدولة واتساب
            'request-stock',                 // طلب مخزون
            'view-stock-requisitions',       // طلبات المخزون
            'manage-idb',                    // إدارة DB المحلية
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
     * Assign permissions to the freshly created roles.
     */
    private function assignPermissionsToRoles(): void
    {
        // Admin gets everything
        $adminRole = Role::where('name', 'ادمن')->first();
        if ($adminRole) {
            $adminRole->givePermissionTo(Permission::all());
        }

        // Staff roles get the operational set
        $operationalPermissions = ['فتح ورديه', 'قفل ورديه', 'سداد', 'الغاء السداد', 'الخصم'];

        // Basic View Permissions for all Staff
        $commonStaffPermissions = [
            'view-dashboard',
            'view-pos',
            'view-pos-offline',
            'view-clients',
            'view-products',
        ];

        $staffRoles = ['مسوول المبيعات', 'كاشير', 'مسوول المخزن'];

        foreach ($staffRoles as $roleName) {
            $role = Role::where('name', $roleName)->first();
            if ($role) {
                // Give core operational capabilities
                $role->givePermissionTo($operationalPermissions);
                // Give basic access
                $role->givePermissionTo($commonStaffPermissions);

                // Inventory Manager Specifics
                if ($roleName === 'مسوول المخزن') {
                    $role->givePermissionTo([
                        'view-suppliers',
                        'view-purchases',
                        'view-stock-adjustments',
                        'view-stock-transfers',
                        'view-reports-inventory',
                        'view-reports-inventory-log',
                        'request-stock',
                        'view-stock-requisitions',
                        'manage-warehouses'
                    ]);
                }

                // Sales Manager Specifics
                if ($roleName === 'مسوول المبيعات') {
                    $role->givePermissionTo([
                        'view-reports-sales',
                        'view-reports-monthly-revenue',
                        'view-reports-discounts',
                        'view-reports-daily-income'
                    ]);
                }
            }
        }
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
