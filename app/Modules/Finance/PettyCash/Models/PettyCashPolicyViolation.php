<?php

namespace App\Modules\Finance\PettyCash\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class PettyCashPolicyViolation extends Model
{
    protected $table = 'petty_cash_policy_violations';

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
            if (empty($model->detected_at)) {
                $model->detected_at = now();
            }
        });
    }

    protected $fillable = [
        'uuid', 'request_id', 'reconciliation_id', 'float_id', 'detected_by_user',
        'violation_type', 'violation_description', 'severity', 'policy_rule', 'metadata',
        'status', 'detected_at', 'resolved_at', 'resolved_by', 'resolution_notes',
    ];

    protected $casts = [
        'metadata'    => 'array',
        'detected_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function request(): BelongsTo
    {
        return $this->belongsTo(PettyCashRequest::class, 'request_id');
    }

    public function reconciliation(): BelongsTo
    {
        return $this->belongsTo(PettyCashReconciliation::class, 'reconciliation_id');
    }

    public function float(): BelongsTo
    {
        return $this->belongsTo(PettyCashFloat::class, 'float_id');
    }

    public function detectedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'detected_by_user');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
