<?php

namespace App\Modules\HR\Payroll\Models;

use App\Modules\HR\Payroll\Models\Bureau\BureauCompany;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Which employment milestones a company wants to hear about.
 *
 * Configured rather than fixed. The requirement arrived with its own contradiction — the minutes
 * said one month, the follow-up said one year — and both are right for somebody: a probation review
 * is a month, an incentive expiry is twenty-four, and a long-service note is sixty.
 */
class EmploymentMilestoneRule extends Model
{
    use BelongsToCompany;

    protected $table = 'employment_milestone_rules';

    protected $guarded = ['id'];

    protected $casts = [
        'months'          => 'integer',
        'categories'      => 'array',
        'is_active'       => 'boolean',
        'notify_email'    => 'boolean',
        'notify_employee' => 'boolean',
        'lead_days'       => 'integer',
    ];

    /**
     * What a company gets before anybody configures anything.
     *
     * Chosen so the module is useful on the day it is switched on: probation at one and three
     * months, the incentive's halfway and expiry points for the categories it applies to, and a
     * first-anniversary note for everybody. An administrator can delete any of them.
     */
    public const DEFAULTS = [
        ['months' => 1,  'label' => 'Probation review',        'categories' => null, 'lead_days' => 7],
        ['months' => 3,  'label' => 'Probation end',           'categories' => null, 'lead_days' => 14],
        ['months' => 6,  'label' => 'Six-month review',        'categories' => null, 'lead_days' => 14],
        ['months' => 12, 'label' => 'First anniversary',       'categories' => null, 'lead_days' => 14],
        ['months' => 18, 'label' => 'Incentive: 18 months',    'categories' => ['intern', 'learnership'], 'lead_days' => 30],
        ['months' => 24, 'label' => 'Incentive: final months', 'categories' => ['intern', 'learnership'], 'lead_days' => 60],
    ];

    protected static function booted(): void
    {
        static::creating(function (self $rule) {
            $rule->uuid ??= (string) Str::uuid();
        });
    }

    public function bureauCompany(): BelongsTo
    {
        return $this->belongsTo(BureauCompany::class, 'bureau_company_id');
    }

    /** Does this rule apply to somebody in this employment category? */
    public function appliesTo(?string $category): bool
    {
        if (blank($this->categories)) {
            return true;
        }

        return in_array((string) $category, $this->categories, true);
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
