<?php

namespace App\Console\Commands\Mail;

use App\Shared\Models\EmailDeliveryLog;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Read the email delivery log from a terminal.
 *
 * The same rows the Governance page shows, reachable over SSH. That page needs the code deployed,
 * the ribbons synced and the assets built before anybody can open it — and the question it answers,
 * "did that invitation actually go out?", is most urgent exactly when a release has not happened
 * yet. A report nobody can reach during an incident is not a report.
 *
 * Read-only by design: it writes nothing and sends nothing.
 */
class ShowEmailLog extends Command
{
    protected $signature = 'mail:log
                            {--kind= : Only this Mailable or Notification, matched loosely (e.g. Invite)}
                            {--to= : Only this recipient, matched loosely}
                            {--status= : sent, failed or pending}
                            {--failed : Only what did not go out}
                            {--limit=25 : How many to show, newest first}
                            {--errors : Print each failure reason in full instead of truncating}';

    protected $description = 'Show recent email sends and what came of each';

    public function handle(): int
    {
        /*
         * Unscoped on purpose.
         *
         * The model is BelongsToCompany, and a console pass has no signed-in user — so the ordinary
         * scope would resolve no company and quietly show nothing. Somebody with a shell on the
         * server is already past every boundary this would enforce, and a diagnostic that hides the
         * rows it was opened to find is worse than useless: it answers "nothing was sent".
         */
        $query = EmailDeliveryLog::withoutGlobalScopes()->latest('id');

        if ($kind = $this->option('kind')) {
            $query->where('mailable', 'ilike', '%' . $kind . '%');
        }

        if ($to = $this->option('to')) {
            $query->where('to', 'ilike', '%' . $to . '%');
        }

        if ($this->option('failed')) {
            $query->where('status', EmailDeliveryLog::FAILED);
        } elseif ($status = $this->option('status')) {
            $query->where('status', $status);
        }

        $rows = $query->limit(max(1, (int) $this->option('limit')))->get();

        if ($rows->isEmpty()) {
            $this->warn('Nothing in the log matches that.');
            $this->line('  The log records a row per attempt, so an empty result means no send was');
            $this->line('  attempted — not that one was attempted and failed.');

            return self::SUCCESS;
        }

        $this->table(
            ['When', 'To', 'Kind', 'Status', 'Mailer', 'Reason'],
            $rows->map(fn (EmailDeliveryLog $row) => [
                $row->created_at?->format('Y-m-d H:i'),
                Str::limit($row->to, 34),
                // The class tail: the namespace is the same for every row and costs the width the
                // reason needs.
                class_basename((string) $row->mailable) ?: '—',
                $row->status,
                $row->mailer,
                $this->option('errors') ? (string) $row->error : Str::limit((string) $row->error, 40),
            ])->all(),
        );

        $this->newLine();
        $this->line(sprintf(
            '  sent %d · failed %d · pending %d  (of the %d shown)',
            $rows->where('status', EmailDeliveryLog::SENT)->count(),
            $rows->where('status', EmailDeliveryLog::FAILED)->count(),
            $rows->where('status', EmailDeliveryLog::PENDING)->count(),
            $rows->count(),
        ));

        /*
         * Said every time, because it is the thing most often misread. "sent" means the mail server
         * took the message — nothing in this process can see an inbox, so a run of sent rows beside
         * a recipient who received nothing points at what happened after the handover: the From
         * address failing SPF, the relay dropping it, or a sandbox transport that never delivers.
         */
        $this->line('  "sent" means the mail server accepted it. It is not proof of arrival.');

        if ($rows->contains(fn ($r) => $r->status === EmailDeliveryLog::SENT)) {
            $from = config('mail.from.address');
            $this->newLine();
            $this->line('  Sending as: <options=bold>' . $from . '</> via ' . config('mail.default')
                . ' (' . config('mail.mailers.' . config('mail.default') . '.host', 'n/a') . ')');

            if (Str::contains((string) $from, ['example.com', 'example.test'])) {
                $this->warn('  MAIL_FROM_ADDRESS is unset, so this is Laravel\'s placeholder. Mail sent');
                $this->warn('  from it fails SPF for your domain and is normally dropped after being');
                $this->warn('  accepted — which looks exactly like this log.');
            }
        }

        return self::SUCCESS;
    }
}
