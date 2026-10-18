<?php

namespace App\Modules\Disciplinary\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class DisciplinaryIncident extends Model
{
    use SoftDeletes, LogsActivity;

    protected $table = 'disciplinary_incidents';

    protected $fillable = [
        'uuid', 'incident_number',
        'reporter_id', 'is_anonymous', 'reporter_name', 'reporter_email',
        'category_id', 'title', 'description', 'severity_level',
        'incident_date', 'location', 'location_details',
        'status', 'investigator_id', 'investigator_assigned_at', 'auto_assigned',
        'case_id', 'resolution_notes', 'resolved_at',
        'created_by', 'updated_by',
    ];

    protected $casts = [
        'incident_date'            => 'datetime',
        'investigator_assigned_at' => 'datetime',
        'resolved_at'              => 'datetime',
        'is_anonymous'             => 'boolean',
        'auto_assigned'            => 'boolean',
    ];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(function ($m) {
            $m->uuid            ??= (string) Str::uuid();
            $m->incident_number ??= 'INC-' . now()->format('Y') . '-' . str_pad(
                static::withTrashed()->count() + 1, 5, '0', STR_PAD_LEFT
            );
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll()->useLogName('disciplinary');
    }

    // ── Relations ─────────────────────────────────────────────────────────────

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(DisciplinaryCategory::class, 'category_id');
    }

    public function investigator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'investigator_id');
    }

    public function formalCase(): BelongsTo
    {
        return $this->belongsTo(DisciplinaryCase::class, 'case_id');
    }

    public function witnesses(): HasMany
    {
        return $this->hasMany(DisciplinaryWitness::class, 'incident_id')->latest();
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(DisciplinaryEvidence::class, 'incident_id')->latest();
    }

    public function accused(): HasMany
    {
        return $this->hasMany(DisciplinaryAccused::class, 'incident_id');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public function reporterName(): string
    {
        if ($this->is_anonymous) {
            return $this->reporter_name ?? 'Anonymous';
        }
        $p = $this->reporter?->person;
        return $p ? trim("{$p->first_name} {$p->surname}") : ($this->reporter?->email ?? 'Unknown');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, ['open', 'under_investigation']);
    }

    public static function statusColor(string $status): string
    {
        return match($status) {
            'open'                => 'warning',
            'under_investigation' => 'primary',
            'escalated'           => 'deep-purple',
            'closed'              => 'success',
            'dismissed'           => 'grey',
            default               => 'grey',
        };
    }
}
