<?php

namespace App\Modules\Assets\Models;

use App\Core\User\Models\User;
use App\Modules\Assets\Enums\ApprovalStatus;
use App\Modules\Assets\Enums\ApprovalType;
use App\Shared\Traits\Uuids;
use Database\Factories\Assets\AssetApprovalFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class AssetApproval extends Model
{
    use Uuids, HasFactory;

    protected static function newFactory(): Factory
    {
        return AssetApprovalFactory::new();
    }

    protected $table = 'asset_approvals';

    protected $fillable = [
        'uuid',
        'type',
        'approvable_type',
        'approvable_id',
        'requested_by',
        'current_step',
        'total_steps',
        'status',
        'notes',
        'completed_at',
    ];

    protected $casts = [
        'type'         => ApprovalType::class,
        'status'       => ApprovalStatus::class,
        'current_step' => 'integer',
        'total_steps'  => 'integer',
        'completed_at' => 'datetime',
    ];

    public function approvable(): MorphTo
    {
        return $this->morphTo();
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function steps(): HasMany
    {
        return $this->hasMany(AssetApprovalStep::class, 'approval_id')->orderBy('step_number');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(AssetApprovalComment::class, 'approval_id')->latest();
    }

    public function currentStep(): ?AssetApprovalStep
    {
        return $this->steps()->where('step_number', $this->current_step)->first();
    }

    public function isPending(): bool
    {
        return $this->status === ApprovalStatus::Pending;
    }

    public function isComplete(): bool
    {
        return in_array($this->status, [ApprovalStatus::Approved, ApprovalStatus::Rejected, ApprovalStatus::Cancelled]);
    }
}
