<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PdfReportSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PdfReportSettingController extends Controller
{
    public function index(): JsonResponse
    {
        $settings = PdfReportSetting::orderBy('id')->get();
        return response()->json($settings);
    }

    public function update(Request $request, string $reportKey): JsonResponse
    {
        $validated = $request->validate([
            'branding_type' => ['nullable', 'in:logo,header,none'],
            'logo_position' => ['nullable', 'in:left,right,center'],
            'logo_height'   => ['nullable', 'integer', 'min:10', 'max:500'],
            'logo_width'    => ['nullable', 'integer', 'min:10', 'max:500'],
            'show_watermark' => ['boolean'],
        ]);

        $setting = PdfReportSetting::where('report_key', $reportKey)->firstOrFail();
        $setting->update($validated);

        return response()->json($setting);
    }
}
