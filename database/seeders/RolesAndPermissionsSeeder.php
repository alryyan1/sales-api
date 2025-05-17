<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar; // Required to clear cache

class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Reset cached roles and permissions
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // --- Define Permissions ---
        // (Use a consistent naming convention: verb-noun, e.g., view-clients)

        // Clients
        Permission::firstOrCreate(['name' => 'view-clients', 'guard_name' => 'web']); // Use 'web' guard if using default Laravel auth, even for Sanctum tokens often
        Permission::firstOrCreate(['name' => 'create-clients', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'edit-clients', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'delete-clients', 'guard_name' => 'web']);
        // Permission::firstOrCreate(['name' => 'adjust-stock', 'guard_name' => 'web']); // Add new permission

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
        Permission::firstOrCreate(['name' => 'adjust-stock', 'guard_name' => 'web']); // Example
        Permission::firstOrCreate(['name' => 'view-stock-adjustments', 'guard_name' => 'web']); // Example

        // (e.g., manage-categories or view-categories, create-categories, etc.) and assign them to admin role.
        // Categories
        Permission::firstOrCreate(['name' => 'view-categories', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'create-categories', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'manage-categories', 'guard_name' => 'web']);

        // Purchases
        Permission::firstOrCreate(['name' => 'view-purchases', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'create-purchases', 'guard_name' => 'web']);
        // Permission::firstOrCreate(['name' => 'delete-purchases', 'guard_name' => 'web']); // Deleting usually restricted

        // Sales
        Permission::firstOrCreate(['name' => 'view-sales', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'create-sales', 'guard_name' => 'web']);
        // Permission::firstOrCreate(['name' => 'edit-sales', 'guard_name' => 'web']); // Editing usually restricted
        // Permission::firstOrCreate(['name' => 'delete-sales', 'guard_name' => 'web']); // Deleting usually restricted

        // Reports
        Permission::firstOrCreate(['name' => 'view-reports', 'guard_name' => 'web']);

        // User Management (Admin only usually)
        Permission::firstOrCreate(['name' => 'manage-users', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'manage-roles', 'guard_name' => 'web']);

        // Settings
        Permission::firstOrCreate(['name' => 'view-settings', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'update-settings', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'view-returns', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'create-sale-returns', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'manage-settings', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'view-near-expiry-report', 'guard_name' => 'web']);

        $this->command->info('Permissions created.');

        // --- Define Roles ---
        $adminRole = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $salesRole = Role::firstOrCreate(['name' => 'salesperson', 'guard_name' => 'web']);
        $inventoryRole = Role::firstOrCreate(['name' => 'inventory_manager', 'guard_name' => 'web']);
        // Stock Requisitions
        Permission::firstOrCreate(['name' => 'request-stock', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'view-own-stock-requisitions', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'view-all-stock-requisitions', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'process-stock-requisitions', 'guard_name' => 'web']);
        $this->command->info('Roles created.');

        // --- Assign Permissions to Roles ---

        // Admin gets all permissions (use wildcard or assign individually)
        // $adminRole->givePermissionTo(Permission::all());
        // Or more explicitly:
        $adminPermissions = [
            'view-clients',
            'create-clients',
            'edit-clients',
            'delete-clients',
            'view-suppliers',
            'create-suppliers',
            'edit-suppliers',
            'delete-suppliers',
            'view-products',
            'create-products',
            'edit-products',
            'delete-products',
            'view-purchases',
            'create-purchases',
            'view-sales',
            'create-sales', /* 'edit-sales', */
            'view-reports',
            'manage-users',
            'manage-roles',
            'view-stock-adjustments',
            'adjust-stock', // New permission
            'view-categories',
            'create-categories',
            'manage-categories',
            'manage-settings',
            'view-returns',
            'view-settings',
            'update-settings',
            'request-stock',
            'view-own-stock-requisitions',
            'view-all-stock-requisitions',
            'process-stock-requisitions',
            'create-sale-returns',
            'view-near-expiry-report',
        ];
        $adminRole->syncPermissions($adminPermissions);
        // Add 'adjust-stock' to relevant roles (e.g., admin, inventory_manager)
        // $adminRole->givePermissionTo('adjust-stock');
        $this->command->info('Admin permissions assigned.');

        // Salesperson
        $salesPermissions = [
            'view-clients',
            'create-clients',
            'edit-clients', // Maybe not delete?
            'view-products', // View products to sell
            'view-sales',    // View own sales? Or all? Needs policy maybe
            'create-sales',
        ];
        $salesRole->syncPermissions($salesPermissions);
        $this->command->info('Salesperson permissions assigned.');

        // Inventory Manager
        $inventoryPermissions = [
            'view-suppliers',
            'create-suppliers',
            'edit-suppliers', // Maybe not delete?
            'view-products',
            'create-products',
            'edit-products', // Maybe not delete?
            'view-purchases',
            'create-purchases',
            // Maybe view stock reports?
            'view-reports',
        ];
        $inventoryRole->syncPermissions($inventoryPermissions);
        // $inventoryRole->givePermissionTo('adjust-stock');
        $this->command->info('Inventory Manager permissions assigned.');

        $this->command->info('Roles and Permissions seeded successfully!');
    }
}
