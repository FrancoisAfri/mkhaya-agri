<?php

namespace App\Modules\Operations\DocumentManagement\Models;

use App\Core\User\Models\User;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One link handed to somebody outside the organisation.
 *
 * The token is never stored here in the form the recipient holds. What is stored is its SHA-256,
 * which is enough to find the share when the link is opened and not enough to reconstruct the link
 * from the database.
 */
class DocumentShare extends Model
{
    use BelongsToCompany;

    protected $table = 'document_shares';

    protected $guarded = ['id'];

    protected $hidden = ['token_hash', 'password_hash'];

    protected $casts = [
        'allow_download' => 'boolean',
        'require_email_code' => 'boolean',
        'code_expires_at' => 'datetime',
        'code_sent_at' => 'datetime',
        'verified_at' => 'datetime',
        'watermark' => 'boolean',
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
        'last_accessed_at' => 'datetime',
        'max_downloads' => 'integer',
        'download_count' => 'integer',
        'view_count' => 'integer',
    ];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'document_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function accesses(): HasMany
    {
        return $this->hasMany(DocumentShareAccess::class, 'share_id')->latest('created_at');
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function hasExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isExhausted(): bool
    {
        return $this->max_downloads !== null && $this->download_count >= $this->max_downloads;
    }

    public function needsPassword(): bool
    {
        return $this->password_hash !== null;
    }

    /**
     * Whether the person opening this has to prove they hold the mailbox it was sent to.
     *
     * Only meaningful with an address to send to. A share made without a recipient cannot be
     * verified against one, and pretending otherwise would produce a link nobody could ever open.
     */
    public function needsEmailCode(): bool
    {
        return (bool) $this->require_email_code && filled($this->recipient_email);
    }

    /** The address, shown back in a form that confirms it without disclosing it. */
    public function maskedRecipient(): ?string
    {
        if (blank($this->recipient_email)) {
            return null;
        }

        [$name, $domain] = array_pad(explode('@', (string) $this->recipient_email, 2), 2, '');

        /*
         * Enough for the right person to recognise their own address and not enough for the wrong
         * person to learn it. Showing it in full would hand the address to whoever has the link,
         * which is the thing this feature exists to stop mattering.
         */
        $head = mb_substr($name, 0, 1);
        $tail = mb_strlen($name) > 2 ? mb_substr($name, -1) : '';

        return $head . str_repeat('*', max(1, mb_strlen($name) - 2)) . $tail . '@' . $domain;
    }

    /**
     * Whether this link still opens anything.
     *
     * Deliberately one question with one answer. The caller is never told which of the three
     * reasons applies, because the public endpoint must not distinguish between a link that has
     * expired, one that was revoked, and one that never existed — telling them apart confirms to
     * somebody guessing tokens that a particular guess was once real.
     */
    public function isUsable(): bool
    {
        return ! $this->isRevoked() && ! $this->hasExpired() && ! $this->isExhausted();
    }

    /** Why it stopped working, for the people inside who are allowed to know. */
    public function state(): string
    {
        return match (true) {
            $this->isRevoked() => 'revoked',
            $this->hasExpired() => 'expired',
            $this->isExhausted() => 'exhausted',
            default => 'active',
        };
    }

    /** @param \Illuminate\Database\Eloquent\Builder<self> $query */
    public function scopeActive($query)
    {
        return $query->whereNull('revoked_at')->where('expires_at', '>', Carbon::now());
    }
}
