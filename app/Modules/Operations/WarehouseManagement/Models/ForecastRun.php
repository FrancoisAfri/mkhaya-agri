<?php

namespace App\Modules\Operations\WarehouseManagement\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/** One forecasting pass, kept whole so its accuracy can be judged later (WMS-120). */
class ForecastRun extends Model
{
    use BelongsToCompany;

    protected $table = 'wms_forecast_runs';

    protected $fillable = ['uuid', 'company_id', 'warehouse_id', 'bucket', 'horizon', 'history_periods', 'params', 'items', 'insufficient', 'created_by'];

    protected $casts = ['params' => 'array'];

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->uuid ??= (string) Str::uuid());
    }

    public function forecasts(): HasMany { return $this->hasMany(Forecast::class, 'run_id'); }
}
