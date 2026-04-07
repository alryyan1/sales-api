<?php

namespace Database\Seeders;

use App\Models\PdfReportSetting;
use Illuminate\Database\Seeder;

class PdfReportSettingSeeder extends Seeder
{
    public function run(): void
    {
        $reports = [
            ['report_key' => 'invoice',                  'report_name' => 'الفاتورة'],
            ['report_key' => 'purchase',                 'report_name' => 'أمر الشراء'],
            ['report_key' => 'daily_sales',              'report_name' => 'تقرير المبيعات اليومية'],
            ['report_key' => 'sales_report',             'report_name' => 'تقرير المبيعات'],
            ['report_key' => 'sale_detail',              'report_name' => 'تفاصيل الفاتورة'],
            ['report_key' => 'inventory',                'report_name' => 'تقرير المخزون'],
            ['report_key' => 'inventory_log',            'report_name' => 'سجل المخزون'],
            ['report_key' => 'inventory_audit',          'report_name' => 'تدقيق المخزون'],
            ['report_key' => 'client_ledger',            'report_name' => 'كشف حساب العميل'],
            ['report_key' => 'supplier_ledger',          'report_name' => 'كشف حساب المورد'],
            ['report_key' => 'product',                  'report_name' => 'كتالوج المنتجات'],
            ['report_key' => 'tax',                      'report_name' => 'تقرير الضرائب'],
            ['report_key' => 'shift_sold_items',         'report_name' => 'مبيعات الوردية'],
            ['report_key' => 'shift_sales_return',       'report_name' => 'مرتجعات الوردية'],
            ['report_key' => 'shift_cost',               'report_name' => 'تكاليف الوردية'],
            ['report_key' => 'shift_inventory_effects',  'report_name' => 'أثر الوردية على المخزون'],
            ['report_key' => 'moved_expired_products',   'report_name' => 'المنتجات المنتهية الصلاحية'],
            ['report_key' => 'pricelist',                'report_name' => 'قائمة الأسعار'],
            ['report_key' => 'report_template',          'report_name' => 'نموذج تقرير مخصص'],
        ];

        foreach ($reports as $report) {
            PdfReportSetting::updateOrCreate(
                ['report_key' => $report['report_key']],
                [
                    'report_name'   => $report['report_name'],
                    'branding_type' => null,
                    'logo_position' => null,
                    'logo_height'   => null,
                    'logo_width'    => null,
                    'show_watermark' => false,
                ]
            );
        }
    }
}
