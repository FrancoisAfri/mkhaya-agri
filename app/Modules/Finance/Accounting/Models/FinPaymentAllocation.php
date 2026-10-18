<?php

namespace App\Modules\Finance\Accounting\Models;

use App\Modules\Finance\Accounting\Traits\BelongsToEntity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinPaymentAllocation extends Model
{
    use BelongsToEntity;

    protected $table = 'fin_payment_allocations';

    protected $fillable = ['entity_id', 'payment_id', 'invoice_id', 'amount'];

    protected $casts = ['amount' => 'decimal:2'];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(FinPayment::class, 'payment_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(FinInvoice::class, 'invoice_id');
    }
}
