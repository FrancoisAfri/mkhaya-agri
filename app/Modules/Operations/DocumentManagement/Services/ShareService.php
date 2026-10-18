<?php

namespace App\Modules\Operations\DocumentManagement\Services;

use App\Modules\Operations\DocumentManagement\Models\Document;
use App\Modules\Operations\DocumentManagement\Models\DocumentEvent;
use App\Modules\Operations\DocumentManagement\Models\DocumentSetting;
use App\Modules\Operations\DocumentManagement\Models\DocumentShare;
use App\Modules\Operations\DocumentManagement\Models\DocumentShareAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Modules\Operations\DocumentManagement\Notifications\DocumentShareCodeNotification;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Issuing, resolving and revoking external share links.
 *
 * The token is generated once and returned once. Everything stored is its hash, so this service can
 * find a share from a link somebody presents but cannot produce the link for a share — which is the
 * property that makes a database copy useless on its own, and the reason a lost link is reissued
 * rather than looked up.
 */
class ShareService
{
    /**
     * 64 hex characters from 32 random bytes.
     *
     * Long enough that guessing is not a strategy, and hex rather than base64 so the token survives
     * being pasted into an email client, a chat window, or a URL bar without anything re-encoding a
     * plus sign into a space.
     */
    private const TOKEN_BYTES = 32;

    public function __construct(private readonly DocumentService $documents) {}

    /**
     * Creates a link and returns it alongside the share.
     *
     * The plain token is in the return value and nowhere else. Callers that do not use it
     * immediately have lost it.
     *
     * @param array{expires_days?: int|null, password?: ?string, allow_download?: bool, max_downloads?: ?int, recipient_email?: ?string, note?: ?string} $options
     * @return array{share: DocumentShare, token: string}
     */
    public function create(Document $document, array $options = []): array
    {
        $settings = DocumentSetting::current();
        $maxDays = max(1, (int) ($settings->share_max_days ?? 30));

        /*
         * The requested lifetime, clamped to what the organisation allows. Clamped rather than
         * rejected: somebody asking for a year when the ceiling is thirty days gets thirty days and
         * a visible expiry date, which is more useful than a validation error they will work around
         * by making a new link every month.
         */
        $days = (int) ($options['expires_days'] ?? $maxDays);
        $days = max(1, min($days, $maxDays));

        $token = bin2hex(random_bytes(self::TOKEN_BYTES));

        $share = DocumentShare::create([
            'uuid' => (string) Str::uuid(),
            'document_id' => $document->id,
            'token_hash' => $this->hashToken($token),
            'recipient_email' => $options['recipient_email'] ?? null,
            'note' => $options['note'] ?? null,
            'password_hash' => filled($options['password'] ?? null) ? Hash::make($options['password']) : null,
            'allow_download' => (bool) ($options['allow_download'] ?? false),
            // Only where there is an address to verify against. Setting it on a link made without a
            // recipient would produce one nobody could ever open.
            'require_email_code' => (bool) ($options['require_email_code'] ?? false)
                && filled($options['recipient_email'] ?? null),
            'watermark' => (bool) ($options['watermark'] ?? $settings->watermark_shares_by_default ?? false),
            'max_downloads' => $options['max_downloads'] ?? null,
            'expires_at' => Carbon::now()->addDays($days),
            'created_by' => Auth::id(),
        ]);

        $this->documents->record($document, DocumentEvent::SHARED, null, [
            'share' => $share->uuid,
            'recipient' => $share->recipient_email,
            'expires_at' => $share->expires_at->toDateString(),
            'allow_download' => $share->allow_download,
            'watermark' => $share->watermark,
            'password_protected' => $share->needsPassword(),
            'email_code' => $share->needsEmailCode(),
        ]);

        return ['share' => $share, 'token' => $token];
    }

    /**
     * Finds the share a token belongs to, or null.
     *
     * Returns null for a token that matches nothing and for one whose share has expired, been
     * revoked or been used up. The caller cannot tell those apart, and neither can whoever is
     * holding the link — distinguishing them tells somebody working through guesses which of them
     * was once a real share.
     *
     * Runs without the tenant scope on purpose: a public request has no company yet, and the token
     * is what determines which one it belongs to. The caller pins the tenant immediately afterwards.
     */
    public function resolve(string $token): ?DocumentShare
    {
        if ($token === '' || ! ctype_xdigit($token)) {
            return null;
        }

        $share = DocumentShare::withoutGlobalScopes()
            ->where('token_hash', $this->hashToken($token))
            ->first();

        return $share?->isUsable() ? $share : null;
    }

    /** Whether the password presented with a link matches the one it was locked with. */
    public function passwordMatches(DocumentShare $share, ?string $password): bool
    {
        if (! $share->needsPassword()) {
            return true;
        }

        return $password !== null && Hash::check($password, $share->password_hash);
    }

