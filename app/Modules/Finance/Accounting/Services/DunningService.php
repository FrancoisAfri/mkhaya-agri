<?php

namespace App\Modules\Finance\Accounting\Services;

use App\Core\SMS\Services\SmsService;
use App\Modules\CRM\Services\CrmWhatsAppService;
use App\Modules\Finance\Accounting\Mail\FinanceDocumentMail;
use App\Modules\Finance\Accounting\Models\FinCollectionActivity;
use App\Modules\Finance\Accounting\Models\FinInvoice;
use App\Modules\Finance\Accounting\Models\FinSetting;
use App\Modules\Finance\Accounting\Support\ReminderTemplates;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * The dunning / collections engine. Sends payment reminders across email, SMS and WhatsApp, driven
 * by a configurable per-company ladder (escalating tone as an invoice ages), localised to each
 * customer's language, with a "pay now" link. Respects disputes, do-not-contact and once-per-day
 * throttling. Also flags broken promises-to-pay. Every send is recorded as a collection activity and
 * advances the invoice's dunning step.
 */
class DunningService
{
    public function __construct(private FinanceDocumentService $docs)
    {
    }

    /**
     * Send a single reminder for an invoice. Channel/tone default to the invoice's next ladder step.
     *
     * @return array{sent:bool, channel:?string, reason:?string}
     */
    public function sendReminder(FinInvoice $invoice, ?string $channel = null, ?string $tone = null, ?int $step = null): array
    {
        $invoice->loadMissing('party');
        $party = $invoice->party;
        $settings = FinSetting::current($invoice->company_id);

        if ($invoice->disputed) {
            return $this->skip('disputed');
        }
        if ($party?->do_not_contact) {
            return $this->skip('do_not_contact');
        }

        /*
         * A reminder needs somebody to send it to.
         *
         * pickChannel() is null-safe and returns null for a party with no contact details — or no
         * party at all — which is what the no_contact skip below is for. But it was only consulted
         * when the caller left the channel open, and runLadder() always passes the ladder step's
         * channel. So on the one path that actually runs in production, the guard was skipped and
         * `$party->language` a few lines down fatalled on a null. That killed finance:send-reminders
         * for the rest of the tenant and every tenant after it, nightly.
         *
         * Resolved the same way whichever path arrives: an explicitly requested channel is honoured
         * only if this party can actually be reached on it, and otherwise falls back to whatever is
         * configured — a customer with a phone and no email should still be chased.
         */
        if (! $party) {
            /*
             * fin_invoices.party_id is NOT NULL, so this is never a missing reference — the row
             * exists and is being scoped away, because FinParty is tenant-scoped and this invoice
             * points at a party belonging to another company or to none. That is a data problem
             * somebody has to fix, not a customer to leave alone quietly, so it is said out loud
             * rather than counted as an ordinary skip.
             */
            Log::warning('Dunning: invoice party is not visible to this tenant', [
                'invoice'    => $invoice->id,
                'party_id'   => $invoice->party_id,
                'company_id' => $invoice->company_id,
            ]);

            return $this->skip('no_party');
        }

        if (! $channel || ! $this->reachableOn($party, $channel)) {
            $channel = $this->pickChannel($settings, $party);
        }

        if (! $channel) {
            return $this->skip('no_contact');
        }

        $daysOverdue = $this->daysOverdue($invoice);
        $tone = $tone ?: $this->toneForStep($settings, $daysOverdue, $step);
        $lang = $party->language ?: config('app.locale', 'en');
        $vars = $this->vars($invoice, $settings, $daysOverdue);
        $msg = ReminderTemplates::render($lang, $tone, $vars);

        $ok = match ($channel) {
            'sms'      => $this->viaSms($party->phone, $msg['sms']),
            'whatsapp' => $this->viaWhatsapp($party->phone, $msg['sms']),
            default    => $this->viaEmail($invoice, $party->email, $msg['subject'], $msg['body']),
        };

        if (! $ok) {
            return $this->skip('send_failed', $channel);
        }

        FinCollectionActivity::create([
            'invoice_id' => $invoice->id, 'party_id' => $invoice->party_id, 'type' => 'reminder',
            'channel' => $channel, 'body' => "Reminder sent via {$channel} ({$tone}).",
            'meta' => ['tone' => $tone, 'days_overdue' => $daysOverdue], 'created_by' => Auth::id(),
        ]);

        $invoice->forceFill([
            'dunning_step'     => $step ?? ((int) $invoice->dunning_step + 1),
            'last_reminded_at' => now(),
        ])->save();

        return ['sent' => true, 'channel' => $channel, 'reason' => null];
    }

