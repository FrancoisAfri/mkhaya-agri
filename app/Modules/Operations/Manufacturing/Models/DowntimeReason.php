<?php

namespace App\Modules\Operations\Manufacturing\Models;

use App\Modules\Operations\Manufacturing\Concerns\HasPublicUuid;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/** Why a machine or line stopped. Planned reasons leave OEE availability alone; unplanned ones count. */
class DowntimeReason extends Model
{
    use \App\Modules\Operations\Manufacturing\Concerns\AuditsManufacturing;
    use BelongsToCompany, HasPublicUuid;

    public const CATEGORIES = ['BREAKDOWN', 'CHANGEOVER', 'SETUP', 'MATERIAL', 'QUALITY', 'LABOUR', 'PLANNED_MAINTENANCE', 'UTILITIES', 'OTHER'];

    protected $table = 'mfg_downtime_reasons';

    protected $fillable = ['uuid', 'company_id', 'code', 'name', 'category', 'is_planned', 'is_active'];

    protected $casts = ['is_planned' => 'boolean', 'is_active' => 'boolean'];
}
