<?php

namespace App\Modules\HR\LMS\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Shared\Traits\KeepsDatabaseDefaults;

class LmsCertification extends Model
{
    use SoftDeletes, KeepsDatabaseDefaults;

    protected $table = 'lms_certifications';

    protected $fillable = [
        'name', 'description', 'course_id', 'template_id',
        'valid_days', 'refresher_before_days', 'refresher_course_id',
        'reminder_days', 'actions_blocked_when_expired',
        'requires_understanding_score', 'min_understanding_score',
        'auto_issue', 'is_active', 'created_by',
    ];

    protected $casts = [
        'valid_days'                    => 'integer',
        'refresher_before_days'         => 'integer',
        'reminder_days'                 => 'array',
        'actions_blocked_when_expired'  => 'array',
        'requires_understanding_score'  => 'boolean',
        'min_understanding_score'       => 'integer',
        'auto_issue'                    => 'boolean',
        'is_active'                     => 'boolean',
    ];

    public function course(): BelongsTo
    {
        return $this->belongsTo(LmsCourse::class, 'course_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(LmsCertificateTemplate::class, 'template_id');
    }

    public function refresherCourse(): BelongsTo
    {
        return $this->belongsTo(LmsCourse::class, 'refresher_course_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function employeeCertifications(): HasMany
    {
        return $this->hasMany(EmployeeCertification::class, 'certification_id');
    }

    public function expiryLogs(): HasMany
    {
        return $this->hasMany(CertificationExpiryLog::class, 'certification_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /** True if this certification ever expires. */
    public function expires(): bool
    {
        return !is_null($this->valid_days);
    }

    /** Calculate expiry date from a given issue date. */
    public function expiryDate(\Carbon\Carbon $issuedAt): ?\Carbon\Carbon
    {
        return $this->valid_days ? $issuedAt->copy()->addDays($this->valid_days) : null;
    }

    /** True if the given action key is blocked when the cert is expired. */
    public function blocksAction(string $actionKey): bool
    {
        return in_array($actionKey, $this->actions_blocked_when_expired ?? []);
    }
}