    /**
     * Run the dunning ladder for one company: every overdue, non-disputed, contactable invoice that
     * has reached its next ladder step and was not already reminded today gets that step's reminder.
     * Call inside TenantContext::run so it is company-scoped. Returns counts.
     *
     * @return array{sent:int, skipped:int}
     */
    public function runLadder(?int $companyId): array
    {
        $settings = FinSetting::current($companyId);
        if (! $settings->reminders_enabled) {
            return ['sent' => 0, 'skipped' => 0];
        }

        $ladder = $settings->ladder();
        if (! $ladder) {
            return ['sent' => 0, 'skipped' => 0];
        }

        $invoices = FinInvoice::with('party')
            ->where('company_id', $companyId)
            ->where('direction', 'receivable')->where('doc_type', 'invoice')
            ->whereIn('status', ['open', 'part_paid'])
            ->whereRaw('total - amount_paid > 0.001')
            ->where('disputed', false)
            ->whereDate('due_date', '<', Carbon::today())
            ->get();

        $sent = $skipped = 0;
        foreach ($invoices as $invoice) {
            $days = $this->daysOverdue($invoice);
            $dueStep = $this->dueStepIndex($ladder, $days);        // highest ladder step reached (1-based), or 0
            if ($dueStep === 0 || $dueStep <= (int) $invoice->dunning_step) {
                $skipped++;
                continue;
            }
            if ($invoice->last_reminded_at && $invoice->last_reminded_at->isToday()) {
                $skipped++;
                continue;
            }
            $stepCfg = $ladder[$dueStep - 1];

            /*
             * One unsendable invoice is not a reason to stop chasing the rest.
             *
             * This ran as a scheduled command across every tenant in turn, so a single bad row
             * ended the whole night's collections — for this company and for every company after
             * it — and the only trace was a stack trace in the log.
             */
            try {
                $r = $this->sendReminder($invoice, $stepCfg['channel'], $stepCfg['tone'], $dueStep);
                $r['sent'] ? $sent++ : $skipped++;
            } catch (\Throwable $e) {
                $skipped++;
                Log::warning('Dunning reminder failed for one invoice', [
                    'invoice'    => $invoice->id,
                    'company_id' => $companyId,
                    'error'      => $e->getMessage(),
                ]);
            }
        }

        return ['sent' => $sent, 'skipped' => $skipped];
    }

    /** Flag promises-to-pay that have lapsed (past promised_date + grace, still unpaid). */
    public function flagBrokenPromises(?int $companyId): int
    {
        $grace = (int) (FinSetting::current($companyId)->reminder_grace_days ?? 2);
        $cutoff = Carbon::today()->subDays($grace);

        $promises = FinCollectionActivity::query()
            ->where('type', 'promise')
            ->whereDate('promised_date', '<', $cutoff)
            ->whereHas('invoice', fn ($i) => $i->where('company_id', $companyId)
                ->whereIn('status', ['open', 'part_paid'])->whereRaw('total - amount_paid > 0.001'))
            ->with('invoice')->get();

        $flagged = 0;
        foreach ($promises->groupBy('invoice_id') as $invoiceId => $acts) {
            // one broken-promise note per invoice per lapse (avoid spamming the timeline)
            $already = FinCollectionActivity::where('invoice_id', $invoiceId)->where('type', 'broken_promise')
                ->where('created_at', '>=', $acts->max('promised_date'))->exists();
            if ($already) {
                continue;
            }
            $p = $acts->sortByDesc('promised_date')->first();
            FinCollectionActivity::create([
                'invoice_id' => $invoiceId, 'party_id' => $p->party_id, 'type' => 'broken_promise',
                'body' => 'Promise to pay lapsed (' . $p->promised_date->toDateString() . ').',
                'meta' => ['promised_date' => $p->promised_date->toDateString()],
            ]);
            $flagged++;
        }

        return $flagged;
    }

