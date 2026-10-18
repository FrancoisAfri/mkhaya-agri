<?php

namespace App\Modules\CRM\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\CRM\Services\EmailEventService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Receives email deliverability event webhooks (bounce / spam complaint /
 * blocked / unsubscribe) from whichever provider is connected and feeds them
 * through EmailEventService. Public (providers can't authenticate as a user) but
 * guarded by a shared secret in CRM_EMAIL_WEBHOOK_SECRET.
 *
 * Opens and clicks are NOT handled here — those are tracked by our own pixel and
 * redirect endpoints (CampaignTrackingController).
 *
 * Until a provider with event webhooks is connected this endpoint simply never
 * receives traffic; no behaviour change for the current SMTP setup.
 */
class EmailEventWebhookController extends Controller
{
    public function __construct(private EmailEventService $events)
    {
    }

    public function handle(Request $request, string $provider): JsonResponse
    {
        $secret = config('services.crm_email_webhook.secret');
        if ($secret && ! hash_equals($secret, (string) ($request->query('secret') ?? $request->header('X-Webhook-Secret')))) {
            return response()->json(['error' => 'unauthorised'], 401);
        }

        $events = match (strtolower($provider)) {
            'mailgun'  => $this->fromMailgun($request),
            'sendgrid' => $this->fromSendGrid($request),
            'postmark' => $this->fromPostmark($request),
            default    => $this->fromGeneric($request),
        };

        $applied = 0;
        foreach ($events as $e) {
            if (($e['email'] ?? '') === '' && ($e['token'] ?? null) === null) {
                continue;
            }
            $applied += $this->events->record($e['event'], $e['email'] ?? '', $e['reason'] ?? null, $e['token'] ?? null) ? 1 : 0;
        }

        return response()->json(['received' => count($events), 'applied' => $applied]);
    }

    /**
     * Normalised passthrough: { event, email, reason?, token? } or { events: [...] }.
     * Use this shape for any provider not covered below.
     */
    private function fromGeneric(Request $request): array
    {
        $payload = $request->input('events', [$request->all()]);

        return collect($payload)->map(fn ($e) => [
            'event'  => strtolower((string) ($e['event'] ?? '')),
            'email'  => (string) ($e['email'] ?? ''),
            'reason' => $e['reason'] ?? null,
            'token'  => $e['token'] ?? null,
        ])->all();
    }

    /** Mailgun: one event under "event-data" (failed/complained/unsubscribed). */
    private function fromMailgun(Request $request): array
    {
        $d = $request->input('event-data', []);
        $type = strtolower((string) ($d['event'] ?? ''));
        $severity = strtolower((string) ($d['severity'] ?? ''));

        $event = match ($type) {
            'failed'       => $severity === 'permanent' ? 'bounced' : 'blocked',
            'complained'   => 'complained',
            'unsubscribed' => 'unsubscribed',
            default        => '',
        };

        return [[
            'event'  => $event,
            'email'  => (string) ($d['recipient'] ?? ''),
            'reason' => $d['delivery-status']['message'] ?? ($d['reason'] ?? null),
            'token'  => $d['user-variables']['token'] ?? null,
        ]];
    }

    /** SendGrid: array of events, each { email, event, reason?, token? }. */
    private function fromSendGrid(Request $request): array
    {
        return collect($request->json()->all())->map(function ($e) {
            $event = match (strtolower((string) ($e['event'] ?? ''))) {
                'bounce'              => 'bounced',
                'dropped', 'blocked'  => 'blocked',
                'spamreport'          => 'complained',
                'unsubscribe', 'group_unsubscribe' => 'unsubscribed',
                default               => '',
            };

            return [
                'event'  => $event,
                'email'  => (string) ($e['email'] ?? ''),
                'reason' => $e['reason'] ?? ($e['type'] ?? null),
                'token'  => $e['token'] ?? null,
            ];
        })->all();
    }

    /** Postmark: one record with RecordType Bounce/SpamComplaint/SubscriptionChange. */
    private function fromPostmark(Request $request): array
    {
        $type = (string) $request->input('RecordType', '');
        $bounceType = strtolower((string) $request->input('Type', ''));

        $event = match ($type) {
            'Bounce'             => in_array($bounceType, ['spamcomplaint', 'spamnotification'], true) ? 'complained' : 'bounced',
            'SpamComplaint'      => 'complained',
            'SubscriptionChange' => $request->boolean('SuppressSending') ? 'unsubscribed' : '',
            default              => '',
        };

        return [[
            'event'  => $event,
            'email'  => (string) $request->input('Email', ''),
            'reason' => $request->input('Description') ?: $request->input('Details'),
            'token'  => $request->input('Metadata.token'),
        ]];
    }
}
