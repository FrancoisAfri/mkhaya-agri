<?php

namespace App\Modules\Finance\Shared\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class FinanceApprovalRequest extends Model
{
    protected $fillable = [
        'uuid', 'module', 'entity_type', 'entity_id', 'current_level',
        'status', 'requested_by', 'assigned_to', 'priority', 'due_date', 'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'due_date' => 'date',
    ];

    protected static function booted(): void
    {
        static::creating(fn(self $r) => $r->uuid ??= (string) Str::uuid());
    }

    public function getRouteKeyName(): string { return 'uuid'; }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function steps(): HasMany
    {
        return $this->hasMany(FinanceApprovalStep::class, 'approval_request_id');
    }
}
