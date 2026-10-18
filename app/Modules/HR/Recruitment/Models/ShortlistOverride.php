<?php

namespace App\Modules\HR\Recruitment\Models;

use Database\Factories\Recruitment\ShortlistOverrideFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class ShortlistOverride extends Model
{
    use HasFactory, LogsActivity;

    protected $table = 'candidate_shortlist_overrides';

    protected static function newFactory(): Factory
    {
        return ShortlistOverrideFactory::new();
    }

    protected $fillable = [
        'shortlist_id',
        'candidate_id',
        'action',
        'override_reason',
        'overridden_by',
        // NOT: approver_id, approved_at — set by ApproveOverrideAction (four-eyes)
    ];

    protected $casts = [
        'approved_at' => 'datetime',
    ];

    // Relationships

    public function shortlist()
    {
        return $this->belongsTo(Shortlist::class);
    }

    public function candidate()
    {
        return $this->belongsTo(Candidate::class);
    }

    public function overriddenBy()
    {
        return $this->belongsTo(\App\Core\User\Models\User::class, 'overridden_by');
    }

    public function approver()
    {
        return $this->belongsTo(\App\Core\User\Models\User::class, 'approver_id');
    }

    // Activity log

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('ShortlistOverride')
            ->logOnly(['action', 'override_reason', 'approver_id', 'approved_at'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(function (string $event) {
                $actor = \Illuminate\Support\Facades\Auth::user()?->name ?? 'System';
                return "{$actor} triggered shortlist override \"{$event}\" on shortlist #{$this->shortlist_id}.";
            });
    }
}
