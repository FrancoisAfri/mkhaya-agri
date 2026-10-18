<?php

namespace App\Modules\Finance\Accounting\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use App\Modules\Finance\Accounting\Traits\BelongsToEntity;
use App\Modules\Tenancy\Traits\BelongsToCompany;

/**
 * A sellable/purchasable item (product or service) with its own pricing and GL
 * account mapping. Selecting it on a document line fills price, tax and account.
 */
class FinItem extends Model
{
    use BelongsToCompany;
    use BelongsToEntity;

    protected $table = 'fin_items';

    protected $fillable = [
        'entity_id', 'uuid', 'company_id', 'code', 'name', 'description', 'image', 'unit', 'type',
        'sales_price', 'cost_price', 'sales_account_id', 'purchase_account_id',
        'tax_rate', 'tax_code', 'is_active',
    ];

    protected $casts = [
        'sales_price' => 'decimal:4',
        'cost_price'  => 'decimal:4',
        'tax_rate'    => 'decimal:3',
        'is_active'   => 'boolean',
    ];

    public const TYPES = ['service', 'product'];

    protected static function booted(): void
    {
        static::creating(function (FinItem $i) {
            if (empty($i->uuid)) {
                $i->uuid = (string) Str::uuid();
            }
            // Auto-generate an item code when none is given, so "add new item" from a line
            // dropdown never blocks on a code. Sequential, tenant-scoped by RLS.
            if (empty($i->code)) {
                $n = static::query()->count() + 1;
                do {
                    $code = 'ITEM' . str_pad((string) $n, 4, '0', STR_PAD_LEFT);
                    $n++;
                } while (static::query()->where('code', $code)->exists());
                $i->code = $code;
            }
        });
    }

    public function salesAccount(): BelongsTo
    {
        return $this->belongsTo(FinAccount::class, 'sales_account_id');
    }

    public function purchaseAccount(): BelongsTo
    {
        return $this->belongsTo(FinAccount::class, 'purchase_account_id');
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }
}
