<?php

namespace App\Modules\Disciplinary\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DisciplinaryCaseTimeline extends Model
{
    protected $table = 'disciplinary_case_timelines';

    protected $fillable = [
        'case_id', 'incident_id', 'event_type', 'event_label',
        'event_detail', 'event_date', 'actor_id', 'actor_name',
        'is_ai_inferred', 'metadata',
    ];

    protected $casts = [
        'event_date'    => 'datetime',
        'is_ai_inferred'=> 'boolean',
        'metadata'      => 'array',
    ];

    public function case(): BelongsTo
    {
        return $this->belongsTo(DisciplinaryCase::class, 'case_id');
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(DisciplinaryIncident::class, 'incident_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public static function eventIcon(string $type): string
    {
        return match ($type) {
            'incident'              => 'mdi-alert-circle-outline',
            'report'                => 'mdi-file-alert-outline',
            'case_opened'           => 'mdi-folder-open-outline',
            'notice_issued'         => 'mdi-email-outline',
            'investigator_assigned' => 'mdi-account-search',
            'hearing_scheduled'     => 'mdi-calendar-clock',
            'hearing_held'          => 'mdi-gavel',
            'outcome'               => 'mdi-check-circle-outline',
            'closed'                => 'mdi-lock-check',
            'evidence_added'        => 'mdi-paperclip',
            'witness_added'         => 'mdi-account-plus-outline',
            default                 => 'mdi-circle-small',
        };
    }

    public static function eventColor(string $type): string
    {
        return match ($type) {
            'incident', 'report'     => 'warning',
            'case_opened'            => 'primary',
            'hearing_held', 'outcome'=> 'purple',
            'closed'                 => 'grey',
            default                  => 'blue',
        };
    }
}
