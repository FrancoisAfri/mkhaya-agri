<?php

namespace App\Modules\Operations\Manufacturing\Models;

use App\Modules\Operations\Manufacturing\Concerns\HasPublicUuid;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One thing an inspection plan checks: a measurement with limits, or a pass/fail attribute. */
class InspectionCharacteristic extends Model
{
    use \App\Modules\Operations\Manufacturing\Concerns\AuditsManufacturing;
    use BelongsToCompany, HasPublicUuid;

    public const MEASURED = 'MEASURED';
    public const ATTRIBUTE = 'ATTRIBUTE';

    protected $table = 'mfg_inspection_characteristics';

    protected $fillable = [
        'uuid', 'company_id', 'plan_id', 'sequence', 'name', 'kind', 'uom', 'target', 'lower_limit',
        'upper_limit', 'is_critical', 'method',
    ];

    protected $casts = [
        'sequence' => 'integer', 'target' => 'decimal:6', 'lower_limit' => 'decimal:6',
        'upper_limit' => 'decimal:6', 'is_critical' => 'boolean',
    ];

    public function plan(): BelongsTo { return $this->belongsTo(InspectionPlan::class, 'plan_id'); }
}
