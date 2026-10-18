<?php

namespace App\Modules\HR\Hr\Models\Attendance;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class RosterSchedule extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name', 'department', 'period_start', 'period_end',
        'status', 'generated_by', 'ai_optimization_notes',
        'coverage_score', 'balance_score', 'created_by', 'published_at',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end'   => 'date',
        'published_at' => 'datetime',
    ];

    const STATUSES = ['draft', 'published', 'archived'];
    const STATUS_COLORS = ['draft' => 'warning', 'published' => 'success', 'archived' => 'secondary'];

    public function assignments(): HasMany
    {
        return $this->hasMany(RosterAssignment::class);
    }

    public function optimizationLogs(): HasMany
    {
        return $this->hasMany(RosterOptimizationLog::class);
    }

    public function getStatusColorAttribute(): string
    {
        return self::STATUS_COLORS[$this->status] ?? 'grey';
    }

    public function scopePublished($query)
    {
        return $query->where('status', 'published');
    }

    public function scopeDraft($query)
    {
        return $query->where('status', 'draft');
    }
}
