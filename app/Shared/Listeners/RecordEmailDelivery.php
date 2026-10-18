<?php

namespace App\Shared\Listeners;

use App\Shared\Models\EmailDeliveryLog;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Throwable;

/**
 * Write down every email this system tries to send, and what came of it.
 *
 * There was no way to answer "did that go out?". An invitation, an invoice, a signing request — the
 * only evidence was the recipient saying it never arrived, and by then nobody could tell whether it
 * had been sent and lost, refused by the mail server, or never attempted at all.
 *
 * Two events, because one is not enough. `MessageSending` fires before the transport is touched and
 * `MessageSent` only after it accepts — so a transport that throws produces the first and never the
 * second. Writing the row on the way in and completing it on the way out means a failed send leaves
 * evidence of itself without anything having to catch it.
 */
class RecordEmailDelivery
{
    /** Logged before the attempt, so a send that never returns still leaves a trace. */
    public function sending(MessageSending $event): void
    {
        $this->safely(function () use ($event) {
            EmailDeliveryLog::create([
                'uuid'       => (string) Str::uuid(),
                'to'         => $this->addresses($event->message),
                'subject'    => Str::limit((string) $event->message->getSubject(), 290, ''),
                'mailable'   => $this->originOf($event->data),
                'status'     => EmailDeliveryLog::PENDING,
                'message_id' => $this->messageId($event->message),
                'mailer'     => $event->data['mailer'] ?? config('mail.default'),
                'user_id'    => Auth::id(),
            ]);
        });
    }

    /** The transport accepted it. Not proof it arrived — nothing here can see that far. */
    public function sent(MessageSent $event): void
    {
        $this->safely(function () use ($event) {
            /*
             * `MessageSent::$message` is the Email itself on this version, not a SentMessage —
             * calling getOriginalMessage() on it threw, and the guard below swallowed it, so every
             * row stayed pending while the mail went out perfectly well. Both shapes are accepted
             * because which one arrives is a framework detail, not a fact about the send.
             */
            $sent = $event->message;
            $message = method_exists($sent, 'getOriginalMessage') ? $sent->getOriginalMessage() : $sent;
            $id = $this->messageId($message);

            /*
             * By Message-ID where there is one, and otherwise by the address.
             *
             * Symfony stamps the Message-ID during the send, so at `MessageSending` there is
             * usually nothing to record — which made matching on it alone leave every row pending
             * and the page reporting that nothing had ever succeeded. The pending row for that
             * recipient is the same attempt: the two events are raised around one send, in order,
             * on the same request.
             */
            $row = EmailDeliveryLog::query()
                ->where('status', EmailDeliveryLog::PENDING)
                ->where('to', $this->addresses($message, $sent))
                ->latest('id')
                ->first();

            $row?->update([
                'status'     => EmailDeliveryLog::SENT,
                // Kept now it exists, so a bounce investigation has something to quote.
                'message_id' => $id ?: $row->message_id,
            ]);
        });
    }

    /**
     * Which Mailable or Notification this was, so a whole category can be found at once — "did any
     * of yesterday's invitations go out?" is the question people actually ask.
     */
    private function originOf(array $data): ?string
    {
        /*
         * `__laravel_mailable` is the key Laravel actually sets, and it was missing from this list.
         *
         * The list read `['__laravel_notification', 'mailable']`, so notifications were recorded
         * and every Mailable — invitations, invoices, signing requests — came through with a blank
         * kind. That is the one column this page is filtered by, so "did any of yesterday's
         * invitations go out?" returned nothing at all, which reads as "none were sent" rather than
         * as "this column was never filled in".
         *
         * Kept alongside the plain 'mailable' key rather than replacing it: nothing sets that one
         * today, but it costs a string comparison and this list is the whole reason the page works.
         */
        foreach (['__laravel_notification', '__laravel_mailable', 'mailable'] as $key) {
            if (! empty($data[$key]) && is_string($data[$key])) {
                return Str::limit($data[$key], 185, '');
            }
        }

        return null;
    }

    /**
     * The Message-ID, if the transport has stamped one yet.
     *
     * Guarded on its own rather than under the caller's guard: the header object's shape varies by
     * Symfony version, and a throw in here was silently taking the whole completion with it —
     * leaving every row pending and the page reporting that nothing had ever succeeded.
     */
    private function messageId($message): ?string
    {
        try {
            $header = $message?->getHeaders()?->get('Message-ID');

            return $header ? Str::limit((string) $header->getBodyAsString(), 185, '') : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Who it was addressed to.
     *
     * `getOriginalMessage()` is typed as RawMessage and only carries recipients when it is an
     * Email — a raw send is not. The envelope always knows, so it is the fallback: without it the
     * lookup threw, the guard swallowed it, and every row stayed pending while the mail went out
     * perfectly well.
     */
    private function addresses($message, $sent = null): string
    {
        $to = [];

        if (is_object($message) && method_exists($message, 'getTo')) {
            $to = array_map(fn ($a) => $a->getAddress(), $message->getTo() ?: []);
        }

        if ($to === [] && $sent !== null && method_exists($sent, 'getEnvelope')) {
            $to = array_map(fn ($a) => $a->getAddress(), $sent->getEnvelope()->getRecipients() ?: []);
        }

        return Str::limit(implode(', ', $to) ?: 'unknown', 250, '');
    }

    /** A log that can break a send is worse than no log. */
    private function safely(callable $work): void
    {
        try {
            $work();
        } catch (Throwable $e) {
            report($e);
        }
    }
}