    // ── internals ─────────────────────────────────────────────────────────────

    /** Whether this party carries the contact detail a given channel needs. */
    private function reachableOn($party, string $channel): bool
    {
        return match ($channel) {
            'sms', 'whatsapp' => filled($party?->phone),
            default           => filled($party?->email),
        };
    }

    private function pickChannel(FinSetting $settings, $party): ?string
    {
        foreach ($settings->channels() as $ch) {
            if ($ch === 'email' && $party?->email) {
                return 'email';
            }
            if (in_array($ch, ['sms', 'whatsapp'], true) && $party?->phone) {
                return $ch;
            }
        }

        return null;
    }

    private function toneForStep(FinSetting $settings, int $days, ?int $step): string
    {
        $ladder = $settings->ladder();
        if ($step && isset($ladder[$step - 1])) {
            return $ladder[$step - 1]['tone'];
        }
        $idx = $this->dueStepIndex($ladder, $days);

        return $idx > 0 ? $ladder[$idx - 1]['tone'] : 'friendly';
    }

    /** Highest ladder step (1-based) whose threshold days have been reached; 0 if none. */
    private function dueStepIndex(array $ladder, int $days): int
    {
        $idx = 0;
        foreach ($ladder as $i => $step) {
            if ($days >= (int) $step['days']) {
                $idx = $i + 1;
            }
        }

        return $idx;
    }

    private function daysOverdue(FinInvoice $invoice): int
    {
        $due = Carbon::parse($invoice->due_date);

        return $due->lt(Carbon::today()) ? (int) $due->diffInDays(Carbon::today()) : 0;
    }

    private function vars(FinInvoice $invoice, FinSetting $settings, int $days): array
    {
        return [
            'party'     => $invoice->party->name,
            'number'    => $invoice->number,
            'amount'    => number_format($invoice->outstanding(), 2),
            'currency'  => $invoice->currency,
            'due_date'  => optional($invoice->due_date)->format('d M Y'),
            'days'      => $days,
            'company'   => $settings->company_name ?: config('app.name'),
            'bank'      => trim(($settings->bank_name ?? '') . ' ' . ($settings->bank_account_number ?? '')),
            'pay_link'  => ($settings->payment_link_enabled ?? true) && $invoice->pay_token ? route('finance.pay.show', $invoice->pay_token) : '',
        ];
    }

    private function viaEmail(FinInvoice $invoice, ?string $email, string $subject, string $body): bool
    {
        if (! $email) {
            return false;
        }
        try {
            Mail::to($email)->send(new FinanceDocumentMail(
                $subject, $invoice->party->name, '<p>' . nl2br(e($body)) . '</p>',
                $this->docs->invoicePdf($invoice)->output(), "{$invoice->number}.pdf"
            ));

            return true;
        } catch (\Throwable $e) {
            Log::error('Dunning email failed', ['invoice' => $invoice->id, 'error' => $e->getMessage()]);

            return false;
        }
    }

    private function viaSms(?string $phone, string $text): bool
    {
        if (! $phone || ! SmsService::isConfigured()) {
            return false;
        }

        return SmsService::send($phone, $text, 'finance_reminder');
    }

    private function viaWhatsapp(?string $phone, string $text): bool
    {
        if (! $phone) {
            return false;
        }
        try {
            $r = app(CrmWhatsAppService::class)->sendText($phone, $text);

            return (bool) ($r['available'] ?? false) && empty($r['error']);
        } catch (\Throwable $e) {
            Log::warning('Dunning WhatsApp failed', ['error' => $e->getMessage()]);

            return false;
        }
    }

    private function skip(string $reason, ?string $channel = null): array
    {
        return ['sent' => false, 'channel' => $channel, 'reason' => $reason];
    }
}
