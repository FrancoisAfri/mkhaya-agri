<?php

namespace App\Modules\Operations\ProjectManagement\Models;

use App\Modules\Operations\ProjectManagement\Concerns\HasPublicUuid;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One row of the Determination of Total Fee — stage 3.1 to 3.9.

 * The computed columns (`stage_fee`, `value_to_date`, `this_account`) are written by
 * InvoiceService from ProfessionalFeeAccount rather than calculated on read. An account that has
 * been issued must keep saying what it said when it was sent, whatever the engine does later.
 */
class InvoiceFeeStage extends Model
{
    use BelongsToCompany, HasPublicUuid, SoftDeletes;

    protected $table = 'pm_invoice_fee_stages';

    protected $fillable = [
        'uuid', 'company_id', 'invoice_id', 'code', 'label', 'sort', 'apportionment', 'progress',
        'fixed_value_to_date', 'stage_fee', 'value_to_date', 'previously_claimed', 'this_account',
    ];

    protected $casts = [
        'sort' => 'integer',
        'apportionment' => 'decimal:6',
        'progress' => 'decimal:6',
        'fixed_value_to_date' => 'decimal:2',
        'stage_fee' => 'decimal:2',
        'value_to_date' => 'decimal:2',
        'previously_claimed' => 'decimal:2',
        'this_account' => 'decimal:2',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }

    /** A fixed row takes its value from elsewhere — 3.1 Time basis, fed by Annexure 11. */
    public function isFixed(): bool
    {
        return $this->fixed_value_to_date !== null;
    }
}
