<?php

namespace App\Modules\Finance\Accounting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use App\Modules\Finance\Accounting\Traits\BelongsToEntity;
use App\Modules\Tenancy\Traits\BelongsToCompany;

class FinBankAccount extends Model
{
    use BelongsToCompany;
    use BelongsToEntity;

    protected $table = 'fin_bank_accounts';

    protected $fillable = [
        'entity_id', 'uuid', 'company_id', 'account_id', 'name', 'type', 'currency',
        'bank_name', 'branch', 'branch_code', 'account_number', 'is_active',
    ];

    protected $casts = ['is_active' => 'boolean'];

    public const TYPES = ['bank', 'petty_cash', 'loan', 'investment', 'wallet'];

    protected static function booted(): void
    {
        static::creating(fn (FinBankAccount $b) => $b->uuid ??= (string) Str::uuid());
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(FinAccount::class, 'account_id');
    }

    public function statementLines(): HasMany
    {
        return $this->hasMany(FinBankStatementLine::class, 'bank_account_id');
    }
}
