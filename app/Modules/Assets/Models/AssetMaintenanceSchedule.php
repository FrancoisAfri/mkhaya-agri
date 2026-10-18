<?php

namespace App\Modules\Assets\Models;

use App\Core\User\Models\User;
use Database\Factories\Assets\AssetMaintenanceScheduleFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * M3 — Maintenance Schedule
 */
class AssetMaintenanceSchedule extends Model
{
    use HasFactory, SoftDeletes;

    protected static function newFactory(): Factory
    {
        return AssetMaintenanceScheduleFactory::new();
    }

    protected $table = 'asset_maintenance_schedules';

    protected $fillable = [
        'uuid',
        'asset_id',
        'title',
        'description',
        'maintenance_type',
        'status',
        'assigned_to',
        'vendor_name',
        'estimated_cost',
        'actual_cost',
        'due_date',
        'started_at',
        'completed_at',
        'next_due_date',
        'recurrence_days',
        'approval_id',
        'notes',
    ];

    protected $casts = [
        'estimated_cost'  => 'decimal:2',
        'actual_cost'     => 'decimal:2',
        'due_date'        => 'date',
        'next_due_date'   => 'date',
        'started_at'      => 'datetime',
        'completed_at'    => 'datetime',
        'recurrence_days' => 'integer',
        'status'          => \App\Modules\Assets\Enums\MaintenanceStatus::class,
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (!$model->uuid) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    // Relationships
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Assets::class, 'asset_id');
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(AssetMaintenanceLog::class, 'schedule_id');
    }

    public function isDue(): bool
    {
        return $this->next_due_date && $this->next_due_date <= now();
    }

    public function isOverdue(): bool
    {
        return $this->next_due_date && $this->next_due_date < now();
    }
}
