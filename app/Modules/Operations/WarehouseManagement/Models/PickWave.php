<?php

namespace App\Modules\Operations\WarehouseManagement\Models;

use App\Modules\Finance\Accounting\Models\FinStockBin;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Models\FinStockLot;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/** A batch of pick work released together, under one strategy (WMS-062). */
class PickWave extends Model
{
    use BelongsToCompany;

    protected $table = 'wms_pick_waves';

    protected $fillable = ['uuid', 'company_id', 'number', 'warehouse_id', 'strategy', 'status', 'zone', 'assigned_to', 'started_at', 'completed_at'];

    protected $casts = ['started_at' => 'datetime', 'completed_at' => 'datetime'];

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->uuid ??= (string) Str::uuid());
    }

    public const STRATEGIES = ['DISCRETE', 'BATCH', 'ZONE', 'WAVE', 'CLUSTER'];

    public function tasks(): HasMany { return $this->hasMany(PickTask::class, 'wave_id')->orderBy('sequence'); }

    public function warehouse(): BelongsTo { return $this->belongsTo(FinWarehouse::class, 'warehouse_id'); }
}
