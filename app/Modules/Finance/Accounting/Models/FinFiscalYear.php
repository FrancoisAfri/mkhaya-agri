<?php

namespace App\Modules\Finance\Accounting\Models;

use App\Modules\Finance\Accounting\Traits\BelongsToEntity;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A company's financial year (e.g. "FY2026/2027", 01 Aug 2026 → 31 Jul 2027). Each tenant may run
 * its own fiscal calendar (start month from settings). Owns 12 monthly fiscal periods.
 */
class FinFiscalYear extends Model
{
    use BelongsToCompany;
    use BelongsToEntity;

    protected $table = 'fin_fiscal_years';

    protected $fillable = ['entity_id', 'company_id', 'label', 'start_date', 'end_date', 'status', 'is_current', 'closed_at'];

    protected $casts = [
        'start_date' => 'date',
        'end_date'   => 'date',
        'is_current' => 'boolean',
        'closed_at'  => 'datetime',
    ];

    public const STATUSES = ['open', 'closed', 'archived'];

    public function periods(): HasMany
    {
        return $this->hasMany(FinFiscalPeriod::class, 'fiscal_year_id')->orderBy('period_no');
    }

    /** Progress: how many periods are past-close (soft/hard/locked) out of the total. */
    public function progress(): array
    {
        $periods = $this->relationLoaded('periods') ? $this->periods : $this->periods()->get();
        $total = $periods->count();
        $done = $periods->whereIn('status', ['soft_close', 'hard_close', 'locked'])->count();

        return [
            'total'   => $total,
            'done'    => $done,
            'percent' => $total ? (int) round($done / $total * 100) : 0,
        ];
    }
}
