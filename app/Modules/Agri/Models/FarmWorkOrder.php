<?php

namespace App\Modules\Agri\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FarmWorkOrder extends FarmOwnedModel
{
    public const CATEGORIES = ['land_preparation', 'planting', 'fertilising', 'spraying', 'weeding', 'pruning', 'irrigation', 'harvesting',
        'grading', 'packing', 'feeding', 'vaccination', 'treatment', 'machinery_service', 'infrastructure', 'fencing', 'borehole', 'cleaning',
        'biosecurity', 'other'];

    public const DRAFT = 'draft';
    public const APPROVED = 'approved';
    public const IN_PROGRESS = 'in_progress';
    public const PAUSED = 'paused';
    public const VERIFICATION = 'verification';
    public const COMPLETED = 'completed';
    public const CANCELLED = 'cancelled';
    public const REJECTED = 'rejected';
    public const STATUSES = [self::DRAFT, self::APPROVED, self::IN_PROGRESS, self::PAUSED, self::VERIFICATION, self::COMPLETED, self::CANCELLED, self::REJECTED];

    /** Still somebody's work. */
    public const OPEN = [self::DRAFT, self::APPROVED, self::IN_PROGRESS, self::PAUSED, self::VERIFICATION, self::REJECTED];

    protected $table = 'farm_work_orders';

    protected $casts = ['planned_start' => \App\Modules\Agri\Casts\FarmDateTime::class, 'planned_end' => \App\Modules\Agri\Casts\FarmDateTime::class, 'required_certifications' => 'array',
        'estimated_hours' => 'float', 'expected_output' => 'float', 'budget_amount' => 'float', 'actual_output' => 'float',
        'actual_labour_cost' => 'float', 'actual_material_cost' => 'float', 'actual_machine_cost' => 'float',
        'approved_at' => \App\Modules\Agri\Casts\FarmDateTime::class, 'started_at' => \App\Modules\Agri\Casts\FarmDateTime::class, 'submitted_at' => \App\Modules\Agri\Casts\FarmDateTime::class, 'verified_at' => \App\Modules\Agri\Casts\FarmDateTime::class, 'completed_at' => \App\Modules\Agri\Casts\FarmDateTime::class];

    public function workers(): HasMany
    {
        return $this->hasMany(WorkOrderWorker::class, 'work_order_id');
    }

    public function materials(): HasMany
    {
        return $this->hasMany(WorkOrderMaterial::class, 'work_order_id');
    }

    public function machines(): HasMany
    {
        return $this->hasMany(WorkOrderMachine::class, 'work_order_id');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(WorkOrderLog::class, 'work_order_id');
    }

    public function statusChanges(): HasMany
    {
        return $this->hasMany(WorkOrderStatusChange::class, 'work_order_id');
    }

    public function cropPlan(): BelongsTo
    {
        return $this->belongsTo(CropPlan::class);
    }

    public function livestockGroup(): BelongsTo
    {
        return $this->belongsTo(LivestockGroup::class);
    }

    public function isOverdue(): bool
    {
        return in_array($this->status, self::OPEN, true) && $this->planned_end->isPast();
    }
}
