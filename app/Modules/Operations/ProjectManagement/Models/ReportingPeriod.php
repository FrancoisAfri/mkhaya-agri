<?php

namespace App\Modules\Operations\ProjectManagement\Models;

use App\Modules\Operations\ProjectManagement\Concerns\AuditsChanges;
use App\Modules\Operations\ProjectManagement\Concerns\HasPublicUuid;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One month's Project & Financial Report — "August 2026 Monthly Project & Financial Report".
 *
 * A period is the document; the rows beneath it are one project each. Every month gets its own
 * period rather than the same rows being edited in place, because the whole value of the report is
 * that August says something different about a project from July.
 */
class ReportingPeriod extends Model
{
    use AuditsChanges, BelongsToCompany, HasPublicUuid, SoftDeletes;

    protected $table = 'pm_reporting_periods';

    public const STATUSES = ['draft', 'issued'];

    /** How many months of projection the client's sheet shows. */
    public const DEFAULT_CASHFLOW_MONTHS = 6;

    protected $fillable = [
        'uuid', 'company_id', 'report_name', 'reporting_year', 'reporting_month',
        'meeting_date', 'general_comment', 'cashflow_months', 'status', 'issued_at', 'created_by',
    ];

    protected $casts = [
        'reporting_year'  => 'integer',
        'reporting_month' => 'integer',
        'cashflow_months' => 'integer',
        'meeting_date'    => 'date',
        'issued_at'       => 'datetime',
    ];

    public function reports(): HasMany
    {
        return $this->hasMany(MonthlyReport::class, 'reporting_period_id')->orderBy('row_number');
    }

    public function scopeIssued(Builder $query): Builder
    {
        return $query->where('status', 'issued');
    }

    public function isIssued(): bool
    {
        return $this->status === 'issued';
    }

    /** The first day of the month this report is about. */
    public function startsOn(): CarbonImmutable
    {
        return CarbonImmutable::create($this->reporting_year, $this->reporting_month, 1);
    }

    public function label(): string
    {
        return $this->startsOn()->format('F Y');
    }

    /**
     * The months the cash-flow columns show.
     *
     * **This is what makes the columns dynamic.** The client's August report shows March to August;
     * September's shows April to September. The window is the N months *ending at* the reporting
     * month, so it moves on by itself and no migration is ever needed to add a column.
     *
     * @return list<array{year:int, month:int, key:string, label:string}>
     */
    public function months(): array
    {
        $count = max(1, $this->cashflow_months ?: self::DEFAULT_CASHFLOW_MONTHS);
        $end = $this->startsOn();
        $months = [];

        for ($i = $count - 1; $i >= 0; $i--) {
            $point = $end->subMonths($i);

            $months[] = [
                'year'  => (int) $point->year,
                'month' => (int) $point->month,
                'key'   => $point->format('Y-m'),
                // "March 2026", as the client's column headings read.
                'label' => $point->format('F Y'),
            ];
        }

        return $months;
    }
}
