<?php

namespace App\Modules\Operations\Manufacturing\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The definitions a work centre's OEE is calculated from (MFG-013), configured, never assumed. */
class OeeSetting extends Model
{
    use \App\Modules\Operations\Manufacturing\Concerns\AuditsManufacturing;
    use BelongsToCompany;

    protected $table = 'mfg_oee_settings';

    protected $fillable = ['company_id', 'work_centre_id', 'ideal_cycle_seconds', 'ideal_from_routing', 'exclude_planned_downtime', 'good_output'];

    protected $casts = ['ideal_cycle_seconds' => 'decimal:3', 'ideal_from_routing' => 'boolean', 'exclude_planned_downtime' => 'boolean'];

    public function workCentre(): BelongsTo { return $this->belongsTo(WorkCentre::class, 'work_centre_id'); }
}
