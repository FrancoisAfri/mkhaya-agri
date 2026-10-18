<?php

namespace App\Modules\CRM\Jobs;

use App\Core\Messaging\Services\MergeFields;
use App\Modules\CRM\Models\CrmCampaign;
use App\Modules\CRM\Models\CrmCampaignRecipient;
use App\Modules\CRM\Models\CrmSuppression;
use App\Modules\CRM\Services\CrmMailService;
use App\Modules\CRM\Services\CrmSendingGuard;
use App\Modules\CRM\Services\CrmWhatsAppService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Sends a campaign to its pending recipients off the request thread (spec §8 /
 * marketing). Email is rewritten with an open-tracking pixel and click-tracking
 * links; WhatsApp goes via the Cloud API. Per-recipient delivery is recorded and
 * the campaign's aggregate counts are updated.
 */
class SendCampaignJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 2;
    public int $timeout = 600;

    /**
     * @param  int|null  $cap  max recipients to send this run (drip). null = send all.
     */
    /** How long one slice of a batch may take before the job hands the worker back. */
    private const SLICE_SECONDS = 5;

    public function __construct(private readonly int $campaignId, private readonly ?int $cap = null)
    {
    }

    public function handle(CrmWhatsAppService $whatsapp, CrmSendingGuard $guard): void
    {
        $campaign = CrmCampaign::find($this->campaignId);
        if (! $campaign) return;

        // Email reputation is a shared, tenant-wide asset: if bounces or spam
        // complaints have crossed the line, stop rather than keep sending and
        // make it worse. WhatsApp is a separate channel and unaffected.
        if ($campaign->channel !== 'whatsapp' && ($reason = $guard->pauseReason())) {
            $guard->pauseCampaign($campaign, $reason);
            return;
        }

        $campaign->update(['status' => 'sending']);

        // How many may actually go out now: the campaign's own drip cap, the
        // hourly ceiling, and the warm-up ramp if the domain is still warming.
        $allowance = $campaign->channel === 'whatsapp'
            ? ($this->cap ?? PHP_INT_MAX)
            : $guard->allowanceForRun($this->cap);

        if ($allowance <= 0) {
            // Nothing may be sent this hour — come back rather than burn the run.
            $campaign->forceFill(['status' => 'scheduled', 'scheduled_at' => now()->addHour()])->save();
            return;
        }

        // Drip: only this run's batch (oldest first) instead of the whole audience.
        $query = CrmCampaignRecipient::where('campaign_id', $campaign->id)->where('status', 'pending')->orderBy('id');
        $batch = $allowance === PHP_INT_MAX ? $query->get() : $query->limit($allowance)->get();

        /*
         * Spacing without sleeping.
         *
         * This used to usleep between every send, which holds a queue worker for the whole batch —
         * a hundred recipients at a 500ms gap is a worker parked for the better part of a minute
         * while everything else waits behind it. The batch is sliced small enough to send straight
         * through, and the gap goes between re-dispatches of this job instead. Same spacing from
         * the receiving domain's point of view; nothing blocked from ours.
         */
        $gapMs = $campaign->channel === 'whatsapp' ? 0 : $guard->minGapMs();
        $sliceSize = $gapMs > 0 ? max(1, (int) ceil(self::SLICE_SECONDS * 1000 / $gapMs)) : $batch->count();
        $slice = $batch->take($sliceSize);

        foreach ($slice as $r) {
            // Honour the suppression list (unsubscribes / bounces).
            $address = $campaign->channel === 'whatsapp' ? $r->phone : $r->email;
            if (CrmSuppression::isSuppressed($campaign->channel, $address)) {
                $r->update(['status' => 'unsubscribed']);
                continue;
            }

            try {
                $campaign->channel === 'whatsapp'
                    ? $this->sendWhatsApp($whatsapp, $campaign, $r)
                    : $this->sendEmail($campaign, $r);

                $r->update(['status' => 'sent', 'sent_at' => now(), 'error' => null]);
            } catch (\Throwable $e) {
                $r->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 250)]);
                Log::warning('Campaign recipient send failed', ['recipient' => $r->id, 'error' => $e->getMessage()]);
            }
        }

        $remaining = $campaign->recipients()->where('status', 'pending')->count();

        // More of this hour's allowance still to go: come back after the spacing this slice owes,
        // rather than holding the worker through it.
        if ($remaining > 0 && $slice->count() < $batch->count()) {
            self::dispatch($this->campaignId, $this->cap)
                ->delay(now()->addMilliseconds($slice->count() * $gapMs));

            $campaign->forceFill([
                'status'       => 'sending',
                'sent_count'   => $campaign->recipients()->whereNotIn('status', ['pending', 'failed'])->count(),
                'failed_count' => $campaign->recipients()->where('status', 'failed')->count(),
            ])->save();

            return;
        }

        // Anyone still pending? With a daily cap, pause and resume tomorrow;
        // otherwise the audience is exhausted and the campaign is done.
        $more = $this->cap !== null && $remaining > 0;

        $campaign->forceFill([
            'status'       => $more ? 'scheduled' : 'sent',
            'scheduled_at' => $more ? now()->addDay() : $campaign->scheduled_at,
            'sent_at'      => $more ? $campaign->sent_at : now(),
            'sent_count'   => $campaign->recipients()->whereNotIn('status', ['pending', 'failed'])->count(),
            'failed_count' => $campaign->recipients()->where('status', 'failed')->count(),
        ])->save();
    }

    private function sendEmail(CrmCampaign $campaign, CrmCampaignRecipient $r): void
    {
        if (! $r->email) {
            throw new \RuntimeException('No email address.');
        }

        /*
         * Personalise before tracking.
         *
         * The bulk campaign never merged at all — it sent the campaign body verbatim, so a letter
         * written with [Name] reached every recipient reading "Good Day [Name]". Merging has to
         * happen before the tracking wrapper, or the rewritten links get rewritten again.
         */
        $merge = app(MergeFields::class);
        // [Company] in a template means the recipient's company. The contact record holds it where
        // one is linked; a bare email list has only a name, and an unresolved [Company] is left
        // visible rather than blanked.
        $data = $merge->forRecipient($r->name, $this->companyOf($r), $r->email);

        $body = $merge->tidyGreeting($merge->render((string) ($campaign->content ?? ''), $data));
        $html = $this->trackedHtml($body, $r->token);
        $subject = $merge->render((string) ($campaign->subject ?: $campaign->name), $data);

        // Prefer the company's configured email provider (Integrations hub); fall
        // back to the campaign's own from-address on the app default mailer.
        $crmMail = app(CrmMailService::class);
        $from    = $crmMail->from();

        $unsubscribeUrl = route('crm.unsubscribe', ['token' => $r->token]);

        $crmMail->marketingMailer()->html($html, function ($m) use ($campaign, $r, $from, $unsubscribeUrl, $subject) {
            $m->to($r->email, $r->name ?: null)->subject($subject);
            if ($campaign->from_email) {
                $m->from($campaign->from_email, $campaign->from_name ?: null);
            } elseif ($from) {
                $m->from($from['address'], $from['name']);
            }

            /*
             * One-click unsubscribe. Gmail and Yahoo require these headers from
             * bulk senders, and mail clients surface them as a native
             * "Unsubscribe" button — which recipients use instead of the spam
             * button. That difference is what protects the domain: an
             * unsubscribe costs nothing, a spam report costs reputation.
             *
             * List-Unsubscribe-Post is what makes it one-click: the client POSTs
             * to the URL directly rather than opening a browser.
             */
            $headers = $m->getHeaders();
            $headers->addTextHeader('List-Unsubscribe', '<' . $unsubscribeUrl . '>');
            $headers->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
            // Groups this campaign for clients that offer per-list muting.
            $headers->addTextHeader('List-Id', 'campaign-' . $campaign->id . '.' . ($this->sendingDomain() ?: 'localhost'));
            // Marks the message as bulk so it is never mistaken for transactional.
            $headers->addTextHeader('Precedence', 'bulk');
            $headers->addTextHeader('Auto-Submitted', 'auto-generated');
        });
    }

    private function sendWhatsApp(CrmWhatsAppService $whatsapp, CrmCampaign $campaign, CrmCampaignRecipient $r): void
    {
        if (! $r->phone) {
            throw new \RuntimeException('No phone number.');
        }

        $result = $campaign->wa_template
            ? $whatsapp->sendTemplate($r->phone, $campaign->wa_template)
            : $whatsapp->sendText($r->phone, $campaign->content ?? '');

        if (! ($result['available'] ?? false)) {
            throw new \RuntimeException($result['error'] ?? 'WhatsApp not configured.');
        }
        if (isset($result['error'])) {
            throw new \RuntimeException($result['error']);
        }
    }

    /** The domain campaigns send from, used to build a stable List-Id. */
    private function sendingDomain(): ?string
    {
        return app(CrmSendingGuard::class)->sendingDomain();
    }

    /** Inject the open-tracking pixel and rewrite links through the click tracker. */
    /** The recipient's own company, from whichever record they came in on. */
    private function companyOf(CrmCampaignRecipient $r): ?string
    {
        if ($r->contact_id && \Illuminate\Support\Facades\Schema::hasTable('crm_contacts')) {
            $name = \Illuminate\Support\Facades\DB::table('crm_contacts as c')
                ->leftJoin('crm_accounts as a', 'a.id', '=', 'c.account_id')
                ->where('c.id', $r->contact_id)
                ->value('a.name');
            if (! blank($name)) {
                return $name;
            }
        }

        if ($r->lead_id && \Illuminate\Support\Facades\Schema::hasTable('crm_leads')) {
            return \Illuminate\Support\Facades\DB::table('crm_leads')->where('id', $r->lead_id)->value('company');
        }

        return null;
    }

    private function trackedHtml(string $html, string $token): string
    {
        // Rewrite http(s) links to go through the click tracker.
        $html = preg_replace_callback(
            '/href\s*=\s*"(https?:\/\/[^"]+)"/i',
            function ($m) use ($token) {
                $url = route('crm.track.click', ['token' => $token]) . '?u=' . urlencode($m[1]);
                return 'href="' . $url . '"';
            },
            $html
        ) ?? $html;

        $pixel = '<img src="' . route('crm.track.open', ['token' => $token]) . '" width="1" height="1" alt="" style="display:none" />';

        // CAN-SPAM / POPIA: always include a working unsubscribe link.
        $unsub = '<div style="text-align:center;font-size:12px;color:#888;padding:16px">'
            . '<a href="' . route('crm.unsubscribe', ['token' => $token]) . '" style="color:#888">Unsubscribe</a></div>';

        $footer = $pixel . $unsub;

        return str_contains($html, '</body>')
            ? str_ireplace('</body>', $footer . '</body>', $html)
            : $html . $footer;
    }
}
