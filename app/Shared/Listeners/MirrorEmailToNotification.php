<?php

namespace App\Shared\Listeners;

use App\Core\User\Models\User;
use App\Shared\Notifications\GenericInAppNotification;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Str;

/**
 * Mirrors every outgoing email into an in-app notification so users always see,
 * in the bell, anything that was emailed to them — "all emails going out should
 * also be added to notifications".
 *
 * Laravel Notification emails are skipped: those notifications already own their
 * in-app delivery via the `database` channel (made reliable/synchronous by
 * {@see \App\Shared\Notifications\SyncInAppNotificationSender}), so mirroring
 * them too would create duplicates. This listener therefore fills the gap left
 * by raw Mailables / Mail::send() calls.
 */
class MirrorEmailToNotification
{
    public function handle(MessageSending $event): void
    {
        try {
            $this->mirror($event);
        } catch (\Throwable $e) {
            // Mirroring must never block an email from being sent.
        }
    }

    private function mirror(MessageSending $event): void
    {
        // Notification-originated mail already creates its own in-app row.
        if (isset($event->data['__laravel_notification'])) {
            return;
        }

        $recipients = $this->recipientEmails($event);
        if (empty($recipients)) {
            return;
        }

        $users = User::whereIn('email', $recipients)->get();
        if ($users->isEmpty()) {
            return;
        }

        $subject = $event->message->getSubject() ?: 'New message';
        $preview = $this->bodyPreview($event);

        foreach ($users as $user) {
            $user->notify(new GenericInAppNotification(
                title: $subject,
                message: $preview,
                url: null,
                type: 'email',
            ));
        }
    }

    /** @return string[] lower-cased recipient addresses (To only) */
    private function recipientEmails(MessageSending $event): array
    {
        $to = $event->message->getTo();

        return collect($to)
            ->map(fn ($addr) => strtolower(trim($addr->getAddress())))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * The first line or two of the message, as a person would read it.
     *
     * ── Why `strip_tags()` alone is not enough ──────────────────────────────
     *
     * It removes the *tags* and keeps everything between them — including the contents of `<style>`
     * and `<title>`, which are not tags to be stripped but text to be discarded. Every templated
     * email on this platform carries a stylesheet in its head, so every mirrored notification read
     * "MkhayaHub @media only screen and (max-width: 600px) { .inner-body {" and then ran out of
     * characters. The one thing the preview exists to show — what the message actually says — was
     * always past the limit.
     *
     * So the head goes first, then any script or style block that survived it, and only then are
     * the remaining tags stripped.
     *
     * Block ends become spaces before that happens, or the last word of one line runs into the
     * first word of the next and the preview reads as nonsense. Entities are decoded because
     * `&amp;` and `&nbsp;` are what an HTML body is full of and neither is a word.
     */
    private function bodyPreview(MessageSending $event): string
    {
        $body = $event->message->getHtmlBody() ?: $event->message->getTextBody() ?: '';

        if (! is_string($body) || $body === '') {
            return '';
        }

        // Everything that is markup rather than message. `<head>` covers title, style and meta in
        // one go; the rest catches blocks placed in the body, which templated mail often does.
        $body = preg_replace('#<head\b[^>]*>.*?</head>#is', ' ', $body) ?? $body;
        $body = preg_replace('#<(style|script|title|noscript)\b[^>]*>.*?</\1>#is', ' ', $body) ?? $body;

        // Comments too: conditional comments for Outlook are a wall of markup that reads as text.
        $body = preg_replace('/<!--.*?-->/s', ' ', $body) ?? $body;

        // Where one block ends and the next begins is a word boundary, not a join.
        $body = preg_replace('#<(br|/p|/div|/td|/tr|/h[1-6]|/li)\b[^>]*>#i', ' ', $body) ?? $body;

        /*
         * Decode, then strip — in that order, and the order is the security-relevant part.
         *
         * Stripping first and decoding after stores whatever `&lt;script&gt;` decodes into, so the
         * preview held live markup as text. Nothing renders it as HTML today — the bell interpolates
         * it, which escapes — but this is a column many things could read later, and "safe because
         * of how it happens to be displayed" is a property that quietly stops being true.
         *
         * Decoding first means the strip sees those tags and removes them, so the stored preview
         * contains no markup under any encoding. The cost is an email that talks *about* a tag
         * losing the word; for a hundred-and-sixty character preview that is worth the invariant.
         */
        $text = strip_tags(html_entity_decode($body, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        // A non-breaking space is not whitespace to `\s`, and there are many of them in table mail.
        $text = str_replace(["\xC2\xA0", "\u{200B}"], ' ', $text);
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');

        return Str::limit($text, 160);
    }
}
