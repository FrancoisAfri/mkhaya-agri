<?php

namespace App\Modules\Operations\WarehouseManagement\Models;

use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Forecast extends Model
{
    use BelongsToCompany;

    protected $table = 'wms_forecasts';

    protected $fillable = ['company_id', 'run_id', 'item_id', 'period_start', 'method', 'qty', 'lower', 'upper', 'override_qty', 'override_reason', 'override_by', 'basis'];

    protected $casts = ['period_start' => 'date', 'qty' => 'decimal:4', 'lower' => 'decimal:4', 'upper' => 'decimal:4', 'override_qty' => 'decimal:4', 'basis' => 'array'];

    public function item(): BelongsTo { return $this->belongsTo(FinStockItem::class, 'item_id'); }

    public function run(): BelongsTo { return $this->belongsTo(ForecastRun::class, 'run_id'); }

    /** What planning uses: the override where somebody made one, else the forecast. */
    public function effective(): ?float
    {
        return $this->override_qty !== null ? (float) $this->override_qty : ($this->qty !== null ? (float) $this->qty : null);
    }
}
