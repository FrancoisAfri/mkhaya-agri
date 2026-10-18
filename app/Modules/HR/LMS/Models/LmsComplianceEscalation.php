<?php

namespace App\Modules\HR\LMS\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LmsComplianceEscalation extends Model
{
    protected $table = 'lms_compliance_escalations';

    public const STATUSES = ['open', 'acknowledged', 'resolved'];

    protected $fillable = [
        'assignment_id', 'user_id', 'escalated_to_id',
        'escalated_to_role', 'days_overdue', 'note',
        'status', 'resolved_at', 'resolved_by', 'escalated_at',
    ];

    protected $casts = [
        'days_overdue'  => 'integer',
        'resolved_at'   => 'datetime',
        'escalated_at'  => 'datetime',
    ];

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(LmsComplianceAssignment::class, 'assignment_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function escalatedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'escalated_to_id');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function scopeOpen($query)
    {
        return $query->where('status', 'open');
    }
}
