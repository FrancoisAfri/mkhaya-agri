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

/** A work centre permitted to run an operation instead of its primary one, ranked. */
class RoutingOperationAlternate extends Model
{
    use BelongsToCompany, HasPublicUuid;

    protected $table = 'mfg_routing_operation_alternates';

    protected $fillable = ['uuid', 'company_id', 'operation_id', 'work_centre_id', 'priority', 'run_minutes'];

    protected $casts = ['priority' => 'integer', 'run_minutes' => 'decimal:4'];

    public function workCentre(): BelongsTo
    {
        return $this->belongsTo(WorkCentre::class, 'work_centre_id');
    }
}
