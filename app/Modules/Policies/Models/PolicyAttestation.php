<?php

namespace App\Modules\Policies\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class PolicyAttestation extends Model
{
    protected $table = 'policy_attestations';

    protected $fillable = [
        'uuid',
        'policy_id',
        'user_id',
        'policy_version_id',
        'campaign_id',
        'viewed_at',
        'acknowledged_at',
        'signed_at',
        'deadline',
        'quiz_score',
        'status',
        'signature_token',
        'notes',
    ];

    // ── Route model binding via UUID ───────────────────────────────────────────

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected static function boot(): void
    {
        parent::boot();
        static::creating(function (self $model): void {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    protected $casts = [
        'viewed_at'       => 'datetime',
        'acknowledged_at' => 'datetime',
        'signed_at'       => 'datetime',
        'deadline'        => 'datetime',
        'quiz_score'      => 'integer',
    ];

    // ── Relationships ──────────────────────────────────────────────────────────

    public function policy(): BelongsTo
    {
        return $this->belongsTo(Policy::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(PolicyVersion::class, 'policy_version_id');
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Policies\Models\AttestationCampaign::class);
    }

    public function reminders(): HasMany
    {
        return $this->hasMany(PolicyAttestationReminder::class, 'attestation_id');
    }

    // ── Scopes ─────────────────────────────────────────────────────────────────

    public function scopePending(Builder $q): Builder
    {
        return $q->where('status', 'pending');
    }

    public function scopeOverdue(Builder $q): Builder
    {
        return $q->where('status', 'overdue')
                 ->orWhere(fn($q) => $q->where('status', 'pending')
                                       ->whereNotNull('deadline')
                                       ->where('deadline', '<', now()));
    }

    public function scopeCompleted(Builder $q): Builder
    {
        return $q->where('status', 'completed');
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    public function isOverdue(): bool
    {
        return $this->status === 'overdue' ||
               ($this->status === 'pending' && $this->deadline && $this->deadline->isPast());
    }

    public function generateSignatureToken(): string
    {
        $token = Str::random(64);
        $this->update(['signature_token' => $token]);
        return $token;
    }
}
