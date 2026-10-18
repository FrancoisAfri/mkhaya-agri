<?php

namespace App\Modules\Operations\WarehouseManagement\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** Getting a loss back from an insurer, a supplier, a carrier or a person (WMS-081). */
class LossRecovery extends Model
{
    use BelongsToCompany;

    protected $table = 'wms_loss_recoveries';

    protected $fillable = ['uuid', 'company_id', 'adjustment_id', 'kind', 'party_id', 'claim_reference', 'amount_claimed',
        'amount_recovered', 'status', 'notes', 'created_by', 'settled_at'];

    protected $casts = ['amount_claimed' => 'decimal:2', 'amount_recovered' => 'decimal:2', 'settled_at' => 'datetime'];

    public const KINDS = ['INSURANCE', 'SUPPLIER', 'CARRIER', 'STAFF', 'OTHER'];

    public const STATUSES = ['OPEN', 'SUBMITTED', 'SETTLED', 'DECLINED', 'WITHDRAWN'];

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->uuid ??= (string) Str::uuid());
    }

    public function adjustment(): BelongsTo { return $this->belongsTo(StockAdjustment::class, 'adjustment_id'); }
}
