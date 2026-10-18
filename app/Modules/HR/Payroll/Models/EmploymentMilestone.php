<?php

namespace App\Modules\HR\Payroll\Models;

use App\Modules\HR\Payroll\Models\Bureau\BureauCompany;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A milestone that has come due for one employee — raised once, and kept afterwards.
 *
 * The details are copied onto the row rather than looked up when it is read. If somebody corrects a
 * start date next month, the notice payroll was actually sent must not quietly change to match: the
 * question a bureau gets asked is "what were we told, and when", and a recomputed answer cannot
 * settle it.
 */
class EmploymentMilestone extends Model
{
    use BelongsToCompany;

    protected $table = 'employment_milestones';

    /** Which engine's employee this belongs to — the same discriminator as the incentive ledger. */
    public const BUREAU = EtiClaim::BUREAU;
    public const HR = EtiClaim::HR;

    protected $guarded = ['id'];

    protected $casts = [
        'months'               => 'integer',
        'start_date'           => 'date',
        'due_on'               => 'date',
        'eti_tracked'          => 'boolean',
        'eti_months_claimed'   => 'integer',
        'eti_months_remaining' => 'integer',
        'notified_at'          => 'datetime',
        'acknowledged_at'      => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $milestone) {
            $milestone->uuid ??= (string) Str::uuid();
        });
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(EmploymentMilestoneRule::class, 'rule_id');
    }

    public function bureauCompany(): BelongsTo
    {
        return $this->belongsTo(BureauCompany::class, 'bureau_company_id');
    }

    /** Still waiting for somebody to act on it. */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('acknowledged_at');
    }

    public function scopeForEmployee(Builder $query, string $type, int $employeeId): Builder
    {
        return $query->where('employee_type', $type)->where('employee_id', $employeeId);
    }

    /** What the notice says, in the words the requirement asked for. */
    public function summary(): string
    {
        $line = "{$this->employee_name} ({$this->employee_number}) — {$this->months} months";

        if ($this->eti_tracked) {
            $claimed = $this->eti_months_claimed ?? 0;
            $line .= ". Incentive: {$claimed} of " . EtiClaim::MAX_MONTHS . ' months used';
        }

        return $line;
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
