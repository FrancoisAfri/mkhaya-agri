<?php

namespace App\Modules\CRM\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single sequence step delivered to an enrollment — the unit of open/click
 * tracking and per-step / A-B analytics.
 */
class CrmSequenceSend extends Model
{
    protected $table = 'crm_sequence_sends';

    protected $fillable = [
        'enrollment_id', 'sequence_id', 'step_index', 'channel', 'variant', 'status', 'token',
        'sent_at', 'opened_at', 'open_count', 'clicked_at', 'click_count', 'replied',
    ];

    protected $casts = [
        'step_index' => 'integer',
        'open_count' => 'integer',
        'click_count' => 'integer',
        'replied'    => 'boolean',
        'sent_at'    => 'datetime',
        'opened_at'  => 'datetime',
        'clicked_at' => 'datetime',
    ];

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(CrmSequenceEnrollment::class, 'enrollment_id');
    }
}
