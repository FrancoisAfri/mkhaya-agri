<?php

namespace App\Modules\HR\Contracts\Models;

use App\Modules\HR\Recruitment\Models\Candidate;
use Database\Factories\Contracts\SignatureRequestFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class SignatureRequest extends Model
{
    use HasFactory;

    protected $table = 'recruitment_signature_requests';

    protected $fillable = [
        'contract_id',
        'signatory_type',
        'user_id',
        'candidate_id',
        // NOT: token_hash, token_expires_at — set by SendContractAction
        //      otp_hash, otp_expires_at, otp_attempts, otp_locked_at — set by OTP flow
        //      signed_at, signature_ip, signature_user_agent — set by SignDocumentAction
    ];

    protected $hidden = [
        'token_hash',
        'otp_hash',
    ];

    protected $casts = [
        'token_expires_at' => 'datetime',
        'otp_expires_at'   => 'datetime',
        'otp_locked_at'    => 'datetime',
        'signed_at'        => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (SignatureRequest $request) {
            $request->uuid = (string) Str::uuid();
        });
    }

    protected static function newFactory(): Factory
    {
        return SignatureRequestFactory::new();
    }

    // Relationships

    public function contract()
    {
        return $this->belongsTo(Contract::class);
    }

    public function user()
    {
        return $this->belongsTo(\App\Core\User\Models\User::class);
    }

    public function candidate()
    {
        return $this->belongsTo(Candidate::class);
    }

    // Helpers

    public function isSigned(): bool
    {
        return $this->signed_at !== null;
    }

    public function isOtpLocked(): bool
    {
        return $this->otp_locked_at !== null;
    }

    public function hasValidToken(string $rawToken): bool
    {
        if (!$this->token_hash || $this->token_expires_at->isPast()) {
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
}
