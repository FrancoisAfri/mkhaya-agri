<?php

namespace App\Modules\HR\Hr\Attendance\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/** A pay period's approved time and piecework, handed to payroll and reconciled against what it paid. */
class PayrollHandoff extends EngineModel
{
    protected $table = 'attendance_payroll_handoffs';

    protected $casts = ['period_start' => 'date', 'period_end' => 'date', 'totals' => 'array', 'sent_at' => \App\Modules\HR\Hr\Attendance\Support\SiteDateTime::class];

    public function lines(): HasMany
    {
        return $this->hasMany(PayrollHandoffLine::class, 'handoff_id');
    }
}
