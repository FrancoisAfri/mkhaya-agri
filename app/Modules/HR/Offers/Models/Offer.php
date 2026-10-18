<?php

namespace App\Modules\HR\Offers\Models;

use App\Modules\HR\Recruitment\Enums\OfferStatus;
use App\Modules\HR\Recruitment\Models\Application;
use App\Modules\HR\Recruitment\Models\Candidate;
use App\Modules\HR\Recruitment\Models\Job;
use Database\Factories\Offers\OfferFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Offer extends Model
{
    use HasFactory, LogsActivity;

    protected $table = 'recruitment_offers';

    protected $fillable = [
        'application_id',
        'job_id',
        'candidate_id',
        'created_by',
        'position_title',
        'department',
        'start_date',
        'salary_amount',
        'salary_currency',
        'contract_type',
        'notes',
        // NOT: status, approved_by, approved_at — set by Action classes
        //      token_hash, token_expires_at — set by SendOfferAction (server-side only)
        //      otp_hash, otp_expires_at, otp_attempts, otp_locked_at — set by OTP flow
        //      sent_at, accepted_at, declined_at, withdrawn_at, expires_at — lifecycle
    ];

    protected $hidden = [
        'token_hash',
        'otp_hash',
    ];

    protected $casts = [
        'status'          => OfferStatus::class,
        'start_date'      => 'date',
        'salary_amount'   => 'decimal:2',
        'approved_at'     => 'datetime',
        'sent_at'         => 'datetime',
        'expires_at'      => 'datetime',
        'accepted_at'     => 'datetime',
        'declined_at'     => 'datetime',
        'withdrawn_at'    => 'datetime',
        'otp_expires_at'  => 'datetime',
        'otp_locked_at'   => 'datetime',
        'token_expires_at'=> 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (Offer $offer) {
            $offer->uuid = (string) Str::uuid();
        });
    }

    protected static function newFactory(): Factory
    {
        return OfferFactory::new();
    }

    // Relationships

    public function application()
    {
        return $this->belongsTo(Application::class);
    }

    public function job()
    {
        return $this->belongsTo(Job::class);
    }

    public function candidate()
    {
        return $this->belongsTo(Candidate::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(\App\Core\User\Models\User::class, 'created_by');
    }

    public function approvedBy()
    {
        return $this->belongsTo(\App\Core\User\Models\User::class, 'approved_by');
    }

    public function contract()
    {
        return $this->hasOne(\App\Modules\HR\Contracts\Models\Contract::class);
    }

    // Helpers

    public function isOtpLocked(): bool
    {
        return $this->otp_locked_at !== null;
    }

    public function hasValidToken(string $rawToken): bool
    {
        if (!$this->token_hash || !$this->token_expires_at) {
            return false;
        }

        if ($this->token_expires_at->isPast()) {
            return false;
        }

        return hash_equals($this->token_hash, hash('sha256', $rawToken));
    }

    public function hasValidOtp(string $rawOtp): bool
    {
        if (!$this->otp_hash || !$this->otp_expires_at || $this->otp_expires_at->isPast()) {
            return false;
        }

        return hash_equals($this->otp_hash, hash('sha256', $rawOtp));
    }

    // Activity log

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('Offer')
            ->logOnly(['status', 'approved_by', 'approved_at', 'sent_at', 'accepted_at', 'declined_at'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(function (string $event) {
                $actor  = \Illuminate\Support\Facades\Auth::user()?->name ?? 'System';
                $status = $this->status?->label() ?? 'unknown';
                return match($event) {
                    'created' => "{$actor} created offer for candidate #{$this->candidate_id}.",
                    'updated' => "{$actor} updated offer #{$this->id} — status: {$status}.",
                    'deleted' => "{$actor} deleted offer #{$this->id}.",
                    default   => "{$actor} triggered \"{$event}\" on offer #{$this->id}.",
                };
            });
    }
}
