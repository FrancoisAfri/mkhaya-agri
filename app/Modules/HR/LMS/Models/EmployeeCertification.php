<?php

namespace App\Modules\HR\LMS\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmployeeCertification extends Model
{
    protected $table = 'employee_certifications';

    protected $fillable = [
        'user_id', 'certification_id', 'certificate_id',
        'status', 'issued_at', 'expires_at',
        'reminders_sent', 'last_reminder_at',
        'refresher_assigned_at', 'refresher_enrollment_id',
        'notes',
    ];

    protected $casts = [
        'issued_at'            => 'datetime',
        'expires_at'           => 'datetime',
        'last_reminder_at'     => 'datetime',
        'refresher_assigned_at'=> 'datetime',
        'reminders_sent'       => 'integer',
    ];

    const STATUSES = [
        'pending'             => 'Pending',
        'active'              => 'Active',
        'expired'             => 'Expired',
        'revoked'             => 'Revoked',
        'refresher_required'  => 'Refresher Required',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function certification(): BelongsTo
    {
        return $this->belongsTo(LmsCertification::class, 'certification_id');
    }

    public function certificate(): BelongsTo
    {
        return $this->belongsTo(LmsCertificate::class, 'certificate_id');
    }

    public function refresherEnrollment(): BelongsTo
    {
        return $this->belongsTo(LmsEnrollment::class, 'refresher_enrollment_id');
    }

    public function expiryLogs(): HasMany
    {
        return $this->hasMany(CertificationExpiryLog::class, 'employee_certification_id');
    }

    public function daysUntilExpiry(): ?int
    {
        if (!$this->expires_at) return null;
        return (int) now()->diffInDays($this->expires_at, absolute: false);
    }

    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    public function statusColor(): string
    {
        return match ($this->status) {
            'active'             => 'success',
            'expired'            => 'error',
            'refresher_required' => 'warning',
            'revoked'            => 'deep-orange',
            default              => 'grey',
        };
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeExpired($query)
    {
        return $query->where('status', 'expired');
    }

    public function scopeExpiringSoon($query, int $days = 30)
    {
        return $query->where('status', 'active')
                     ->whereNotNull('expires_at')
                     ->where('expires_at', '<=', now()->addDays($days))
                     ->where('expires_at', '>', now());
    }
}
