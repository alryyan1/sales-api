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
        // Permission::firstOrCreate(['name' => 'adjust-stock', 'guard_name' => 'web']); // Example

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

        $this->command->info('Permissions created.');

        // --- Define Roles ---
        $adminRole = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $salesRole = Role::firstOrCreate(['name' => 'salesperson', 'guard_name' => 'web']);
        $inventoryRole = Role::firstOrCreate(['name' => 'inventory_manager', 'guard_name' => 'web']);

        $this->command->info('Roles created.');

        // --- Assign Permissions to Roles ---

        // Admin gets all permissions (use wildcard or assign individually)
        // $adminRole->givePermissionTo(Permission::all());
        // Or more explicitly:
        $adminPermissions = [
            'view-clients', 'create-clients', 'edit-clients', 'delete-clients',
            'view-suppliers', 'create-suppliers', 'edit-suppliers', 'delete-suppliers',
            'view-products', 'create-products', 'edit-products', 'delete-products',
            'view-purchases', 'create-purchases',
            'view-sales', 'create-sales', /* 'edit-sales', */
            'view-reports',
            'manage-users', 'manage-roles',
        ];
        $adminRole->syncPermissions($adminPermissions);
        $this->command->info('Admin permissions assigned.');

        // Salesperson
        $salesPermissions = [
            'view-clients', 'create-clients', 'edit-clients', // Maybe not delete?
            'view-products', // View products to sell
            'view-sales',    // View own sales? Or all? Needs policy maybe
            'create-sales',
        ];
        $salesRole->syncPermissions($salesPermissions);
        $this->command->info('Salesperson permissions assigned.');

        // Inventory Manager
        $inventoryPermissions = [
            'view-suppliers', 'create-suppliers', 'edit-suppliers', // Maybe not delete?
            'view-products', 'create-products', 'edit-products', // Maybe not delete?
            'view-purchases', 'create-purchases',
            // Maybe view stock reports?
            'view-reports',
        ];
        $inventoryRole->syncPermissions($inventoryPermissions);
        $this->command->info('Inventory Manager permissions assigned.');

         $this->command->info('Roles and Permissions seeded successfully!');
    }
}