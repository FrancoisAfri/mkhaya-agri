<?php

namespace App\Modules\CRM\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class CrmSurveyPlan extends Model
{
    protected $table = 'crm_survey_plans';

    protected $fillable = [
        'uuid', 'name', 'description', 'category', 'questions', 'audience', 'cadence',
        'next_run_at', 'deadline_days', 'thank_you', 'active', 'last_run_at', 'runs_count', 'created_by',
    ];

    protected $casts = [
        'questions'   => 'array',
        'audience'    => 'array',
        'active'      => 'boolean',
        'next_run_at' => 'datetime',
        'last_run_at' => 'datetime',
    ];

    public const CADENCES = ['once', 'weekly', 'monthly', 'quarterly', 'yearly'];

    public function isRecurring(): bool
    {
        return $this->cadence !== 'once';
    }

    /** Compute the next run after a given date, or null for a one-off. */
    public function nextRunAfter(Carbon $from): ?Carbon
    {
        return match ($this->cadence) {
            'weekly'    => $from->copy()->addWeek(),
            'monthly'   => $from->copy()->addMonthNoOverflow(),
            'quarterly' => $from->copy()->addMonthsNoOverflow(3),
            'yearly'    => $from->copy()->addYear(),
            default     => null,
        };
    }
}
