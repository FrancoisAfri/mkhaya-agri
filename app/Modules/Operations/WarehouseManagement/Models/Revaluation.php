<?php

namespace App\Modules\Operations\WarehouseManagement\Models;

use App\Core\User\Models\User;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** A change to what stock on hand is worth, approved by a second person (WMS-101). */
class Revaluation extends Model
{
    use BelongsToCompany;

    protected $table = 'wms_revaluations';

    protected $fillable = ['override_id', 'uuid', 'company_id', 'number', 'item_id', 'qty', 'old_cost', 'new_cost', 'delta', 'reason', 'status',
        'requested_by', 'decided_by', 'decided_at', 'decision_note', 'journal_id'];

    protected $casts = ['qty' => 'decimal:4', 'old_cost' => 'decimal:4', 'new_cost' => 'decimal:4', 'delta' => 'decimal:2', 'decided_at' => 'datetime'];

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->uuid ??= (string) Str::uuid());
    }

    public function item(): BelongsTo { return $this->belongsTo(FinStockItem::class, 'item_id'); }

    public function requester(): BelongsTo { return $this->belongsTo(User::class, 'requested_by'); }
}
