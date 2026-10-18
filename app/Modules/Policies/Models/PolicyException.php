<?php

namespace App\Modules\Policies\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PolicyException extends Model
{
    use SoftDeletes;
    use HasFactory;

    protected $table = 'policy_exceptions';

    protected $fillable = [
        'policy_id',
        'rule_id',
        'requested_by',
        'reason',
        'evidence',
        'valid_from',
        'valid_until',
        'status',
        'approved_by',
        'decided_at',
        'decision_notes',
        'auto_expire_notified',
    ];

    protected $casts = [
        'valid_from' => 'date',
        'valid_until' => 'date',
        'decided_at' => 'datetime',
        'auto_expire_notified' => 'boolean',
    ];

    public function policy(): BelongsTo
    {
        return $this->belongsTo(Policy::class, 'policy_id');
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(PolicyRule::class, 'rule_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
