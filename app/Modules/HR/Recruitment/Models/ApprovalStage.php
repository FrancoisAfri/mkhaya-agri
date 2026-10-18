<?php

namespace App\Modules\HR\Recruitment\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ApprovalStage extends Model
{
    protected $table = 'recruitment_approval_stages';

    protected $fillable = [
        'workflow_id',
        'stage_number',
        'name',
        'approver_role',
        'approver_user_id',
        'is_required',
        'min_approvers',
    ];

    protected $casts = [
        'is_required'    => 'boolean',
        'stage_number'   => 'integer',
        'min_approvers'  => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            $model->uuid ??= (string) Str::uuid();
        });
    }

    // ── Relationships ─────────────────────────────────────────────────────────

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(ApprovalWorkflow::class, 'workflow_id');
    }
}
