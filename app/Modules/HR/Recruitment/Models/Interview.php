<?php

namespace App\Modules\HR\Recruitment\Models;

use App\Modules\HR\Recruitment\Enums\InterviewType;
use Database\Factories\Recruitment\InterviewFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Interview extends Model
{
    use HasFactory, LogsActivity;

    protected $table = 'recruitment_interviews';

    protected static function newFactory(): Factory
    {
        return InterviewFactory::new();
    }

    protected $fillable = [
        'application_id',
        'interviewer_id',
        'type',
        'scheduled_at',
        'duration_minutes',
        'location',
        'meeting_url',
        'notes',
        // NOT: status — set by action classes (CompleteInterviewAction, CancelInterviewAction)
    ];

    protected $casts = [
        'type'         => InterviewType::class,
        'scheduled_at' => 'datetime',
    ];

    // Relationships

    public function application()
    {
        return $this->belongsTo(Application::class);
    }

    public function interviewer()
    {
        return $this->belongsTo(\App\Core\User\Models\User::class, 'interviewer_id');
    }

    public function questions()
    {
        return $this->hasMany(InterviewQuestion::class)->orderBy('order_index');
    }

    public function score()
    {
        return $this->hasOne(InterviewScore::class);
    }

    // Activity log

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('Interview')
            ->logOnly(['status', 'scheduled_at', 'interviewer_id', 'type'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn(string $event) => $this->describeEvent($event));
    }

    private function describeEvent(string $event): string
    {
        $actor = Auth::user()?->name ?? 'System';
        $type  = $this->type?->label() ?? 'interview';
        return match($event) {
            'created' => "{$actor} scheduled a {$type} interview for application #{$this->application_id}.",
            'updated' => "{$actor} updated interview #{$this->id} — status: {$this->status}.",
            'deleted' => "{$actor} cancelled interview #{$this->id}.",
            default   => "{$actor} triggered \"{$event}\" on interview #{$this->id}.",
        };
    }
}
