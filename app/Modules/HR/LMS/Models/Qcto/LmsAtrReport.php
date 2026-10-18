<?php

namespace App\Modules\HR\LMS\Models\Qcto;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use App\Shared\Traits\KeepsDatabaseDefaults;

class LmsAtrReport extends Model
{
    use SoftDeletes, LogsActivity, KeepsDatabaseDefaults;

    protected $table = 'lms_atr_reports';

    protected $fillable = [
        'wsp_plan_id', 'seta_id', 'sdf_id',
        'report_year', 'period_start', 'period_end',
        'submission_date', 'status',
        'total_actual_spend', 'actual_spend_pct',
        'submission_reference', 'notes', 'created_by',
    ];

    protected $casts = [
        'period_start'      => 'date',
        'period_end'        => 'date',
        'submission_date'   => 'date',
        'total_actual_spend'=> 'decimal:2',
        'actual_spend_pct'  => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::creating(fn($m) => $m->uuid ??= (string) Str::uuid());
    }

    public function wspPlan()
    {
        return $this->belongsTo(LmsWspPlan::class, 'wsp_plan_id');
    }

    public function seta()
    {
        return $this->belongsTo(LmsSeta::class, 'seta_id');
    }

    public function sdf()
    {
        return $this->belongsTo(LmsSdf::class, 'sdf_id');
    }

    public function items()
    {
        return $this->hasMany(LmsAtrItem::class, 'atr_report_id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('LMS ATR Report')
            ->logOnly(['report_year', 'status', 'total_actual_spend', 'submission_reference', 'submission_date'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn(string $e) => ucfirst($e) . " ATR report {$this->report_year} — status: {$this->status}.");
    }
}
