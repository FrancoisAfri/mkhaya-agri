<?php

namespace App\Modules\CRM\Services;

use App\Modules\CRM\Models\CrmIntegration;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Support\Facades\Mail;

/**
 * Resolves the Laravel mailer the CRM should send through. When a company has
 * activated an email integration on the Integrations hub, we build a dynamic
 * SMTP mailer from those (decrypted) credentials so campaigns and CRM mail go
 * out from their own provider. Falls back to the app's default mailer.
 */
class CrmMailService
{
    /** Is a custom CRM email provider configured & active? */
    public function hasProvider(): bool
    {
        return CrmIntegration::active('email') !== null;
    }

    /** Sender address/name from the active provider, or null to use defaults. */
    public function from(): ?array
    {
        $config = CrmIntegration::active('email')?->configArray() ?? [];
        if (empty($config['from_email'])) return null;
        return ['address' => $config['from_email'], 'name' => $config['from_name'] ?? config('mail.from.name')];
    }

    /** The Mailer instance to send CRM mail through. */
    public function mailer(): Mailer
    {
        $integration = CrmIntegration::active('email');
        if (! $integration) {
            return Mail::mailer(); // app default
        }

        $c = $integration->configArray();
        $encryption = $c['encryption'] ?? 'tls';

        config([
            'mail.mailers.crm_dynamic' => [
                'transport'  => 'smtp',
                'host'       => $c['host'] ?? '127.0.0.1',
                'port'       => (int) ($c['port'] ?? 587),
                'encryption' => $encryption === 'none' ? null : $encryption,
                'username'   => $c['username'] ?? null,
                'password'   => $c['password'] ?? null,
                'timeout'    => 30,
            ],
        ]);

        // Rebuild so the freshly-injected config is picked up.
        Mail::purge('crm_dynamic');

        return Mail::mailer('crm_dynamic');
    }

    /**
     * The mailer bulk marketing goes through.
     *
     * Marketing and transactional mail should not share a reputation. If a
     * campaign gets people hitting "spam", that must not be able to stop
     * password resets and leave notifications from arriving. When a dedicated
     * marketing mailer is configured (`MAIL_MAILER_MARKETING`, typically a
     * separate subdomain such as mail.example.com) campaigns use it; otherwise
     * this falls back to the normal CRM mailer so nothing breaks.
     */
    public function marketingMailer(): Mailer
    {
        $name = config('mail.marketing_mailer') ?: env('MAIL_MAILER_MARKETING');

        if ($name && config("mail.mailers.{$name}")) {
            return Mail::mailer($name);
        }

        return $this->mailer();
    }

    /**
     * Send bulk marketing HTML with the headers mailbox providers expect.
     *
     * Campaigns and sequences both go through here so they cannot drift apart —
     * the one-click unsubscribe headers matter for every bulk message, not just
     * campaigns. Gmail and Yahoo require them from bulk senders, and clients turn
     * them into a native Unsubscribe button, which recipients press instead of
     * "report spam". That swap is what protects the sending domain.
     *
     * @param  string|array  $to
     */
    public function sendMarketingHtml(
        $to,
        string $subject,
        string $html,
        string $unsubscribeUrl,
        ?string $listId = null,
        ?array $sender = null,
    ): void {
        /*
         * `$sender` lets the caller send as a particular person rather than the tenant's one shared
         * address — see CrmSenderIdentity, which is what decides whether that is deliverable. It
         * carries an optional `reply_to` for the case where the From header has to stay the
         * authenticated address but the replies still belong to somebody.
         */
        $from = $sender ?? $this->from();
        $replyTo = $sender['reply_to'] ?? null;

        $domain = $from && str_contains($from['address'] ?? '', '@')
            ? explode('@', $from['address'])[1]
            : 'localhost';

        $this->marketingMailer()->html($html, function ($message) use ($to, $subject, $from, $replyTo, $unsubscribeUrl, $listId, $domain) {
            $message->to($to)->subject($subject);
            if ($from && ! empty($from['address'])) {
                $message->from($from['address'], $from['name']);
            }
            if ($replyTo) {
                $message->replyTo($replyTo, $from['name'] ?? null);
            }

            $headers = $message->getHeaders();
            $headers->addTextHeader('List-Unsubscribe', '<' . $unsubscribeUrl . '>');
            $headers->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
            $headers->addTextHeader('List-Id', ($listId ?: 'bulk') . '.' . $domain);
            $headers->addTextHeader('Precedence', 'bulk');
            $headers->addTextHeader('Auto-Submitted', 'auto-generated');
        });
    }

    /**
     * Send a raw HTML email through the configured CRM provider.
     *
     * @param  string|array  $to
     */
    public function sendHtml($to, string $subject, string $html): void
    {
        $from = $this->from();

        $this->mailer()->html($html, function ($message) use ($to, $subject, $from) {
            $message->to($to)->subject($subject);
            if ($from) {
                $message->from($from['address'], $from['name']);
            }
        });
    }
}
