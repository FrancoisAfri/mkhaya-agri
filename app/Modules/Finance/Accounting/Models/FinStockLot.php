<?php

namespace App\Modules\Finance\Accounting\Models;

use App\Modules\Finance\Accounting\Traits\BelongsToEntity;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A lot, batch or serial number of one stock item. A serial is a lot of exactly one.
 *
 * Expiry is a property of the lot and of the date, so "expired" is computed here rather than
 * stored -- stock can never be left available because a nightly job that marks it expired did not
 * run.
 */
class FinStockLot extends Model
{
    use BelongsToCompany, BelongsToEntity;

    protected $table = 'fin_stock_lots';

    protected $fillable = [
        'uuid', 'company_id', 'entity_id', 'item_id', 'lot_number', 'kind', 'manufactured_on',
        'expires_on', 'origin', 'supplier_id', 'supplier_lot_number', 'source_type', 'source_id', 'notes',
    ];

    protected $casts = ['manufactured_on' => 'date', 'expires_on' => 'date'];

    public const LOT = 'LOT';
    public const SERIAL = 'SERIAL';

    public const ORIGINS = ['RECEIPT', 'PRODUCTION', 'SPLIT', 'MERGE', 'RETURN', 'ADJUSTMENT'];

    protected static function booted(): void
    {
        static::creating(fn (self $l) => $l->uuid ??= (string) Str::uuid());
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(FinStockItem::class, 'item_id');
    }

    public function isExpiredOn(?\DateTimeInterface $date = null): bool
    {
        if (! $this->expires_on) {
            return false;
        }

        $day = Carbon::instance($date ?? now())->startOfDay();

        return $this->expires_on->copy()->startOfDay()->lessThan($day);
    }
}
