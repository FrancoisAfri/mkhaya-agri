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

class DisciplinaryCase extends Model
{
    use SoftDeletes;
    use SoftDeletes, LogsActivity;

    protected $table = 'disciplinary_cases';

    protected $fillable = [
        'uuid', 'case_number', 'employee_id', 'department_id',
        'category_id', 'status_id', 'outcome_id', 'sanction_id',
        'investigator_id', 'chairperson_id',
        'title', 'description', 'incident_date',
        'notice_issued_date', 'hearing_date', 'closed_at',
        'policy_reference', 'outcome_notes', 'internal_notes',
        'policy_engine_checked', 'policy_violations',
        'created_by', 'updated_by',
    ];

    protected $casts = [
        'incident_date'       => 'date',
        'notice_issued_date'  => 'date',
        'hearing_date'        => 'datetime',
        'closed_at'           => 'datetime',
        'policy_engine_checked' => 'boolean',
        'policy_violations'   => 'array',
    ];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(function ($m) {
            $m->uuid        ??= (string) Str::uuid();
            $m->case_number ??= 'DC-' . now()->format('Y') . '-' . str_pad(static::withTrashed()->count() + 1, 5, '0', STR_PAD_LEFT);
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll()->useLogName('disciplinary');
    }

    // ── Relations ─────────────────────────────────────────────────────────────

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employee_id');
    }

    public function investigator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'investigator_id');
    }

    public function chairperson(): BelongsTo
    {
        return $this->belongsTo(User::class, 'chairperson_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(DisciplinaryCategory::class, 'category_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(DisciplinaryStatus::class, 'status_id');
    }

    public function outcome(): BelongsTo
    {
        return $this->belongsTo(DisciplinaryOutcome::class, 'outcome_id');
    }

    public function sanction(): BelongsTo
    {
        return $this->belongsTo(DisciplinarySanction::class, 'sanction_id');
    }

    public function hearings(): HasMany
    {
        return $this->hasMany(DisciplinaryHearing::class, 'case_id');
    }

    public function investigations(): HasMany
    {
        return $this->hasMany(DisciplinaryInvestigation::class, 'case_id');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public function isClosed(): bool
    {
        return $this->status?->is_closed ?? false;
    }

    public function employeeName(): string
    {
        $p = $this->employee?->person;
        return $p ? trim("{$p->first_name} {$p->surname}") : ($this->employee?->email ?? 'Unknown');
    }
}
