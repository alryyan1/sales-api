<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PdfReportSetting extends Model
{
    protected $table = 'pdf_report_settings';

    protected $fillable = [
        'report_key',
        'report_name',
        'branding_type',
        'logo_position',
        'logo_height',
        'logo_width',
        'show_watermark',
        'show_stamp',
        'show_signature',
    ];

    protected $casts = [
        'show_watermark' => 'boolean',
        'show_stamp'     => 'boolean',
        'show_signature' => 'boolean',
        'logo_height'    => 'integer',
        'logo_width'     => 'integer',
    ];
}
