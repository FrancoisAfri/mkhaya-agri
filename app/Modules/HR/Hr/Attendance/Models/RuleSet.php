<?php

namespace App\Modules\HR\Hr\Attendance\Models;

/** How time is counted for a group of workers: overtime thresholds, multipliers, night work, rest and fatigue limits. */
class RuleSet extends EngineModel
{
    protected $table = 'attendance_rule_sets';

    protected $casts = ['applies_to' => 'array', 'is_default' => 'boolean', 'needs_review' => 'boolean', 'reviewed_at' => \App\Modules\HR\Hr\Attendance\Support\SiteDateTime::class,
        'ot_multiplier' => 'float', 'sunday_multiplier' => 'float', 'holiday_multiplier' => 'float', 'night_allowance_per_hour' => 'float'];
}
