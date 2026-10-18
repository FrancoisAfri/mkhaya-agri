<?php

namespace App\Modules\CRM\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class CrmComplaint extends Model
{
    use SoftDeletes;

    protected $table = 'crm_complaints';

    protected $fillable = [
        'uuid', 'reference', 'type', 'source', 'subject', 'body',
        'customer_name', 'customer_email', 'customer_phone',
        'against_employee_id', 'against_name', 'assigned_to', 'manager_id',
        'severity', 'category', 'sentiment', 'ai_summary', 'ai_recommendations',
        'status', 'respond_by', 'resolve_by', 'acknowledged_at', 'resolved_at',
        'escalated', 'escalated_at', 'created_by',
    ];

    protected $casts = [
        'ai_recommendations' => 'array',
        'escalated'          => 'boolean',
        'respond_by'         => 'datetime',
        'resolve_by'         => 'datetime',
        'acknowledged_at'    => 'datetime',
        'resolved_at'        => 'datetime',
        'escalated_at'       => 'datetime',
    ];

    public const SEVERITIES = [
        'low'      => ['label' => 'Low',      'color' => 'success'],
        'medium'   => ['label' => 'Medium',   'color' => 'info'],
        'high'     => ['label' => 'High',     'color' => 'warning'],
        'critical' => ['label' => 'Critical', 'color' => 'error'],
    ];

    public const STATUSES = [
        'new'          => ['label' => 'New',          'color' => 'info'],
        'acknowledged' => ['label' => 'Acknowledged', 'color' => 'primary'],
        'in_progress'  => ['label' => 'In progress',  'color' => 'warning'],
        'escalated'    => ['label' => 'Escalated',    'color' => 'error'],
        'resolved'     => ['label' => 'Resolved',     'color' => 'success'],
        'closed'       => ['label' => 'Closed',       'color' => 'blue-grey'],
    ];

    public function againstEmployee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'against_employee_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    public function updates(): HasMany
    {
        return $this->hasMany(CrmComplaintUpdate::class, 'complaint_id')->latest();
    }

    public function isOverdue(): bool
    {
        return $this->resolve_by
            && ! in_array($this->status, ['resolved', 'closed'], true)
            && $this->resolve_by->isPast();
    }
}
