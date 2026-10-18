<?php

namespace App\Modules\Operations\ProjectManagement\Models;

use App\Modules\Operations\ProjectManagement\Concerns\HasPublicUuid;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One month's estimated invoice cash flow for one project row.
 *
 * A row per month, carrying its own year and month — never a column. The client's report shows six
 * months and the window moves on every month; columns would mean a migration every month forever.
 */
class MonthlyCashflow extends Model
{
    use BelongsToCompany, HasPublicUuid;

    protected $table = 'pm_monthly_cashflows';

    protected $fillable = [
        'uuid', 'company_id', 'monthly_report_id', 'forecast_year', 'forecast_month', 'amount',
    ];

    protected $casts = [
        'forecast_year'  => 'integer',
        'forecast_month' => 'integer',
        'amount'         => 'decimal:2',
    ];

    public function report(): BelongsTo
    {
        return $this->belongsTo(MonthlyReport::class, 'monthly_report_id');
    }

    /** `2026-03`, the key the report grid addresses a month by. */
    public function key(): string
    {
        return sprintf('%04d-%02d', $this->forecast_year, $this->forecast_month);
    }
}
