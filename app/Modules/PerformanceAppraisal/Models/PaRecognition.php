<?php

namespace App\Modules\PerformanceAppraisal\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class PaRecognition extends Model
{
    use LogsActivity;

    protected $table = 'pa_recognitions';

    protected $fillable = [
        'uuid', 'giver_id', 'receiver_id', 'badge', 'value_tag',
        'message', 'is_public', 'mentioned_user_ids',
    ];

    protected $casts = [
        'is_public'          => 'boolean',
        'mentioned_user_ids' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->uuid ??= (string) Str::uuid());
    }

    public function giver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'giver_id');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'receiver_id');
    }

    public function reactions(): HasMany
    {
        return $this->hasMany(PaRecognitionReaction::class, 'recognition_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(PaRecognitionComment::class, 'recognition_id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('performance_appraisal')
            ->logOnly(['giver_id', 'receiver_id', 'badge', 'message'])
            ->dontSubmitEmptyLogs();
    }
}
