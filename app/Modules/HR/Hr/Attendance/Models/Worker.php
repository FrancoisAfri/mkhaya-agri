<?php

namespace App\Modules\HR\Hr\Attendance\Models;

use App\Modules\HR\Hr\Models\HRPerson;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Someone whose time T&A keeps: the HR person (who owns their personal details) with how their time
 * is worked and paid — category, crew, labour rule set, clocking badge, consent to GPS and biometric
 * clocking, the hourly rate their labour is costed at, and the site they normally work on (a farm).
 */
class Worker extends SitedModel
{
    public const CATEGORIES = ['permanent', 'seasonal', 'casual', 'contractor', 'supervisor', 'manager'];
    public const ACTIVE = 'active';
    public const SUSPENDED = 'suspended';
    public const TERMINATED = 'terminated';

    protected $table = 'attendance_workers';

    protected $hidden = ['badge_token'];

    protected $casts = ['contract_start' => 'date', 'contract_end' => 'date', 'gps_allowed' => 'boolean', 'biometric_allowed' => 'boolean',
        'consent_recorded_at' => \App\Modules\HR\Hr\Attendance\Support\SiteDateTime::class, 'hourly_cost_rate' => 'float'];

    public function person(): BelongsTo
    {
        return $this->belongsTo(HRPerson::class, 'hr_person_id')->withoutGlobalScopes();
    }

    public function crew(): BelongsTo
    {
        return $this->belongsTo(Crew::class, 'crew_id');
    }

    public function ruleSet(): BelongsTo
    {
        return $this->belongsTo(RuleSet::class, 'labour_rule_set_id');
    }

    /** The name from HR, which owns personal details. */
    public function displayName(): string
    {
        $p = $this->person;

        return trim(($p?->first_name ?? '').' '.($p?->surname ?? '')) ?: ($p?->employee_number ?? 'Worker #'.$this->id);
    }

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }
}
