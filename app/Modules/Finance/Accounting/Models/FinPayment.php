<?php

namespace App\Modules\Finance\Accounting\Models;

use App\Modules\Finance\Accounting\Traits\BelongsToEntity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A customer receipt (direction=receipt) or supplier payment (direction=payment),
 * allocated across one or more invoices. Posted to the GL on creation.
 */
class FinPayment extends Model
{
    use BelongsToEntity;

    protected $table = 'fin_payments';

    protected $fillable = [
        'entity_id', 'uuid', 'company_id', 'party_id', 'direction', 'number', 'payment_date',
        'currency', 'amount', 'bank_account_id', 'reference', 'journal_id', 'created_by',
    ];

    protected $casts = [
        'payment_date' => 'date',
        'amount'       => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::creating(function (FinPayment $p) {
            if (empty($p->uuid)) {
                $p->uuid = (string) Str::uuid();
            }
        });
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(FinParty::class, 'party_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(FinPaymentAllocation::class, 'payment_id');
    }
}
