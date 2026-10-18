<?php

namespace App\Modules\Operations\Manufacturing\Models;

use App\Modules\Operations\Manufacturing\Concerns\HasPublicUuid;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/** A standard this item is inspected against, and at which stage (MFG-002). */
class ItemQualityRequirement extends Model
{
    use BelongsToCompany, HasPublicUuid;

    protected $table = 'mfg_item_quality_requirements';

    protected $fillable = [
        'uuid', 'company_id', 'item_id', 'standard_code', 'standard_name',
        'inspection_stage', 'inspection_plan_reference', 'is_mandatory',
    ];

    protected $casts = ['is_mandatory' => 'boolean'];

    public const STAGES = ['INCOMING', 'IN_PROCESS', 'FINAL'];

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id');
    }
}
