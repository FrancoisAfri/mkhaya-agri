<?php

namespace App\Modules\HR\LMS\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CertificationExpiryLog extends Model
{
    protected $table = 'certification_expiry_logs';

    protected $fillable = [
        'employee_certification_id', 'user_id', 'certification_id',
        'event_type', 'days_until_expiry', 'action_key',
        'notes', 'created_by',
    ];

    protected $casts = [
        'days_until_expiry' => 'integer',
    ];

    const EVENT_TYPES = [
        'issued'             => 'Certificate Issued',
        'expired'            => 'Certificate Expired',
        'reminder_sent'      => 'Expiry Reminder Sent',
        'refresher_assigned' => 'Refresher Training Assigned',
        'action_blocked'     => 'Action Blocked',
        'revoked'            => 'Certificate Revoked',
        'renewed'            => 'Certificate Renewed',
    ];

    public function employeeCertification(): BelongsTo
    {
        return $this->belongsTo(EmployeeCertification::class, 'employee_certification_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function certification(): BelongsTo
    {
        return $this->belongsTo(LmsCertification::class, 'certification_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function eventColor(): string
    {
        return match ($this->event_type) {
            'issued'             => 'success',
            'expired'            => 'error',
            'reminder_sent'      => 'info',
            'refresher_assigned' => 'warning',
            'action_blocked'     => 'deep-orange',
            'revoked'            => 'error',
            'renewed'            => 'success',
            default              => 'grey',
        };
    }
}
