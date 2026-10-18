<?php

namespace App\Core\Messaging\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Decides whether an open or a click was a person or a machine.
 *
 * Both bulk-mail modules put a tracking pixel and a wrapped link in every message, and the same
 * mail-security proxies that fetch the unsubscribe link fetch those too — Microsoft Safe Links,
 * Proofpoint, Mimecast, Barracuda, and the image caches at Gmail, Outlook and Yahoo.
 *
 * While the numbers were only reported, this made open rates read high and mean little. It stopped
 * being cosmetic when sequences arrived, because the branch conditions read exactly these fields:
 * a step written for people who never opened is skipped for somebody whose employer's scanner
 * opened it on their behalf, and they silently receive a different message from the one intended.
 *
 * So the question has to be asked before the fields are written. It cannot be answered perfectly —
 * open tracking never can — but it only has to be right enough to branch on, and at the moment
 * nothing asks it at all.
 *
 * This lives in the shared namespace deliberately. The last two rounds fixed the same defect twice
 * because the two modules each had their own copy of the decision.
 */
class EngagementFilter
{
    /** Fragments that identify a scanner, a proxy or a cache rather than a reader. */
    private const AUTOMATED_AGENTS = [
        'googleimageproxy', 'yahoomailproxy', 'outlook-ios-mail', 'microsoft office',
        'safelinks', 'proofpoint', 'mimecast', 'barracuda', 'symantec', 'forcepoint',
        'messagelabs', 'trendmicro', 'sophos', 'cloudmark', 'urldefense',
        'bot', 'crawler', 'spider', 'preview', 'scanner', 'validator', 'monitor',
        'curl/', 'wget/', 'python-requests', 'go-http-client', 'okhttp', 'java/',
        'headlesschrome', 'phantomjs', 'apache-httpclient',
    ];

    /**
     * A message delivered and "read" within this many seconds was not read.
     *
     * A scanner fetches every URL as the message arrives. A person has to receive it, notice it and
     * open it, which does not happen in three seconds — and on the rare occasion somebody is
     * staring at their inbox when it lands, losing that one open costs far less than sending them
     * the wrong follow-up.
     */
    private const HUMAN_DELAY_SECONDS = 3;

    /**
     * @return array{automated: bool, reason: ?string}
     */
    public function inspect(Request $request, ?Carbon $sentAt = null): array
    {
        $agent = Str::lower((string) $request->userAgent());

        if ($agent === '') {
            // Every real mail client and browser sends one. Nothing that does is worth trusting.
            return ['automated' => true, 'reason' => 'No user agent.'];
        }

        foreach (self::AUTOMATED_AGENTS as $fragment) {
            if (str_contains($agent, $fragment)) {
                return ['automated' => true, 'reason' => 'Automated client: ' . $fragment];
            }
        }

        if ($sentAt !== null && $sentAt->diffInSeconds(Carbon::now()) < self::HUMAN_DELAY_SECONDS) {
            return ['automated' => true, 'reason' => 'Opened within seconds of sending.'];
        }

        // Some proxies announce themselves this way rather than in the user agent.
        if ($request->headers->get('X-Moz') === 'prefetch'
            || Str::contains(Str::lower((string) $request->headers->get('Purpose')), 'prefetch')
            || Str::contains(Str::lower((string) $request->headers->get('Sec-Purpose')), 'prefetch')) {
            return ['automated' => true, 'reason' => 'Declared prefetch.'];
        }

        return ['automated' => false, 'reason' => null];
    }

    public function isAutomated(Request $request, ?Carbon $sentAt = null): bool
    {
        return $this->inspect($request, $sentAt)['automated'];
    }

    /**
     * What to say on a screen next to an open rate.
     *
     * Being explicit about what the number excludes is worth more than a figure that looks precise:
     * an editor who knows automated fetches are filtered reads a 22% open rate correctly, and one
     * who does not reads an unfiltered 60% as success.
     */
    public function caveat(int $automated): ?string
    {
        return $automated > 0
            ? "{$automated} automated fetch(es) from mail scanners were excluded."
            : null;
    }
}
