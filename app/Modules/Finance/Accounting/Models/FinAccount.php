<?php

namespace App\Modules\Finance\Accounting\Models;

use Illuminate\Database\Eloquent\Builder;
use App\Modules\Finance\Accounting\Traits\BelongsToEntity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A chart-of-accounts node. Only postable (leaf) accounts may carry journal lines.
 */
class FinAccount extends Model
{
    use BelongsToEntity;

    protected $table = 'fin_accounts';

    protected $fillable = [
        'entity_id', 'uuid', 'company_id', 'code', 'name', 'type', 'subtype', 'normal_balance',
        'parent_id', 'is_postable', 'is_active', 'tax_code', 'bank_link',
    ];

    protected $casts = [
        'is_postable' => 'boolean',
        'is_active'   => 'boolean',
    ];

    public const TYPES = ['asset', 'liability', 'equity', 'income', 'expense'];

    /** Debit-normal types increase with debits; credit-normal with credits. */
    public const NORMAL_BALANCE = [
        'asset' => 'debit', 'expense' => 'debit',
        'liability' => 'credit', 'equity' => 'credit', 'income' => 'credit',
    ];

    protected static function booted(): void
    {
        static::creating(function (FinAccount $a) {
            if (empty($a->uuid)) {
                $a->uuid = (string) Str::uuid();
            }
            if (empty($a->normal_balance) && isset(self::NORMAL_BALANCE[$a->type])) {
                $a->normal_balance = self::NORMAL_BALANCE[$a->type];
            }
        });
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(FinJournalLine::class, 'account_id');
    }

    public function scopePostable(Builder $q): Builder
    {
        return $q->where('is_postable', true)->where('is_active', true);
    }
}
