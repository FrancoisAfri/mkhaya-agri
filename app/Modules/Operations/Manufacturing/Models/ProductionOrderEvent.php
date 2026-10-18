<?php

namespace App\Modules\Operations\Manufacturing\Models;

use App\Modules\Operations\Manufacturing\Concerns\HasPublicUuid;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A change of state, an exception or a correction on an order -- who, when, and why. */
class ProductionOrderEvent extends Model
{
    use BelongsToCompany, HasPublicUuid;

    protected $table = 'mfg_production_order_events';

    protected $fillable = [
        'uuid', 'company_id', 'order_id', 'action', 'from_status', 'to_status', 'is_exception',
        'reason', 'detail', 'actor_id', 'acted_at',
    ];

    protected $casts = ['is_exception' => 'boolean', 'detail' => 'array', 'acted_at' => 'datetime'];
}
