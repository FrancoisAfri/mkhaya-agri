<?php

namespace App\Modules\Finance\Accounting\Models;

use App\Modules\Finance\Accounting\Traits\BelongsToEntity;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A fiscal period (usually a month). Postings are only allowed into open or
 * soft-closed periods; hard-closed accepts reversals only; locked accepts nothing.
 */
class FinFiscalPeriod extends Model
{
    use BelongsToCompany;
    use BelongsToEntity;

    /**
     * A period with no company is the shared calendar every tenant reads.
     *
     * The bootstrap seeds twelve open periods with a null company so the ledger works out of the
     * box. Without this the company scope adds `where company_id = X` the moment a tenant is
     * active, hiding every one of them — and a freshly seeded installation refuses the first
     * posting because it can find no open period, which is the same failure the chart of accounts
     * had for the same reason.
     *
     * It does not stop a tenant owning its own periods. The trait stamps the active company before
     * it consults this, so only a write with no company at all — the seeder, the console — leaves
     * the row shared.
     */
    public static function sharesGlobalRecords(): bool
    {
        return true;
    }

    protected $table = 'fin_fiscal_periods';

    protected $fillable = [
        'entity_id', 'company_id', 'fiscal_year', 'fiscal_year_id', 'period_no', 'start_date', 'end_date', 'status', 'label', 'checklist',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date'   => 'date',
        'checklist'  => 'array',
    ];

    public const STATUSES = ['open', 'soft_close', 'hard_close', 'locked'];

    /** Manual pre-close checklist items (computed checks live in PeriodService). */
    public const CHECKLIST_ITEMS = ['bank_reconciled', 'vat_submitted', 'inventory_count', 'payroll_posted'];

    /** UI colour per status (traffic-light) — green→yellow→orange→red. */
    public const STATUS_COLOR = ['open' => 'success', 'soft_close' => 'warning', 'hard_close' => 'orange', 'locked' => 'error'];

    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FinFiscalYear::class, 'fiscal_year_id');
    }

    /** The next lifecycle actions allowed from the current status (drives the UI buttons). */
    public function allowedActions(): array
    {
        return match ($this->status) {
            'open'       => ['soft_close', 'hard_close'],
            'soft_close' => ['reopen', 'hard_close'],
            'hard_close' => ['reopen', 'lock'],
            'locked'     => ['unlock'],
            default      => [],
        };
    }

    public function acceptsPostings(): bool
    {
        // Only an open period takes new postings. Soft-close blocks new activity
        // but still allows corrections (reversals) — see acceptsReversals().
        return $this->status === 'open';
    }

    public function acceptsReversals(): bool
    {
        return in_array($this->status, ['open', 'soft_close', 'hard_close'], true);
    }

    /** The period whose date-range contains $date for a company (null if none). */
    public static function forDate(CarbonInterface $date, ?int $companyId): ?self
    {
        $within = fn ($q) => $q->whereDate('start_date', '<=', $date)->whereDate('end_date', '>=', $date);

        if ($companyId) {
            // The company's own calendar decides where it keeps one — a tenant that has closed
            // March must not go on posting into the shared March.
            return static::query()->where('company_id', $companyId)->where($within)->first()
                ?? static::query()->whereNull('company_id')->where($within)->first();
        }

        return static::query()->where($within)->first();
    }
}
