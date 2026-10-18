<?php

namespace App\Modules\ESign\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;

class OtpChallenge extends Model
{
    protected $table = 'esign_otp_challenges';

    protected $fillable = [
        'signer_id',
        'code_hash',
        'channel',
        'sent_to',
        'expires_at',
    ];

    protected $hidden = [
        'code_hash',
    ];

    protected $casts = [
        'expires_at'  => 'datetime',
        'verified_at' => 'datetime',
    ];

    public function signer()
    {
        return $this->belongsTo(Signer::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }

    public function verifyCode(string $rawCode): bool
    {
        if ($this->isExpired() || $this->isVerified() || $this->attempts >= 5) {
            return false;
        }

        return Hash::check($rawCode, $this->code_hash);
    }
}
