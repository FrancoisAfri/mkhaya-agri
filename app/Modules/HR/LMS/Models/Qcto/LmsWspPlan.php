<?php

namespace App\Modules\HR\LMS\Models\Qcto;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use App\Shared\Traits\KeepsDatabaseDefaults;

class LmsWspPlan extends Model
{
    use SoftDeletes, LogsActivity, KeepsDatabaseDefaults;

    protected $table = 'lms_wsp_plans';

    protected $fillable = [
        'plan_year', 'seta_id', 'sdf_id',
        'due_date', 'submission_date', 'status',
        'total_levy_paid', 'total_planned_spend', 'planned_spend_pct',
        'mandatory_grant_claimed', 'discretionary_grant_applied',
        'submission_reference', 'notes',
        'sdl_number', 'paye_reference', 'sic_code',
        'created_by',
    ];

    protected $casts = [
        'due_date'                   => 'date',
        'submission_date'            => 'date',
        'total_levy_paid'            => 'decimal:2',
        'total_planned_spend'        => 'decimal:2',
        'planned_spend_pct'          => 'decimal:2',
        'mandatory_grant_claimed'    => 'boolean',
        'discretionary_grant_applied'=> 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(fn($m) => $m->uuid ??= (string) Str::uuid());
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
        return $this->hasMany(LmsWspItem::class, 'wsp_plan_id');
    }

    public function atrReport()
    {
        return $this->hasOne(LmsAtrReport::class, 'wsp_plan_id');
    }

    public function recalculateTotals(): void
    {
        $total = $this->items()->sum('total_planned_cost');

        // Levy is 1% of annual payroll; planned_spend_pct = planned / (levy / 0.01) * 100
        $levy           = (float) $this->total_levy_paid;
        $annualPayroll  = $levy > 0 ? $levy / 0.01 : 0;
        $spendPct       = $annualPayroll > 0 ? round(($total / $annualPayroll) * 100, 2) : 0;

        $this->update([
            'total_planned_spend' => $total,
            'planned_spend_pct'   => $spendPct,
        ]);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('LMS WSP Plan')
            ->logOnly(['plan_year', 'status', 'total_planned_spend', 'submission_reference', 'submission_date'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn(string $e) => ucfirst($e) . " WSP plan {$this->plan_year} — status: {$this->status}.");
    }
}
