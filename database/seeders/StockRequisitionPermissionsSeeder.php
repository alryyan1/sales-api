<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class StockRequisitionPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $permissions = [
            'request-stock',                // طالب الصرف: إنشاء طلب وعرض طلباته
            'view-all-stock-requisitions',  // عرض كل الطلبات (مدير/أمين مخزن)
            'process-stock-requisitions',   // موافقة/رفض/صرف الطلبات (أمين المخزن)
        ];

        foreach ($permissions as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        // منح جميع الصلاحيات لدور الادمن
        $adminRole = Role::where('name', 'ادمن')->first();
        if ($adminRole) {
            $adminRole->givePermissionTo($permissions);
        }

        // منح صلاحيات أمين المخزن لدور مسوول المخزن
        $warehouseRole = Role::where('name', 'مسوول المخزن')->first();
        if ($warehouseRole) {
            $warehouseRole->givePermissionTo([
                'view-all-stock-requisitions',
                'process-stock-requisitions',
            ]);
        }

        // منح صلاحية الطلب لدور مسوول المبيعات
        $salesRole = Role::where('name', 'مسوول المبيعات')->first();
        if ($salesRole) {
            $salesRole->givePermissionTo('request-stock');
        }

        // منح صلاحية الطلب لدور الكاشير
        $cashierRole = Role::where('name', 'كاشير')->first();
        if ($cashierRole) {
            $cashierRole->givePermissionTo('request-stock');
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $this->command->info('تم إضافة صلاحيات صرف المخزن وتعيينها للأدوار بنجاح.');
    }
}
