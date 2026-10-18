<?php

namespace App\Modules\HR\LMS\Models;

use App\Core\User\Models\User;
use App\Modules\HR\Hr\Models\HRPerson;
use App\Modules\HR\LMS\Models\Qcto\LmsPoePortfolio;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class LmsCertificate extends Model
{
    use SoftDeletes;

    protected $table = 'lms_certificates';

    protected $fillable = [
        'uuid', 'template_id', 'certification_id',
        'enrollment_id', 'hr_person_id', 'poe_portfolio_id',
        'certificate_number',
        'nqf_level', 'saqa_qual_id',
        'issued_at', 'expires_at', 'issued_by',
    ];

    protected $casts = [
        'issued_at'  => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->uuid)) {
                $model->uuid = Str::uuid()->toString();
            }
        });
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(LmsEnrollment::class, 'enrollment_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(LmsCertificateTemplate::class, 'template_id');
    }

    public function certification(): BelongsTo
    {
        return $this->belongsTo(LmsCertification::class, 'certification_id');
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function hrPerson(): BelongsTo
    {
        return $this->belongsTo(HRPerson::class, 'hr_person_id');
    }

    public function poePortfolio(): BelongsTo
    {
        return $this->belongsTo(LmsPoePortfolio::class, 'poe_portfolio_id');
    }

    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    public function daysUntilExpiry(): ?int
    {
        if (!$this->expires_at) return null;
        return (int) now()->diffInDays($this->expires_at, absolute: false);
    }
}
