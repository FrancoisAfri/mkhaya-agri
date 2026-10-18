<?php

namespace App\Modules\Operations\ProjectManagement\Models;

use App\Modules\Operations\ProjectManagement\Concerns\HasPublicUuid;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A Form 2 row, in one of the form's two blocks.

 * `block` is 'fee' for the rows rated at the disbursement fee rate, and 'category' for the
 * annexure-fed schedule rated at VAT. Keeping them in one table with a discriminator matches the
 * form, where the two blocks are added together at the foot.
 */
class InvoiceDisbursement extends Model
{
    use BelongsToCompany, HasPublicUuid, SoftDeletes;

    protected $table = 'pm_invoice_disbursements';

    public const BLOCKS = ['fee', 'category'];
    protected $fillable = [
        'uuid', 'company_id', 'invoice_id', 'block', 'description', 'reference', 'sort',
        'this_account', 'previously_claimed', 'total',
    ];

    protected $casts = [
        'sort' => 'integer',
        'this_account' => 'decimal:2',
        'previously_claimed' => 'decimal:2',
        'total' => 'decimal:2',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }
}
