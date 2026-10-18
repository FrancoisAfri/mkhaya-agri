<?php

namespace App\Modules\CRM\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CrmSequenceEnrollment extends Model
{
    protected $table = 'crm_sequence_enrollments';

    protected $fillable = [
        'sequence_id', 'lead_id', 'contact_id', 'name', 'email', 'phone', 'current_step',
        'status', 'reply_intent', 'next_step_at', 'last_sent_at', 'enrolled_at',
        'variant', 'completed_at', 'goal_met_at', 'replied_at', 'last_reply',
    ];

    protected $casts = [
        'next_step_at' => 'datetime',
        'last_sent_at' => 'datetime',
        'enrolled_at'  => 'datetime',
        'completed_at' => 'datetime',
        'goal_met_at'  => 'datetime',
        'replied_at'   => 'datetime',
    ];

    public function sequence(): BelongsTo
    {
        return $this->belongsTo(CrmSequence::class, 'sequence_id');
    }
}
