<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WhatsAppScheduler extends Model
{
    use HasFactory;

    protected $table = 'whatsapp_schedulers';

    protected $fillable = [
        'name',
        'phone_number',
        'report_type',
        'schedule_time',
        'is_active',
        'days_of_week',
        'notes',
        'last_sent_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'days_of_week' => 'array',
        'schedule_time' => 'datetime:H:i',
        'last_sent_at' => 'datetime',
    ];

    /**
     * Get the report type options
     */
    public static function getReportTypes(): array
    {
        return [
            'daily_sales' => 'Daily Sales Report',
            'inventory' => 'Inventory Report',
            'profit_loss' => 'Profit & Loss Report',
        ];
    }

    /**
     * Get the days of week options
     */
    public static function getDaysOfWeek(): array
    {
        return [
            0 => 'Sunday',
            1 => 'Monday',
            2 => 'Tuesday',
            3 => 'Wednesday',
            4 => 'Thursday',
            5 => 'Friday',
            6 => 'Saturday',
        ];
    }

    /**
     * Scope to get only active schedulers
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to get schedulers for a specific day of week
     */
    public function scopeForDayOfWeek($query, $dayOfWeek)
    {
        return $query->whereJsonContains('days_of_week', $dayOfWeek);
    }

    /**
     * Scope to get schedulers for a specific report type
     */
    public function scopeForReportType($query, $reportType)
    {
        return $query->where('report_type', $reportType);
    }

    /**
     * Get the formatted schedule time
     */
    public function getFormattedScheduleTimeAttribute(): string
    {
        return $this->schedule_time->format('H:i');
    }

    /**
     * Get the formatted days of week
     */
    public function getFormattedDaysOfWeekAttribute(): string
    {
        if (empty($this->days_of_week)) {
            return 'Not set';
        }

        $days = self::getDaysOfWeek();
        $selectedDays = array_map(function ($day) use ($days) {
            return $days[$day] ?? 'Unknown';
        }, $this->days_of_week);

        return implode(', ', $selectedDays);
    }

    /**
     * Get the report type label
     */
    public function getReportTypeLabelAttribute(): string
    {
        $types = self::getReportTypes();
        return $types[$this->report_type] ?? 'Unknown';
    }
}
