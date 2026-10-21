<?php

namespace App\Shared\Insights;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** A catalogue report sent on a schedule, run as the person who set it up. */
class ReportSchedule extends Model
{
    use BelongsToCompany;

    public const FREQUENCIES = ['daily', 'weekly', 'monthly'];
    public const PERIODS = ['last_7_days', 'last_month', 'month_to_date', 'year_to_date'];

    protected $table = 'report_schedules';

    protected $fillable = ['uuid', 'company_id', 'user_id', 'report_key', 'title', 'params', 'period', 'format', 'frequency', 'weekday', 'month_day',
        'send_at', 'recipients', 'active', 'next_run_at', 'last_run_at', 'last_status', 'last_error'];

    protected $casts = ['params' => 'array', 'recipients' => 'array', 'active' => 'boolean', 'next_run_at' => 'datetime', 'last_run_at' => 'datetime'];

    protected static function booted(): void
    {
        static::creating(fn (ReportSchedule $s) => $s->uuid ??= (string) Str::uuid());
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
