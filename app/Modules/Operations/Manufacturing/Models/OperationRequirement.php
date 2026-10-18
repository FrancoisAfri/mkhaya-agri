<?php

namespace App\Modules\Operations\Manufacturing\Models;

use App\Modules\Operations\Manufacturing\Concerns\HasPublicUuid;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What an operator needs to do an operation, or to work at a centre: a skill at a level, a current
 * certification, or a current SHEQ competency -- read from where HR, learning and SHEQ keep them.
 */
class OperationRequirement extends Model
{
    use \App\Modules\Operations\Manufacturing\Concerns\AuditsManufacturing;
    use BelongsToCompany, HasPublicUuid;

    public const KINDS = ['SKILL', 'CERTIFICATION', 'COMPETENCY'];

    protected $table = 'mfg_operation_requirements';

    protected $fillable = ['uuid', 'company_id', 'routing_operation_id', 'work_centre_id', 'kind', 'reference_id', 'min_level', 'is_mandatory'];

    protected $casts = ['min_level' => 'integer', 'is_mandatory' => 'boolean'];

    public function routingOperation(): BelongsTo { return $this->belongsTo(RoutingOperation::class, 'routing_operation_id')->withoutGlobalScopes(); }

    public function workCentre(): BelongsTo { return $this->belongsTo(WorkCentre::class, 'work_centre_id'); }
}
