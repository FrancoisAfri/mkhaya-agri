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

/** Who did what to a routing revision, and why. */
class RoutingApproval extends Model
{
    use BelongsToCompany, HasPublicUuid;

    protected $table = 'mfg_routing_approvals';

    protected $fillable = ['uuid', 'company_id', 'routing_id', 'action', 'actor_id', 'acted_at', 'comment'];

    protected $casts = ['acted_at' => 'datetime'];
}
