<?php

namespace App\Modules\CRM\Services;

use App\Modules\CRM\Models\CrmCampaign;
use App\Modules\CRM\Models\CrmCampaignRecipient;
use App\Modules\CRM\Models\CrmSetting;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Protects the sending domain's reputation.
 *
 * Mailbox providers judge a domain on volume shape and on how recipients react.
 * A daily cap alone does not do that: 5 000 messages sent in one burst from a
 * cold domain looks like spam, and a campaign that is being marked as junk keeps
 * sending until the audience is exhausted. This decides how many may go out
 * right now (warm-up ramp, daily and hourly ceilings) and whether sending should
 * stop because bounces or complaints have crossed the line.
 *
 * Everything is configurable per tenant through crm_settings; the defaults below
 * are deliberately conservative and follow the Gmail/Yahoo bulk-sender rules.
 */
class CrmSendingGuard
{
    /** Gmail requires spam complaints under 0.3%; pause well before that. */
    public const DEFAULT_COMPLAINT_THRESHOLD = 0.1;   // percent
    public const DEFAULT_BOUNCE_THRESHOLD    = 5.0;   // percent
    public const DEFAULT_HOURLY_CAP          = 500;
    public const DEFAULT_MIN_GAP_MS          = 250;   // spacing between sends
    public const MIN_SAMPLE_FOR_REPUTATION   = 50;    // don't judge on a handful

    /**
     * Warm-up ramp: the most a domain should send on day N of its warm-up.
     * Roughly doubles every couple of days, which is the accepted shape for
     * establishing a new sending domain without tripping rate limits.
     */
    private const WARMUP_LADDER = [
        1 => 50, 2 => 100, 3 => 250, 4 => 500, 5 => 1000, 6 => 2000,
        7 => 4000, 8 => 7500, 9 => 12000, 10 => 20000, 11 => 35000, 12 => 50000,
    ];

    // ── Configuration ─────────────────────────────────────────────────────────

    public function warmupEnabled(): bool
    {
        return CrmSetting::get('sending_warmup_enabled', '0') === '1';
    }

    public function warmupStart(): ?Carbon
    {
        $raw = CrmSetting::get('sending_warmup_start');

        return $raw ? Carbon::parse($raw)->startOfDay() : null;
    }

    public function hourlyCap(): int
    {
        return (int) CrmSetting::get('sending_hourly_cap', (string) self::DEFAULT_HOURLY_CAP);
    }

    public function minGapMs(): int
    {
        return max(0, (int) CrmSetting::get('sending_min_gap_ms', (string) self::DEFAULT_MIN_GAP_MS));
    }

    public function complaintThreshold(): float
    {
        return (float) CrmSetting::get('sending_complaint_threshold', (string) self::DEFAULT_COMPLAINT_THRESHOLD);
    }

    public function bounceThreshold(): float
    {
        return (float) CrmSetting::get('sending_bounce_threshold', (string) self::DEFAULT_BOUNCE_THRESHOLD);
    }

    /** The domain campaigns send from — used by the DNS checker. */
    public function sendingDomain(): ?string
    {
        $explicit = CrmSetting::get('sending_domain');
        if ($explicit) {
            return strtolower(trim($explicit));
        }

        $from = app(CrmMailService::class)->from()['address'] ?? config('mail.from.address');

        return $from && str_contains($from, '@')
            ? strtolower(trim(explode('@', $from)[1]))
            : null;
    }

    // ── Volume ────────────────────────────────────────────────────────────────

    /** Marketing messages actually sent today (all campaigns, this tenant). */
    public function sentToday(): int
    {
        return CrmCampaignRecipient::whereNotNull('sent_at')
            ->whereDate('sent_at', Carbon::today())
            ->count();
    }

    public function sentLastHour(): int
    {
        return CrmCampaignRecipient::whereNotNull('sent_at')
            ->where('sent_at', '>=', Carbon::now()->subHour())
            ->count();
    }

    /** The warm-up ceiling for today, or null when warm-up is off/finished. */
    public function warmupAllowanceToday(): ?int
    {
        if (! $this->warmupEnabled()) {
            return null;
        }

        $start = $this->warmupStart();
        if (! $start) {
            return null;
        }

        $day = $start->diffInDays(Carbon::today()) + 1;

        if ($day < 1) {
            return 0;               // warm-up hasn't started yet
        }
        if ($day > array_key_last(self::WARMUP_LADDER)) {
            return null;            // ramp complete — normal caps apply
        }

        return self::WARMUP_LADDER[$day];
    }

    /**
     * How many messages may be sent in this run, given the campaign's own cap and
     * every ceiling that applies. Returns 0 when sending must wait.
     */
    public function allowanceForRun(?int $campaignCap): int
    {
        $limits = [];

        if ($campaignCap !== null) {
            $limits[] = max(0, $campaignCap);
        }

        $hourly = $this->hourlyCap();
        if ($hourly > 0) {
            $limits[] = max(0, $hourly - $this->sentLastHour());
        }

        $warmup = $this->warmupAllowanceToday();
        if ($warmup !== null) {
            $limits[] = max(0, $warmup - $this->sentToday());
        }

        return empty($limits) ? PHP_INT_MAX : (int) min($limits);
    }

    // ── Reputation ────────────────────────────────────────────────────────────

    /**
     * Bounce and complaint rates over the trailing window, as percentages of
     * messages sent. Judged across the tenant, not one campaign, because that is
     * how mailbox providers see the domain.
     *
     * @return array{sent:int, bounced:int, complained:int, bounce_rate:float, complaint_rate:float, sample:bool}
     */
    public function reputation(int $days = 7): array
    {
        $since = Carbon::now()->subDays($days);

        $sent = CrmCampaignRecipient::whereNotNull('sent_at')->where('sent_at', '>=', $since)->count();
        $bounced = CrmCampaignRecipient::where('status', 'bounced')->where('updated_at', '>=', $since)->count();
        $complained = CrmCampaignRecipient::where('status', 'complained')->where('updated_at', '>=', $since)->count();

        return [
            'sent'           => $sent,
            'bounced'        => $bounced,
            'complained'     => $complained,
            'bounce_rate'    => $sent > 0 ? round($bounced / $sent * 100, 3) : 0.0,
            'complaint_rate' => $sent > 0 ? round($complained / $sent * 100, 3) : 0.0,
            'sample'         => $sent >= self::MIN_SAMPLE_FOR_REPUTATION,
        ];
    }

    /**
     * Should sending stop? Only judged once there is enough volume to be
     * meaningful — a single bounce out of three sends is not a 33% bounce rate
     * in any useful sense.
     *
     * @return string|null  reason to pause, or null to carry on
     */
    public function pauseReason(): ?string
    {
        $r = $this->reputation();

        if (! $r['sample']) {
            return null;
        }

        if ($r['complaint_rate'] >= $this->complaintThreshold()) {
            return "Spam complaints at {$r['complaint_rate']}% (limit {$this->complaintThreshold()}%)";
        }

        if ($r['bounce_rate'] >= $this->bounceThreshold()) {
            return "Bounces at {$r['bounce_rate']}% (limit {$this->bounceThreshold()}%)";
        }

        return null;
    }

    /** Stop a campaign and record why, so it is visible rather than mysterious. */
    public function pauseCampaign(CrmCampaign $campaign, string $reason): void
    {
        $campaign->forceFill([
            'status'       => 'paused',
            'pause_reason' => $reason,
            'paused_at'    => now(),
        ])->save();

        Log::warning('CRM campaign paused to protect sending reputation', [
            'campaign' => $campaign->id,
            'reason'   => $reason,
        ]);
    }
}
