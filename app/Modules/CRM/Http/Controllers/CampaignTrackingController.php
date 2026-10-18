<?php

namespace App\Modules\CRM\Http\Controllers;

use App\Core\Messaging\Services\EngagementFilter;
use App\Core\Messaging\Services\UnsubscribeGate;
use App\Http\Controllers\Controller;
use App\Modules\CRM\Models\CrmCampaign;
use App\Modules\CRM\Models\CrmCampaignRecipient;
use App\Modules\CRM\Models\CrmContact;
use App\Modules\CRM\Models\CrmLead;
use App\Modules\CRM\Models\CrmSequenceSend;
use App\Modules\CRM\Models\CrmSuppression;
use App\Modules\CRM\Services\CrmEventBus;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Public (unauthenticated) email open / click tracking endpoints — these are
 * loaded by the recipient's mail client and browser, so they must not require
 * auth or the module middleware.
 */
class CampaignTrackingController extends Controller
{
    /** 1×1 transparent GIF returned for the open-tracking pixel. */
    private const PIXEL = "\x47\x49\x46\x38\x39\x61\x01\x00\x01\x00\x80\x00\x00\x00\x00\x00\x00\x00\x00\x21\xf9\x04\x01\x00\x00\x00\x00\x2c\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02\x44\x01\x00\x3b";

    public function open(Request $request, string $token): Response
    {
        $recipient = CrmCampaignRecipient::where('token', $token)->first();

        if ($recipient) {
            // The same shared filter the outreach module uses. Written once, asked twice — the two
            // copies of this decision are what let the same defect survive a fix once already.
            if (app(EngagementFilter::class)->isAutomated($request, $recipient->sent_at)) {
                CrmCampaign::where('id', $recipient->campaign_id)->increment('automated_opens');

                return response(self::PIXEL, 200, [
                    'Content-Type'  => 'image/gif',
                    'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
                    'Pragma'        => 'no-cache',
                ]);
            }

            $first = $recipient->opened_at === null;
            $recipient->forceFill([
                'opened_at'  => $recipient->opened_at ?? now(),
                'open_count' => $recipient->open_count + 1,
                'status'     => in_array($recipient->status, ['clicked'], true) ? $recipient->status : 'opened',
            ])->save();

            if ($first) {
                CrmCampaign::where('id', $recipient->campaign_id)->increment('opened_count');
                $this->fireEngagement($recipient, 'email.opened');
            }
        } else {
            $this->trackSequence($token, 'open');
        }

        return response(self::PIXEL, 200, [
            'Content-Type'  => 'image/gif',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
            'Pragma'        => 'no-cache',
        ]);
    }

    public function click(Request $request, string $token): SymfonyResponse
    {
        $url = $request->query('u');

        $recipient = CrmCampaignRecipient::where('token', $token)->first();
        if ($recipient && app(EngagementFilter::class)->isAutomated($request, $recipient->sent_at)) {
            CrmCampaign::where('id', $recipient->campaign_id)->increment('automated_clicks');
            $recipient = null;   // counted, but it does not become engagement
        }
        if ($recipient) {
            $firstClick = $recipient->click_count === 0;
            $firstOpen  = $recipient->opened_at === null; // a click implies an open

            $recipient->forceFill([
                'click_count'     => $recipient->click_count + 1,
                'last_clicked_at' => now(),
                'status'          => 'clicked',
                'opened_at'       => $recipient->opened_at ?? now(),
            ])->save();

            DB::transaction(function () use ($recipient, $firstClick, $firstOpen) {
                if ($firstClick) {
                    CrmCampaign::where('id', $recipient->campaign_id)->increment('clicked_count');
                }
                if ($firstOpen) {
                    CrmCampaign::where('id', $recipient->campaign_id)->increment('opened_count');
                }
            });

            if ($firstClick) {
                $this->fireEngagement($recipient, 'email.clicked');
            }
        } else {
            $this->trackSequence($token, 'click');
        }

        // Only redirect to safe absolute http(s) URLs.
        if (is_string($url) && preg_match('#^https?://#i', $url)) {
            return redirect()->away($url);
        }

        return redirect()->away(config('app.url'));
    }

