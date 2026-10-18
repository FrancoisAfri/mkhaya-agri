<?php

namespace App\Modules\Finance\Accounting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use App\Modules\Finance\Accounting\Traits\BelongsToEntity;
use App\Modules\Tenancy\Traits\BelongsToCompany;

/**
 * A transfer of funds between two of the company's own bank / cash accounts.
 * Backed by a single balanced journal in the ledger.
 */
class FinBankTransfer extends Model
{
    use BelongsToCompany;
    use BelongsToEntity;

    protected $table = 'fin_bank_transfers';

    protected $fillable = [
        'entity_id', 'uuid', 'company_id', 'from_bank_id', 'to_bank_id', 'amount', 'fee',
        'transfer_date', 'reference', 'description', 'journal_id', 'created_by',
    ];

    protected $casts = [
        'amount'        => 'decimal:2',
        'fee'           => 'decimal:2',
        'transfer_date' => 'date',
    ];

    protected static function booted(): void
    {
        static::creating(fn (FinBankTransfer $t) => $t->uuid ??= (string) Str::uuid());
    }

    public function fromBank(): BelongsTo
    {
        return $this->belongsTo(FinBankAccount::class, 'from_bank_id');
    }

    public function toBank(): BelongsTo
    {
        return $this->belongsTo(FinBankAccount::class, 'to_bank_id');
    }
}
