<?php

namespace App\Modules\Operations\Manufacturing\Models;

use App\Modules\Operations\Manufacturing\Concerns\HasPublicUuid;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A preventive maintenance rule: raise a work order every so many days, run hours, or breakdowns. */
class PmRule extends Model
{
    use \App\Modules\Operations\Manufacturing\Concerns\AuditsManufacturing;
    use BelongsToCompany, HasPublicUuid;

    public const KINDS = ['TIME', 'USAGE', 'CONDITION'];

    protected $table = 'mfg_pm_rules';

    protected $fillable = [
        'uuid', 'company_id', 'asset_id', 'title', 'kind', 'interval_days', 'interval_run_hours', 'breakdowns_threshold',
        'window_days', 'tasks', 'work_order_type', 'priority', 'assign_to', 'last_triggered_at', 'run_hours_at_last',
        'is_active', 'created_by',
    ];

    protected $casts = [
        'interval_days' => 'integer', 'interval_run_hours' => 'decimal:2', 'breakdowns_threshold' => 'integer',
        'window_days' => 'integer', 'last_triggered_at' => 'datetime', 'run_hours_at_last' => 'decimal:2', 'is_active' => 'boolean',
    ];

    public function asset(): BelongsTo { return $this->belongsTo(\App\Modules\Assets\Models\Assets::class, 'asset_id'); }

    public function raised(): HasMany { return $this->hasMany(PmWorkOrder::class, 'rule_id')->orderByDesc('id'); }
}
