<?php

namespace App\Modules\CRM\Services;

use App\Modules\CRM\Models\CrmCampaign;
use App\Modules\CRM\Models\CrmCampaignRecipient;
use App\Modules\CRM\Models\CrmSuppression;
use Illuminate\Support\Facades\DB;

/**
 * Applies a normalised email deliverability event (bounce / spam complaint /
 * blocked / unsubscribe) to a campaign recipient, the campaign's rollup counts
 * and the suppression list.
 *
 * Provider webhooks differ wildly, so the provider-specific parsing lives in the
 * webhook controller; this service only understands our own normalised events
 * and is therefore reusable regardless of which provider is connected. Opens and
 * clicks are tracked by our pixel/redirect endpoints, not here.
 */
class EmailEventService
{
    /** Provider event → our recipient status. */
    private const STATUS_MAP = [
        'bounced'    => 'bounced',
        'complained' => 'complained',
        'blocked'    => 'blocked',
        'dropped'    => 'blocked',
        'failed'     => 'blocked',
    ];

    /** Recipient status → campaign counter column to bump (once). */
    private const COUNTER = [
        'bounced'      => 'bounced_count',
        'complained'   => 'complained_count',
        'blocked'      => 'failed_count',
        'unsubscribed' => 'unsubscribed_count',
    ];

    /** Statuses that should also land the address on the suppression list. */
    private const SUPPRESS_REASON = [
        'bounced'      => 'bounced',
        'complained'   => 'complained',
        'unsubscribed' => 'unsubscribed',
    ];

    /**
     * @param  string       $event  normalised: bounced|complained|blocked|dropped|failed|unsubscribed
     * @param  string|null  $token  recipient token if the provider echoed it back (most precise match)
     * @return bool  whether a recipient was matched and updated
     */
    public function record(string $event, string $email, ?string $reason = null, ?string $token = null): bool
    {
        $status = $event === 'unsubscribed' ? 'unsubscribed' : (self::STATUS_MAP[$event] ?? null);
        if ($status === null) {
            return false; // delivered/opened/clicked etc. — nothing to do here
        }

        // Mirror bounce/complaint onto any active sequence enrollment for the
        // same address so the cadence stops (and the address is suppressed).
        if (in_array($status, ['bounced', 'complained'], true) && trim($email) !== '') {
            \App\Modules\CRM\Models\CrmSequenceEnrollment::where('status', 'active')
                ->whereRaw('LOWER(email) = ?', [mb_strtolower(trim($email))])
                ->update(['status' => 'bounced', 'completed_at' => now(), 'next_step_at' => null]);
        }

        $recipient = $token
            ? CrmCampaignRecipient::where('token', $token)->first()
            : CrmCampaignRecipient::whereRaw('LOWER(email) = ?', [mb_strtolower(trim($email))])
                ->orderByDesc('id')->first();

        if (! $recipient) {
            // Still suppress by address so we stop mailing a known-bad recipient.
            if (isset(self::SUPPRESS_REASON[$status]) && $email !== '') {
                CrmSuppression::suppress('email', $email, self::SUPPRESS_REASON[$status]);
            }
            return false;
        }

        $alreadyInState = $recipient->status === $status;

        DB::transaction(function () use ($recipient, $status, $reason, $alreadyInState) {
            $recipient->forceFill([
                'status'        => $status,
                'bounce_reason' => $reason ?: $recipient->bounce_reason,
                'last_event_at' => now(),
            ])->save();

            if (! $alreadyInState && isset(self::COUNTER[$status])) {
                CrmCampaign::where('id', $recipient->campaign_id)->increment(self::COUNTER[$status]);
            }
        });

        if (isset(self::SUPPRESS_REASON[$status]) && $recipient->email) {
            CrmSuppression::suppress('email', $recipient->email, self::SUPPRESS_REASON[$status], $recipient->campaign_id);
        }

        return true;
    }
}
