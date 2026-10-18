<?php

namespace App\Modules\Disciplinary\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DisciplinaryHearingMinute extends Model
{
    protected $table = 'disciplinary_hearing_minutes';

    protected $fillable = [
        'hearing_id', 'minute_type', 'sequence', 'speaker_name', 'content', 'minute_time', 'recorded_by',
    ];

    protected $casts = [
        'minute_time' => 'datetime',
    ];

    public function hearing(): BelongsTo
    {
        return $this->belongsTo(DisciplinaryHearing::class, 'hearing_id');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public static function minuteTypeLabel(string $type): string
    {
        return match ($type) {
            'opening'           => 'Opening',
            'charge_reading'    => 'Charge Reading',
            'plea'              => 'Plea',
            'evidence'          => 'Evidence Presentation',
            'witness_testimony' => 'Witness Testimony',
            'closing'           => 'Closing Statement',
            'procedural'        => 'Procedural Note',
            default             => 'General',
        };
    }
}
