<?php

namespace App\Modules\Finance\Accounting\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use App\Modules\Finance\Accounting\Traits\BelongsToEntity;
use App\Modules\Tenancy\Traits\BelongsToCompany;

/**
 * A fixed asset in the register. Book value = cost − accumulated depreciation.
 */
class FinAsset extends Model
{
    use BelongsToCompany;
    use BelongsToEntity;

    protected $table = 'fin_assets';

    protected $fillable = [
        'entity_id', 'uuid', 'company_id', 'parent_id', 'tag', 'name', 'category',
        'asset_account_id', 'accum_account_id', 'expense_account_id',
        'acquisition_date', 'cost', 'residual_value', 'useful_life_months',
        'method', 'annual_rate', 'accumulated_depreciation', 'last_depreciated_on',
        'status', 'disposal_date', 'disposal_proceeds',
        'acquisition_journal_id', 'disposal_journal_id', 'created_by',
    ];

    protected $casts = [
        'acquisition_date'          => 'date',
        'last_depreciated_on'       => 'date',
        'disposal_date'             => 'date',
        'cost'                      => 'decimal:2',
        'residual_value'            => 'decimal:2',
        'accumulated_depreciation'  => 'decimal:2',
        'disposal_proceeds'         => 'decimal:2',
        'annual_rate'               => 'decimal:3',
        'useful_life_months'        => 'integer',
    ];

    public const METHODS = ['straight_line' => 'Straight line', 'reducing_balance' => 'Reducing balance'];

    // Keep the in-memory model consistent with the DB defaults so a freshly
    // created asset is immediately usable (status/accumulated depreciation set).
    protected $attributes = [
        'status'                   => 'active',
        'accumulated_depreciation' => 0,
        'residual_value'           => 0,
        'method'                   => 'straight_line',
        'useful_life_months'       => 60,
    ];

    protected static function booted(): void
    {
        static::creating(fn (FinAsset $a) => $a->uuid ??= (string) Str::uuid());
    }

    public function bookValue(): float
    {
        return round((float) $this->cost - (float) $this->accumulated_depreciation, 2);
    }

    public function isDepreciable(): bool
    {
        return $this->status === 'active' && $this->bookValue() > (float) $this->residual_value + 0.001;
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('status', 'active');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function depreciationEntries(): HasMany
    {
        return $this->hasMany(FinAssetDepreciation::class, 'asset_id');
    }

    public function assetAccount(): BelongsTo
    {
        return $this->belongsTo(FinAccount::class, 'asset_account_id');
    }
}
