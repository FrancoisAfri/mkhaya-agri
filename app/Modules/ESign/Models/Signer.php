<?php

namespace App\Modules\ESign\Models;

use App\Modules\ESign\Enums\SignerRole;
use App\Modules\ESign\Enums\SignerStatus;
use Database\Factories\ESign\SignerFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Signer extends Model
{
    use HasFactory;

    protected $table = 'esign_signers';

    protected $fillable = [
        'envelope_id',
        'user_id',
        'name',
        'email',
        'phone',
        'role',
        'signing_order',
        // NOT: token, token_expires_at — set by ESignService::send
        //      status, consent_given, consent_given_at — lifecycle fields
        //      signature_data, signature_type, signed_at — signing fields
        //      ip_address, user_agent — audit fields
    ];

    protected $hidden = [
        'token',
    ];

    protected $casts = [
        'status'           => SignerStatus::class,
        'role'             => SignerRole::class,
        'token_expires_at' => 'datetime',
        'consent_given'    => 'boolean',
        'consent_given_at' => 'datetime',
        'signed_at'        => 'datetime',
        'declined_at'      => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (Signer $signer) {
            $signer->uuid   = (string) Str::uuid();
            $signer->status = SignerStatus::Pending->value;
        });
    }

    protected static function newFactory(): Factory
    {
        return SignerFactory::new();
    }

    public function envelope()
    {
        return $this->belongsTo(Envelope::class);
    }

    public function user()
    {
        return $this->belongsTo(\App\Core\User\Models\User::class);
    }

    public function otpChallenges()
    {
        return $this->hasMany(OtpChallenge::class);
    }

    public function isSigned(): bool
    {
        return $this->status === SignerStatus::Signed;
    }

    public function isDeclined(): bool
    {
        return $this->status === SignerStatus::Declined;
    }

    public function hasValidToken(string $token): bool
    {
        if (!$this->token || !$this->token_expires_at || $this->token_expires_at->isPast()) {
            return false;
        }

        return hash_equals($this->token, $token);
    }

    public function latestPendingOtp(): ?OtpChallenge
    {
        return $this->otpChallenges()
            ->whereNull('verified_at')
            ->where('expires_at', '>', now())
            ->latest()
            ->first();
    }

    /**
     * The signature as something a PDF can draw.
     *
     * Drawn signatures are stored on the shared disk, but a PDF renderer cannot fetch a URL — it
     * needs the bytes in the document — so they come back as a data URI here and nowhere else. That
     * is a read per signature while a certificate renders, which is a handful, rather than per row
     * in a listing.
     *
     * A typed signature is a name, not an image, and passes through unchanged.
     */
    public function signatureImage(): ?string
    {
        if (! blank($this->signature_path)) {
            $bytes = \App\Core\Storage\StoredImage::bytes($this->signature_path);

            if ($bytes !== null) {
                return 'data:image/png;base64,' . base64_encode($bytes);
            }
        }

        return $this->signature_data;
    }
}
