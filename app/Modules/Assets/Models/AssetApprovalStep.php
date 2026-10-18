<?php

namespace App\Modules\Assets\Models;

use App\Core\User\Models\User;
use App\Modules\Assets\Enums\ApprovalStatus;
use App\Shared\Traits\Uuids;
use Database\Factories\Assets\AssetApprovalStepFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssetApprovalStep extends Model
{
    use Uuids, HasFactory;

    protected static function newFactory(): Factory
    {
        return AssetApprovalStepFactory::new();
    }

    protected $table = 'asset_approval_steps';

    protected $fillable = [
        'uuid',
        'approval_id',
        'step_number',
        'approver_id',
        'status',
        'decided_at',
        'notes',
    ];

    protected $casts = [
        'step_number' => 'integer',
        'status'      => ApprovalStatus::class,
        'decided_at'  => 'datetime',
    ];

    public function approval(): BelongsTo
    {
        return $this->belongsTo(AssetApproval::class, 'approval_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_id');
    }
}
