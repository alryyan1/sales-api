<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Reset cached roles and permissions
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $this->createPermissions();
        $this->createRoles();
        $this->assignPermissionsToRoles();

        $this->command->info('Roles and Permissions seeded successfully!');
    }

    /**
     * Create all permissions
     */
    private function createPermissions(): void
    {
        // Clients
        Permission::firstOrCreate(['name' => 'view-clients', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'create-clients', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'edit-clients', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'delete-clients', 'guard_name' => 'web']);

        // Suppliers
        Permission::firstOrCreate(['name' => 'view-suppliers', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'create-suppliers', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'edit-suppliers', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'delete-suppliers', 'guard_name' => 'web']);

        // Products
        Permission::firstOrCreate(['name' => 'view-products', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'create-products', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'edit-products', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'delete-products', 'guard_name' => 'web']);

        // Stock Management
        Permission::firstOrCreate(['name' => 'view-stock-adjustments', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'adjust-stock', 'guard_name' => 'web']);

        // Categories
        Permission::firstOrCreate(['name' => 'view-categories', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'create-categories', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'manage-categories', 'guard_name' => 'web']);

        // Purchases
        Permission::firstOrCreate(['name' => 'view-purchases', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'create-purchases', 'guard_name' => 'web']);

        // Sales
        Permission::firstOrCreate(['name' => 'view-sales', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'create-sales', 'guard_name' => 'web']);

        // Sales Returns
        Permission::firstOrCreate(['name' => 'view-returns', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'create-sale-returns', 'guard_name' => 'web']);

        // Reports
        Permission::firstOrCreate(['name' => 'view-reports', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'view-near-expiry-report', 'guard_name' => 'web']);

        // User Management
        Permission::firstOrCreate(['name' => 'manage-users', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'manage-roles', 'guard_name' => 'web']);

        // Settings
        Permission::firstOrCreate(['name' => 'view-settings', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'update-settings', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'manage-settings', 'guard_name' => 'web']);

        // Stock Requisitions
        Permission::firstOrCreate(['name' => 'request-stock', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'view-own-stock-requisitions', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'view-all-stock-requisitions', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'process-stock-requisitions', 'guard_name' => 'web']);

        // WhatsApp Management
        Permission::firstOrCreate(['name' => 'send-whatsapp-messages', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'view-whatsapp-status', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'manage-whatsapp-schedulers', 'guard_name' => 'web']);

        // System Management
        Permission::firstOrCreate(['name' => 'view-system', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'update-system', 'guard_name' => 'web']);

        $this->command->info('All permissions created successfully.');
    }

    /**
     * Create roles
     */
    private function createRoles(): void
    {
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'salesperson', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'inventory_manager', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'cashier', 'guard_name' => 'web']);

        $this->command->info('Roles created successfully.');
    }

    /**
     * Assign permissions to roles
     */
    private function assignPermissionsToRoles(): void
    {
        // Admin Role - All permissions
        $adminRole = Role::where('name', 'admin')->first();
        $adminPermissions = [
            // Clients
            'view-clients', 'create-clients', 'edit-clients', 'delete-clients',
            
            // Suppliers
            'view-suppliers', 'create-suppliers', 'edit-suppliers', 'delete-suppliers',
            
            // Products
            'view-products', 'create-products', 'edit-products', 'delete-products',
            
            // Stock Management
            'view-stock-adjustments', 'adjust-stock',
            
            // Categories
            'view-categories', 'create-categories', 'manage-categories',
            
            // Purchases
            'view-purchases', 'create-purchases',
            
            // Sales
            'view-sales', 'create-sales',
            
            // Sales Returns
            'view-returns', 'create-sale-returns',
            
            // Reports
            'view-reports', 'view-near-expiry-report',
            
            // User Management
            'manage-users', 'manage-roles',
            
            // Settings
            'view-settings', 'update-settings', 'manage-settings',
            
            // Stock Requisitions
            'request-stock', 'view-own-stock-requisitions', 'view-all-stock-requisitions', 'process-stock-requisitions',
            
            // WhatsApp Management
            'send-whatsapp-messages', 'view-whatsapp-status', 'manage-whatsapp-schedulers',
            
            // System Management
            'view-system', 'update-system',
        ];
        $adminRole->syncPermissions($adminPermissions);
        $this->command->info('Admin permissions assigned.');

        // Salesperson Role
        $salesRole = Role::where('name', 'salesperson')->first();
        $salesPermissions = [
            'view-clients', 'create-clients', 'edit-clients',
            'view-products',
            'view-sales', 'create-sales',
            'view-returns', 'create-sale-returns',
            'view-reports',
        ];
        $salesRole->syncPermissions($salesPermissions);
        $this->command->info('Salesperson permissions assigned.');

        // Inventory Manager Role
        $inventoryRole = Role::where('name', 'inventory_manager')->first();
        $inventoryPermissions = [
            'view-suppliers', 'create-suppliers', 'edit-suppliers',
            'view-products', 'create-products', 'edit-products',
            'view-stock-adjustments', 'adjust-stock',
            'view-categories', 'create-categories',
            'view-purchases', 'create-purchases',
            'view-reports', 'view-near-expiry-report',
            'request-stock', 'view-own-stock-requisitions', 'view-all-stock-requisitions', 'process-stock-requisitions',
        ];
        $inventoryRole->syncPermissions($inventoryPermissions);
        $this->command->info('Inventory Manager permissions assigned.');

        // Cashier Role
        $cashierRole = Role::where('name', 'cashier')->first();
        $cashierPermissions = [
            'view-clients', 'create-clients',
            'view-products',
            'view-sales', 'create-sales',
            'view-returns', 'create-sale-returns',
        ];
        $cashierRole->syncPermissions($cashierPermissions);
        $this->command->info('Cashier permissions assigned.');
    }
}
