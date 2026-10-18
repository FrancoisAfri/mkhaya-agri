<?php

namespace App\Modules\Operations\ProjectManagement\Models;

use App\Modules\Operations\ProjectManagement\Concerns\HasPublicUuid;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A month of the projected cashflow.

 * VAT-inclusive, as the client's sheet has it, with the running cumulative the sheet's second
 * column carries. Hung off the project rather than the invoice so the forecast survives an
 * account being cancelled; `invoice_id` records which account, if any, realised the month.
 */
class InvoiceCashflow extends Model
{
    use BelongsToCompany, HasPublicUuid, SoftDeletes;

    protected $table = 'pm_invoice_cashflows';

    protected $fillable = [
        'uuid', 'company_id', 'project_id', 'invoice_id', 'forecast_year', 'forecast_month',
        'amount_incl_vat', 'cumulative',
    ];

    protected $casts = [
        'forecast_year' => 'integer',
        'forecast_month' => 'integer',
        'amount_incl_vat' => 'decimal:2',
        'cumulative' => 'decimal:2',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }
}
