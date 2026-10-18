<?php

namespace App\Modules\Operations\Sheq\Services;

use App\Modules\Operations\Sheq\Contracts\NotificationTransport;
use App\Modules\Operations\Sheq\Support\Timestamps;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Drains the dispatch queue (SHEQ-INC-4/5, delivery half).
 *
 * Separate from the router so that routing is a pure decision recorded in a transaction with the
 * incident, and delivery is a retryable side effect. The failure this arrangement prevents is the
 * common one: an incident committed while its notifications were lost because the mail server was
 * down at that instant.
 *
 * Rows are claimed with `FOR UPDATE SKIP LOCKED` so two workers can drain the same queue without
 * either sending a duplicate or blocking on the other.
 */
class NotificationSender
{
    /** After this many attempts the row is parked as FAILED for a human, not retried forever. */
    private const MAX_ATTEMPTS = 5;

    public function __construct(private NotificationTransport $transport) {}

    /**
     * Send everything due for one tenant.
     *
     * @return array{sent:int, failed:int, skipped:int}
     */
    public function drain(int $companyId, int $limit = 100): array
    {
        $sent = 0;
        $failed = 0;
        $skipped = 0;

        $due = $this->claim($companyId, $limit);

        foreach ($due as $row) {
            if (! $this->transport->supports($row->channel)) {
                // Left PENDING on purpose: an unsupported channel is an undelivered obligation,
                // and marking it sent would be a false record of a statutory alert.
                $skipped++;

                continue;
            }

            $ok = $this->transport->send($this->message($row));

            if ($ok) {
                DB::table('sheq_notification_dispatches')->where('id', $row->id)->update([
                    'status' => 'SENT',
                    'sent_at' => Timestamps::utc(Carbon::now()),
                    'attempts' => $row->attempts + 1,
                    'last_error' => null,
                    'updated_at' => now(),
                ]);
                $sent++;

                continue;
            }

            $attempts = $row->attempts + 1;

            DB::table('sheq_notification_dispatches')->where('id', $row->id)->update([
                // A CRITICAL alert is never abandoned: it stays pending and keeps showing up on the
                // queue until somebody deals with it. Routine traffic is allowed to give up.
                'status' => $attempts >= self::MAX_ATTEMPTS && $row->severity !== 'CRITICAL'
                    ? 'FAILED' : 'PENDING',
                'attempts' => $attempts,
                'last_error' => 'Transport declined the message.',
                // Back off so a broken provider does not burn the whole queue on one pass.
                'send_after' => Timestamps::utc(Carbon::now()->addMinutes(min(60, 2 ** $attempts))),
                'updated_at' => now(),
            ]);
            $failed++;
        }

        return ['sent' => $sent, 'failed' => $failed, 'skipped' => $skipped];
    }

    /** @return list<object> */
    private function claim(int $companyId, int $limit): array
    {
        $query = DB::table('sheq_notification_dispatches')
            ->where('company_id', $companyId)
            ->where('status', 'PENDING')
            ->where('send_after', '<=', Timestamps::utc(Carbon::now()))
            // Critical first: if the queue is backed up, the fatality alert goes before the
            // near-miss digest.
            ->orderByRaw("CASE severity WHEN 'CRITICAL' THEN 0 WHEN 'HIGH' THEN 1 ELSE 2 END")
            ->orderBy('send_after')
            ->orderBy('id')
            ->limit($limit);

        if (DB::getDriverName() === 'pgsql') {
            // Raw, because the query builder has no skipLocked(): two senders must be able to drain
            // the same queue without one blocking on the other or both sending the same alert.
            return $query->lock('for update skip locked')->get()->all();
        }

        return $query->get()->all();
    }

    private function message(object $row): array
    {
        $payload = json_decode((string) $row->payload, true) ?: [];

        return [
            'channel' => $row->channel,
            'address' => $row->recipient_address,
            'user_id' => $row->recipient_user_id ? (int) $row->recipient_user_id : null,
            'label' => $row->recipient_label,
            'severity' => $row->severity,
            'subject' => $this->subject($row, $payload),
            'body' => $this->body($row, $payload),
            'link' => $payload['link'] ?? null,
            'reference' => $row->subject_reference,
        ];
    }

    private function subject(object $row, array $payload): string
    {
        $reference = $row->subject_reference ? ' '.$row->subject_reference : '';

        /*
         * The CRITICAL wording belongs to incidents. Nothing but the statutory floor sets that
         * severity, and the floor only fires on a fatality or a section 24 event, so a reminder
         * can never reach this branch — it carries its own title through the line below.
         */
        if ($row->severity === 'CRITICAL') {
            return trim('Reportable incident'.$reference.' — immediate action required');
        }

        return trim(($payload['title'] ?? 'SHEQ incident').$reference);
    }

    private function body(object $row, array $payload): string
    {
        // A reminder composed its own wording against the thing it is reminding about — the
        // competency's name, the document's number, the supplier's outstanding list. Nothing
        // assembled from incident fields would say any of that.
        if (! empty($payload['body'])) {
            return (string) $payload['body'];
        }

        $lines = [];

        if ($row->recipient_label) {
            $lines[] = $row->recipient_label.',';
            $lines[] = '';
        }

        $lines[] = $payload['title'] ?? 'An incident has been reported.';

        if (! empty($payload['incident_type'])) {
            $lines[] = 'Type: '.$payload['incident_type'];
        }

        if (! empty($payload['statutory_category'])) {
            $lines[] = 'Statutory classification: '.$payload['statutory_category'];
        }

        if ($row->recipient_role) {
            $lines[] = 'You are receiving this as: '.$row->recipient_role;
        }

        return implode("\n", $lines);
    }
}