    /**
     * A GET only asks. Only a POST unsubscribes.
     *
     * This is the CRM's own copy of the outreach tracking, and it had the same defect: unsubscribing
     * on a GET, while corporate mail security fetches every URL in an inbound message to scan it
     * before the recipient ever sees the mail. A scanner sweep could suppress a whole campaign's
     * recipients without anybody clicking. Same fix, same shared gate.
     */
    public function unsubscribe(Request $request, string $token): Response
    {
        $gate = app(UnsubscribeGate::class);
        $recipient = CrmCampaignRecipient::with('campaign')->where('token', $token)->first();
        $send = $recipient ? null : CrmSequenceSend::with('enrollment')->where('token', $token)->first();

        if (! $gate->mayProceed($request)) {
            $channel = $recipient?->campaign?->channel ?? 'email';
            $address = $recipient
                ? ($channel === 'whatsapp' ? $recipient->phone : $recipient->email)
                : ($send?->enrollment?->email ?? '');

            return $gate->confirmationPage(route('crm.unsubscribe', $token), (string) $address);
        }

        if ($recipient) {
            $channel = $recipient->campaign?->channel ?? 'email';
            $address = $channel === 'whatsapp' ? $recipient->phone : $recipient->email;

            if ($address) {
                CrmSuppression::suppress($channel, $address, 'unsubscribed', $recipient->campaign_id);
            }
            // Already guarded here, and left that way — a second submission counts once.
            if ($recipient->status !== 'unsubscribed') {
                $recipient->update(['status' => 'unsubscribed', 'last_event_at' => now()]);
                CrmCampaign::where('id', $recipient->campaign_id)->increment('unsubscribed_count');
            }
        } elseif ($send && $send->enrollment) {
            // A sequence email rather than a campaign: suppress the address and stop the cadence.
            $enrollment = $send->enrollment;
            if ($enrollment->email) {
                CrmSuppression::suppress('email', $enrollment->email, 'unsubscribed');
            }
            if (! in_array($enrollment->status, ['unsubscribed', 'stopped'], true)) {
                $enrollment->update(['status' => 'unsubscribed', 'completed_at' => now(), 'next_step_at' => null]);
            }
        }

        return $gate->donePage();
    }

    /**
     * Fire an engagement automation event on the recipient's linked CRM record
     * (contact preferred, else lead). Runs in a public/unauthenticated context.
     */
    private function fireEngagement(CrmCampaignRecipient $recipient, string $event): void
    {
        $entity = $recipient->contact_id
            ? CrmContact::find($recipient->contact_id)
            : ($recipient->lead_id ? CrmLead::find($recipient->lead_id) : null);

        if ($entity) {
            app(CrmEventBus::class)->fire($event, $entity, ['campaign_id' => $recipient->campaign_id]);
        }
    }

    /**
     * Open/click tracking for a sequence send (same pixel/click routes). Bumps
     * the send counters and fires the engagement automation on first event.
     */
    private function trackSequence(string $token, string $type): void
    {
        $send = CrmSequenceSend::with('enrollment')->where('token', $token)->first();
        if (! $send) {
            return;
        }

        if ($type === 'open') {
            $first = $send->opened_at === null;
            $send->forceFill(['opened_at' => $send->opened_at ?? now(), 'open_count' => $send->open_count + 1])->save();
            $event = 'email.opened';
        } else {
            $first = $send->click_count === 0;
            $send->forceFill([
                'clicked_at' => $send->clicked_at ?? now(), 'click_count' => $send->click_count + 1,
                'opened_at'  => $send->opened_at ?? now(),
            ])->save();
            $event = 'email.clicked';
        }

        if (! $first) {
            return;
        }

        $e = $send->enrollment;
        $entity = $e?->contact_id ? CrmContact::find($e->contact_id) : ($e?->lead_id ? CrmLead::find($e->lead_id) : null);
        if ($entity) {
            app(CrmEventBus::class)->fire($event, $entity);
        }
    }
}
