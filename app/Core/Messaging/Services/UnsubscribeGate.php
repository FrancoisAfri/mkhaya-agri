<?php

namespace App\Core\Messaging\Services;

use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Stands between an unsubscribe link and the act of unsubscribing somebody.
 *
 * Unsubscribing used to happen on a GET, in both this platform's bulk-mail modules. Corporate mail
 * security — Microsoft Safe Links, Proofpoint, Mimecast, most spam filters — fetches every URL in
 * an inbound message to check it, before the recipient sees the mail and whether or not they ever
 * open it. Every one of those fetches unsubscribed that person.
 *
 * Three things made it worse than the usual version of this bug: the unsubscribe writes to the
 * tenant-wide suppression list, so it blocks every future campaign rather than one; the screens
 * deliberately refuse to undo an unsubscribe, because it is the person's decision, which is the
 * right rule and the wrong outcome when the person never made the decision; and a scanner sweeping
 * one campaign hits every tagged URL in it, so the blast radius is the entire recipient list.
 *
 * So: a GET renders a page with a button, and only a POST performs it. Mail clients that offer a
 * genuine one-click unsubscribe use RFC 8058, which posts and is never prefetched — the headers for
 * that are on this class too.
 */
class UnsubscribeGate
{
    /**
     * True when this request may actually unsubscribe somebody.
     *
     * A POST from the confirmation page, or a POST from a mail client honouring RFC 8058, which
     * sends `List-Unsubscribe=One-Click` in the body.
     */
    public function mayProceed(Request $request): bool
    {
        return $request->isMethod('POST');
    }

    /** RFC 8058 one-click, which every major mail client understands and no scanner prefetches. */
    public function headers(string $unsubscribeUrl, ?string $mailto = null): array
    {
        $targets = $mailto ? "<mailto:{$mailto}>, <{$unsubscribeUrl}>" : "<{$unsubscribeUrl}>";

        return [
            'List-Unsubscribe'      => $targets,
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ];
    }

    /**
     * The page a GET gets: what is about to happen, and a button that does it.
     *
     * Deliberately plain and self-contained — it is opened from an email client, often on a phone,
     * by somebody who is already mildly annoyed. No assets to load, one control, no dark pattern.
     */
    public function confirmationPage(string $postUrl, string $address = '', string $sender = ''): Response
    {
        $safeUrl = htmlspecialchars($postUrl, ENT_QUOTES);
        $safeAddress = htmlspecialchars($address, ENT_QUOTES);
        $safeSender = htmlspecialchars($sender ?: config('app.name'), ENT_QUOTES);
        $token = csrf_token();

        $who = $safeAddress !== ''
            ? "<p>We will stop sending to <strong>{$safeAddress}</strong>.</p>"
            : '';

        $html = <<<HTML
        <!doctype html><meta charset="utf-8">
        <meta name="viewport" content="width=device-width,initial-scale=1">
        <title>Unsubscribe</title>
        <style>
          body { font: 16px/1.6 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
                 max-width: 32rem; margin: 4rem auto; padding: 0 1.25rem; color: #16181d; background: #fff; }
          h1 { font-size: 1.35rem; margin: 0 0 .75rem; }
          p { color: #4a5058; }
          button { font: inherit; font-weight: 600; cursor: pointer; margin-top: 1.25rem;
                   padding: .7rem 1.4rem; border: 0; border-radius: 6px; background: #16181d; color: #fff; }
          button:focus-visible { outline: 3px solid #6ea8fe; outline-offset: 2px; }
          @media (prefers-color-scheme: dark) {
            body { background: #101216; color: #eceff3; } p { color: #a8b0ba; }
            button { background: #eceff3; color: #101216; }
          }
        </style>
        <h1>Unsubscribe from {$safeSender}?</h1>
        {$who}
        <p>You will not receive further messages from this sender. Nothing else is required from you.</p>
        <form method="POST" action="{$safeUrl}">
          <input type="hidden" name="_token" value="{$token}">
          <button type="submit">Yes, unsubscribe me</button>
        </form>
        HTML;

        return response($html, 200, [
            'Content-Type'  => 'text/html; charset=utf-8',
            // Never cached, and never indexed: this URL identifies one person.
            'Cache-Control' => 'no-store, private',
            'X-Robots-Tag'  => 'noindex, nofollow',
        ]);
    }

    /** The page shown once it is done. */
    public function donePage(string $sender = ''): Response
    {
        $safeSender = htmlspecialchars($sender ?: config('app.name'), ENT_QUOTES);

        $html = <<<HTML
        <!doctype html><meta charset="utf-8">
        <meta name="viewport" content="width=device-width,initial-scale=1">
        <title>Unsubscribed</title>
        <style>
          body { font: 16px/1.6 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
                 max-width: 32rem; margin: 4rem auto; padding: 0 1.25rem; color: #16181d; background: #fff; }
          h1 { font-size: 1.35rem; margin: 0 0 .75rem; }
          p { color: #4a5058; }
          @media (prefers-color-scheme: dark) { body { background: #101216; color: #eceff3; } p { color: #a8b0ba; } }
        </style>
        <h1>You have been unsubscribed</h1>
        <p>{$safeSender} will not send you further messages. Nothing else is required from you.</p>
        HTML;

        return response($html, 200, [
            'Content-Type'  => 'text/html; charset=utf-8',
            'Cache-Control' => 'no-store, private',
            'X-Robots-Tag'  => 'noindex, nofollow',
        ]);
    }
}
