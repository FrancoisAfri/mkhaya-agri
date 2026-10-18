<?php

namespace App\Modules\Operations\ProjectManagement\Models;

use App\Modules\Operations\ProjectManagement\Concerns\HasPublicUuid;
use App\Modules\Operations\ProjectManagement\Support\Invoicing\Money;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One line of a subcontractor's invoice: what they did, how much, at what rate.
 *
 * `amount` is stored rather than derived on read so that a rate corrected next month cannot
 * restate an invoice that was approved last month.
 */
class ContractorInvoiceLine extends Model
{
    use BelongsToCompany, HasPublicUuid, SoftDeletes;

    protected $table = 'pm_contractor_invoice_lines';

    protected $fillable = [
        'uuid', 'company_id', 'contractor_invoice_id', 'description', 'quantity', 'rate', 'amount', 'sort',
    ];

    protected $casts = [
        'quantity' => 'decimal:4',
        'rate'     => 'decimal:2',
        'amount'   => 'decimal:2',
        'sort'     => 'integer',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(ContractorInvoice::class, 'contractor_invoice_id');
    }

    /** quantity × rate. */
    public function computedAmount(): string
    {
        return Money::money(Money::mul((string) $this->quantity, (string) $this->rate));
    }

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        return [
            'id'          => $this->uuid,
            'description' => $this->description,
            'quantity'    => (string) $this->quantity,
            'rate'        => Money::money($this->rate),
            'amount'      => Money::money($this->amount),
        ];
    }
}
