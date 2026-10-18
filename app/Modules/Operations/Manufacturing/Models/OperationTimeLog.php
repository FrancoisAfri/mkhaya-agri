<?php

namespace App\Modules\Operations\Manufacturing\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One stretch of work on an operation: opened by start or resume, closed by pause or complete. */
class OperationTimeLog extends Model
{
    use BelongsToCompany;

    protected $table = 'mfg_operation_time_logs';

    protected $fillable = ['company_id', 'order_operation_id', 'user_id', 'started_at', 'ended_at', 'minutes', 'ended_by_action'];

    protected $casts = ['started_at' => 'datetime', 'ended_at' => 'datetime', 'minutes' => 'decimal:2'];

    public function operation(): BelongsTo { return $this->belongsTo(ProductionOrderOperation::class, 'order_operation_id'); }

    public function user(): BelongsTo { return $this->belongsTo(\App\Core\User\Models\User::class, 'user_id'); }
}
