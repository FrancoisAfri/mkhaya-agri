<?php

namespace App\Modules\PerformanceAppraisal\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaAiInsight extends Model
{
    protected $table = 'pa_ai_insights';

    protected $fillable = [
        'employee_id', 'cycle_id', 'insight_type', 'severity',
        'title', 'body', 'data', 'acknowledged_by', 'acknowledged_at',
    ];

    protected $casts = [
        'data'             => 'array',
        'acknowledged_at'  => 'datetime',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employee_id');
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(PaCycle::class, 'cycle_id');
    }

    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }

    public function scopeUnacknowledged($query)
    {
        return $query->whereNull('acknowledged_at');
    }
}