    /**
     * Sends a one-time code to the address the link was made out to.
     *
     * The code is hashed, never stored plainly: a code sitting in a table is a password with a
     * shorter life, and the table is the one an attacker would already be reading if they could
     * read it at all.
     *
     * @return array{sent: bool, error: ?string}
     */
    public function sendCode(DocumentShare $share): array
    {
        if (! $share->needsEmailCode()) {
            return ['sent' => false, 'error' => null];
        }

        /*
         * Not resent on demand. Without this, the send button is an open relay: anybody holding the
         * link can post to it repeatedly and fill the recipient's inbox, and the recipient cannot
         * tell which of the twenty codes is live.
         */
        $wait = 60;

        if ($share->code_sent_at !== null && $share->code_sent_at->diffInSeconds(Carbon::now()) < $wait) {
            return ['sent' => false, 'error' => __('document_management.sharing.code_too_soon')];
        }

        // Six digits, padded, so a code beginning with a zero is still six characters long — one
        // that silently became five would be rejected and nobody would know why.
        $code = str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT);

        $share->forceFill([
            'code_hash' => Hash::make($code),
            'code_expires_at' => Carbon::now()->addMinutes(15),
            'code_sent_at' => Carbon::now(),
            // A fresh code starts the count again: somebody who mistyped twice and asked for a new
            // one has not used up their attempts on a code that no longer exists.
            'code_attempts' => 0,
        ])->save();

        Notification::route('mail', $share->recipient_email)
            ->notify(new DocumentShareCodeNotification($share, $code));

        return ['sent' => true, 'error' => null];
    }

    /**
     * Checks a code, and spends an attempt whether or not it was right.
     *
     * @return array{ok: bool, error: ?string}
     */
    public function verifyCode(DocumentShare $share, ?string $code): array
    {
        if (! $share->needsEmailCode()) {
            return ['ok' => true, 'error' => null];
        }

        if ($share->code_hash === null || $share->code_expires_at === null || $share->code_expires_at->isPast()) {
            return ['ok' => false, 'error' => __('document_management.sharing.code_expired')];
        }

        /*
         * Five tries. Six digits is a million possibilities, which is plenty against a person
         * typing and nothing at all against a script — and the link is public, so a script is the
         * case to design for.
         */
        if ((int) $share->code_attempts >= 5) {
            return ['ok' => false, 'error' => __('document_management.sharing.code_exhausted')];
        }

        $share->increment('code_attempts');

        if (! $code || ! Hash::check($code, $share->code_hash)) {
            return ['ok' => false, 'error' => __('document_management.sharing.code_wrong')];
        }

        // Spent on use. A code that stayed live for its full fifteen minutes after being used is a
        // second key to the same door, sitting in an inbox.
        $share->forceFill([
            'code_hash' => null,
            'code_expires_at' => null,
            'code_attempts' => 0,
            'verified_at' => Carbon::now(),
        ])->save();

        return ['ok' => true, 'error' => null];
    }

    /**
     * Records that somebody opened the link, and counts it.
     *
     * Only downloads count against max_downloads. A view-limited link that decremented on every
     * page render would be spent by a browser's own prefetch before the recipient read anything.
     */
    public function recordAccess(DocumentShare $share, string $action, ?Request $request = null): void
    {
        DB::transaction(function () use ($share, $action, $request) {
            DocumentShareAccess::create([
                'company_id' => $share->company_id,
                'share_id' => $share->id,
                'action' => $action,
                'ip' => $request?->ip(),
                'user_agent' => Str::limit((string) $request?->userAgent(), 250, ''),
                'created_at' => Carbon::now(),
            ]);

            $share->forceFill([
                'last_accessed_at' => Carbon::now(),
                'view_count' => $share->view_count + ($action === DocumentShareAccess::VIEWED ? 1 : 0),
                'download_count' => $share->download_count + ($action === DocumentShareAccess::DOWNLOADED ? 1 : 0),
            ])->save();
        });
    }

    /** Stops a link working, keeping the record that it existed. */
    public function revoke(DocumentShare $share): DocumentShare
    {
        if ($share->isRevoked()) {
            return $share;
        }

        $share->forceFill([
            'revoked_at' => Carbon::now(),
            'revoked_by' => Auth::id(),
        ])->save();

        $document = $share->document()->withoutGlobalScopes()->first();

        if ($document) {
            $this->documents->record($document, DocumentEvent::SHARE_REVOKED, null, [
                'share' => $share->uuid,
                'recipient' => $share->recipient_email,
                'views' => $share->view_count,
                'downloads' => $share->download_count,
            ]);
        }

        return $share;
    }

    /**
     * Revokes every live link for a document at once.
     *
     * Used when a document is deleted or put under legal hold — the links outlive the document's
     * status otherwise, and a share issued last month keeps serving a document that has since been
     * withdrawn.
     *
     * @return int the number revoked
     */
    public function revokeAllFor(Document $document): int
    {
        $shares = DocumentShare::where('document_id', $document->id)->active()->get();

        foreach ($shares as $share) {
            $this->revoke($share);
        }

        return $shares->count();
    }

    private function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }
}
