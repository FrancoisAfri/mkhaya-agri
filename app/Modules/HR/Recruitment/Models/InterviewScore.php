<?php

namespace App\Modules\HR\Recruitment\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class InterviewScore extends Model
{
    use HasFactory, LogsActivity;

    protected $table = 'interview_scores';

    protected $fillable = [
        'interview_id',
        'scored_by',
        'technical_score',
        'communication_score',
        'culture_fit_score',
        'overall_score',
        'recommendation',
        'comments',
        'scored_at',
    ];

    protected $casts = [
        'technical_score'    => 'decimal:2',
        'communication_score'=> 'decimal:2',
        'culture_fit_score'  => 'decimal:2',
        'overall_score'      => 'decimal:2',
        'scored_at'          => 'datetime',
    ];

    // Relationships

    public function interview()
    {
        return $this->belongsTo(Interview::class);
    }

    public function scoredBy()
    {
        return $this->belongsTo(\App\Core\User\Models\User::class, 'scored_by');
    }

    // Activity log

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('InterviewScore')
            ->logOnly(['overall_score', 'recommendation', 'scored_by'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(function (string $event) {
                $actor = \Illuminate\Support\Facades\Auth::user()?->name ?? 'System';
                return "{$actor} {$event} score sheet for interview #{$this->interview_id}.";
            });
    }
}
