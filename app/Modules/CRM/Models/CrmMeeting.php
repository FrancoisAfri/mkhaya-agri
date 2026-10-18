<?php

namespace App\Modules\CRM\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CrmMeeting extends Model
{
    protected $table = 'crm_meetings';

    protected $fillable = [
        'uuid', 'title', 'related_type', 'related_id', 'audio_path', 'transcript', 'summary',
        'action_items', 'sentiment', 'call_score', 'call_analysis', 'followup_suggested', 'followup_reason',
        'status', 'status_message', 'owner_id', 'created_by',
    ];

    protected $casts = [
        'action_items'       => 'array',
        'call_analysis'      => 'array',
        'followup_suggested' => 'boolean',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }
}
