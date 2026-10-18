<?php

namespace App\Modules\HR\LMS\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LmsTrainingReminder extends Model
{
    protected $table = 'lms_training_reminders';

    public const TYPES = ['upcoming_due', 'overdue', 'escalation'];

    protected $fillable = [
        'assignment_id',
        'user_id',
        'reminder_type',
        'days_relative',
        'sent_at',
        'sent_via',
    ];

    protected $casts = [
        'sent_at'      => 'datetime',
        'days_relative'=> 'integer',
    ];

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(LmsComplianceAssignment::class, 'assignment_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
