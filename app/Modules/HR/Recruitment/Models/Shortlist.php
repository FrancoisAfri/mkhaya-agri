<?php

namespace App\Modules\HR\Recruitment\Models;

use App\Modules\HR\Recruitment\Enums\ShortlistStatus;
use Database\Factories\Recruitment\ShortlistFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Shortlist extends Model
{
    use HasFactory, LogsActivity;

    protected $table = 'recruitment_shortlists';

    protected static function newFactory(): Factory
    {
        return ShortlistFactory::new();
    }

    protected $fillable = [
        'job_id',
        'generated_by',
        'notes',
        // NOT: status — set by ApproveShortlistAction / RejectShortlistAction
        //      approved_by, approved_at — server-set, never from HTTP input
    ];

    protected $casts = [
        'status'      => ShortlistStatus::class,
        'approved_at' => 'datetime',
    ];

    // Relationships

    public function job()
    {
        return $this->belongsTo(Job::class);
    }

    public function generatedBy()
    {
        return $this->belongsTo(\App\Core\User\Models\User::class, 'generated_by');
    }

    public function approvedBy()
    {
        return $this->belongsTo(\App\Core\User\Models\User::class, 'approved_by');
    }

    public function items()
    {
        return $this->hasMany(ShortlistItem::class)->orderBy('rank');
    }

    public function overrides()
    {
        return $this->hasMany(ShortlistOverride::class);
    }

    // Activity log

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('Shortlist')
            ->logOnly(['status', 'approved_by', 'approved_at'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn(string $event) => $this->describeEvent($event));
    }

    private function describeEvent(string $event): string
    {
        $actor  = Auth::user()?->name ?? 'System';
        $status = $this->status?->label() ?? 'unknown';
        return match($event) {
            'created' => "{$actor} generated shortlist for job #{$this->job_id}.",
            'updated' => "{$actor} updated shortlist #{$this->id} — status: {$status}.",
            'deleted' => "{$actor} deleted shortlist #{$this->id}.",
            default   => "{$actor} triggered \"{$event}\" on shortlist #{$this->id}.",
        };
    }
}
