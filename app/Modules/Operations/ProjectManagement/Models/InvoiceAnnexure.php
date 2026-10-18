<?php

namespace App\Modules\Operations\ProjectManagement\Models;

use App\Modules\Operations\ProjectManagement\Concerns\HasPublicUuid;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One annexure attached to an account.

 * Only the annexures actually used appear on an account, which is why this is a row rather than a
 * fixed set of thirteen. `line_type` and `surcharge_rate` are copied from AnnexureCatalogue when
 * the annexure is added, so an account issued today keeps the rules it was issued under even if
 * the catalogue is later changed.
 */
class InvoiceAnnexure extends Model
{
    use BelongsToCompany, HasPublicUuid, SoftDeletes;

    protected $table = 'pm_invoice_annexures';

    protected $fillable = [
        'uuid', 'company_id', 'invoice_id', 'number', 'title', 'line_type', 'surcharge_rate', 'projections',
        'previously_claimed', 'this_invoice', 'total',
    ];

    protected $casts = [
        'number' => 'integer',
        'surcharge_rate' => 'decimal:6',
        'projections' => 'array',
        'previously_claimed' => 'decimal:2',
        'this_invoice' => 'decimal:2',
        'total' => 'decimal:2',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceAnnexureItem::class, 'annexure_id')->orderBy('sort');
    }
}
