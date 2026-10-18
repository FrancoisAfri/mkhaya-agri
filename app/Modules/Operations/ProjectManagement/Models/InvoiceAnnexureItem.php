<?php

namespace App\Modules\Operations\ProjectManagement\Models;

use App\Modules\Operations\ProjectManagement\Concerns\HasPublicUuid;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A line on an annexure.

 * Deliberately wide and mostly nullable: thirteen annexures share one table because they share one
 * shape — a date, a description, some measure, and the previously-claimed / this-invoice / total
 * tail. Which measure applies is decided by the annexure's `line_type`, not by a separate table
 * per annexure, which would be thirteen near-identical tables and thirteen places to fix a bug.
 */
class InvoiceAnnexureItem extends Model
{
    use BelongsToCompany, HasPublicUuid, SoftDeletes;

    protected $table = 'pm_invoice_annexure_items';

    protected $fillable = [
        'uuid', 'company_id', 'annexure_id', 'sort', 'entry_date', 'description', 'party',
        'post_level', 'cc', 'size', 'variant', 'quantity', 'tariff', 'kilometres', 'less_kilometres',
        'hours', 'less_hours', 'appointment_amount', 'kimopax_fee', 'claimed_this_period',
        'previously_claimed', 'this_invoice', 'total',
    ];

    protected $casts = [
        'sort' => 'integer',
        'entry_date' => 'date',
        'quantity' => 'decimal:4',
        'tariff' => 'decimal:4',
        'kilometres' => 'decimal:4',
        'less_kilometres' => 'decimal:4',
        'hours' => 'decimal:4',
        'less_hours' => 'decimal:4',
        'appointment_amount' => 'decimal:2',
        'kimopax_fee' => 'decimal:2',
        'claimed_this_period' => 'boolean',
        'previously_claimed' => 'decimal:2',
        'this_invoice' => 'decimal:2',
        'total' => 'decimal:2',
    ];

    public function annexure(): BelongsTo
    {
        return $this->belongsTo(InvoiceAnnexure::class, 'annexure_id');
    }
}
