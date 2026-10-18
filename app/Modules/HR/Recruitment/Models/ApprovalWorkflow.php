<?php

namespace App\Modules\HR\Recruitment\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class ApprovalWorkflow extends Model
{
    use SoftDeletes;

    protected $table = 'recruitment_approval_workflows';

    protected $fillable = [
        'name',
        'description',
        'entity_type',
        'is_active',
        'created_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            $model->uuid ??= (string) Str::uuid();
        });
    }

    // ── Relationships ─────────────────────────────────────────────────────────

    public function stages(): HasMany
    {
        return $this->hasMany(ApprovalStage::class, 'workflow_id')->orderBy('stage_number');
    }

    public function decisions(): HasMany
    {
        return $this->hasMany(ApprovalDecision::class, 'workflow_id');
    }
}
