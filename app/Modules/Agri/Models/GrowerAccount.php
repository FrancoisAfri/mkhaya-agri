<?php

namespace App\Modules\Agri\Models;

use App\Modules\Agri\Concerns\HasPublicUuid;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * A contracted grower's sign-in to the grower portal, on the `grower_portal` guard.
 *
 * Deliberately the framework's authenticatable and not the platform's User: no roles, no
 * permissions, no modules, no organisation switching. It cannot pass any staff route (those run on
 * the `web` guard), and everything it may see is decided by the one grower it belongs to.
 */
class GrowerAccount extends Authenticatable
{
    use BelongsToCompany, HasPublicUuid, Notifiable;

    public const INVITED = 'invited';
    public const ACTIVE = 'active';
    public const REVOKED = 'revoked';
    public const LOCALES = ['en', 'af'];

    /** Wrong codes or passwords before the account is held shut for a while. */
    public const MAX_ATTEMPTS = 5;

    protected $table = 'agri_grower_accounts';

    protected $guarded = ['id'];

    protected $hidden = ['password', 'remember_token', 'otp_hash'];

    protected $casts = [
        'password' => 'hashed', 'otp_expires_at' => 'datetime', 'mobile_verified_at' => 'datetime', 'email_verified_at' => 'datetime',
        'locked_until' => 'datetime', 'invited_at' => 'datetime', 'revoked_at' => 'datetime', 'last_login_at' => 'datetime',
        'otp_attempts' => 'integer', 'failed_attempts' => 'integer',
    ];

    public static function requiresCompany(): bool
    {
        return true;
    }

    public function grower(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Grower::class, 'grower_id')->withoutGlobalScopes();
    }

    public function canSignIn(): bool
    {
        return $this->status !== self::REVOKED && ! ($this->locked_until && $this->locked_until->isFuture());
    }

    /** Mail notifications go to the email on the account. */
    public function routeNotificationForMail(): ?string
    {
        return $this->email;
    }
}
