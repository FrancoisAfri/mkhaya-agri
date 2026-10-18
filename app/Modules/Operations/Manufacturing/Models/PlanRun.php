<?php

namespace App\Modules\Operations\Manufacturing\Models;

use App\Modules\Operations\Manufacturing\Concerns\HasPublicUuid;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One MRP calculation, kept whole for review. */
class PlanRun extends Model
{
    use BelongsToCompany, HasPublicUuid;

    protected $table = 'mfg_plan_runs';

    protected $fillable = ['uuid', 'company_id', 'number', 'starts_on', 'horizon_weeks', 'status', 'summary', 'exceptions', 'run_by'];

    protected $casts = ['starts_on' => 'date', 'horizon_weeks' => 'integer', 'summary' => 'array', 'exceptions' => 'array'];

    public function items(): HasMany { return $this->hasMany(PlanItem::class, 'run_id')->orderBy('level')->orderBy('id'); }

    public function recommendations(): HasMany { return $this->hasMany(PlanRecommendation::class, 'run_id')->orderBy('release_by'); }
}
