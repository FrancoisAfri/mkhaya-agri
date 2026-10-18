<?php

namespace App\Modules\Operations\Sheq\Http\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Operations\Sheq\Events\EventRegistry;
use App\Modules\Operations\Sheq\Http\Api\ProblemDetails;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Webhook subscription management (§8.2, SHEQ-API-5).
 *
 * Self-service because the alternative does not scale past the first customer: an integrator who
 * must email support to change a subscription URL will instead poll the read API every minute
 * forever, and both sides pay for that indefinitely.
 *
 * The security decision that shapes this controller: **the signing secret is shown exactly once**,
 * at creation, and only its hint thereafter. Storing it retrievably would mean any token with
 * webhook scope could read the secret that authenticates our deliveries — and the whole point of
 * signing is that the receiver can prove a payload came from us. A secret anyone can fetch proves
 * nothing.
 */
class WebhookApiController extends Controller
{
    public function __construct(
        private readonly EventRegistry $registry,
        private readonly CurrentCompany $current,
    ) {}

    /** `GET /api/v1/sheq/webhooks` */
    public function index(Request $request): JsonResponse
    {
        if (! $request->user()?->can('sheq.configure')) {
            return $this->needsConfigure();
        }

        $rows = DB::table('sheq_webhooks')
            ->where('company_id', $this->companyId())->whereNull('deleted_at')
            ->orderBy('name')
            ->get(['uuid', 'name', 'url', 'secret_hint', 'event_types', 'is_active',
                'consecutive_failures', 'disabled_at', 'disabled_reason', 'last_delivered_at',
                'created_at']);

        return response()->json(['data' => $rows->map(fn ($r) => [
            'uuid' => $r->uuid,
            'name' => $r->name,
            'url' => $r->url,
            // Never the secret itself. The hint is enough to tell two subscriptions apart.
            'secret_hint' => $r->secret_hint,
            'event_types' => json_decode((string) $r->event_types, true) ?: [],
            'is_active' => (bool) $r->is_active,
            'consecutive_failures' => (int) $r->consecutive_failures,
            'disabled_at' => $r->disabled_at,
            'disabled_reason' => $r->disabled_reason,
            'last_delivered_at' => $r->last_delivered_at,
            'created_at' => $r->created_at,
        ])->values()]);
    }

    /** `POST /api/v1/sheq/webhooks` */
    public function store(Request $request): JsonResponse
    {
        if (! $request->user()?->can('sheq.configure')) {
            return $this->needsConfigure();
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'url' => ['required', 'url', 'max:500'],
            'event_types' => ['required', 'array', 'min:1'],
            'event_types.*' => ['string'],
        ]);

        if (! str_starts_with($validated['url'], 'https://')) {
            return ProblemDetails::refused('insecure-webhook-url',
                'Webhook URLs must be https. SHEQ payloads carry incident and health information '
                .'about identifiable people, and plaintext delivery of that is a POPIA problem '
                .'regardless of what the signature proves.');
        }

        $unknown = array_values(array_diff($validated['event_types'], $this->registry->types()));

        if ($unknown !== []) {
            // Failing loudly beats accepting a subscription that will never fire — a typo'd event
            // name would otherwise present as "the webhook is broken" weeks later, with nothing in
            // any log to explain it.
            return ProblemDetails::refused('unknown-event-type',
                'These event types are not published by SHEQ: '.implode(', ', $unknown),
                ['unknown' => $unknown, 'available' => $this->registry->types()]);
        }

        // Shown once, here, and never again.
        $secret = 'whsec_'.Str::random(48);
        $uuid = (string) Str::uuid();

        DB::table('sheq_webhooks')->insert([
            'uuid' => $uuid,
            'company_id' => $this->companyId(),
            'name' => $validated['name'],
            'url' => $validated['url'],
            'secret' => encrypt($secret),
            'secret_hint' => substr($secret, 0, 12).'…',
            'event_types' => json_encode(array_values($validated['event_types'])),
            'is_active' => true,
            'created_by' => $request->user()?->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return response()->json([
            'data' => [
                'uuid' => $uuid,
                'name' => $validated['name'],
                'url' => $validated['url'],
                'event_types' => array_values($validated['event_types']),
                'secret' => $secret,
            ],
            'warning' => 'Store this secret now. It cannot be retrieved again — only rotated.',
            'signature' => [
                'header' => 'X-Sheq-Signature',
                'timestamp_header' => 'X-Sheq-Timestamp',
                'algorithm' => 'HMAC-SHA256',
                // Spelling out that it is the raw body saves every integrator the same afternoon:
                // verifying against re-serialised JSON fails intermittently, because key order and
                // unicode escaping differ between their parser and ours.
                'signed_payload' => 'The timestamp, a full stop, then the raw request body — '
                    .'verify against the bytes as received, not against re-serialised JSON.',
            ],
        ], 201);
    }

    /** `PATCH /api/v1/sheq/webhooks/{uuid}` */
    public function update(Request $request, string $uuid): JsonResponse
    {
        if (! $request->user()?->can('sheq.configure')) {
            return $this->needsConfigure();
        }

        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:120'],
            'url' => ['nullable', 'url', 'max:500'],
            'event_types' => ['nullable', 'array', 'min:1'],
            'event_types.*' => ['string'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $webhook = $this->find($uuid);

        if ($webhook === null) {
            return ProblemDetails::make(404, 'not-found', 'No such webhook',
                'No webhook with that identifier exists in your company.');
        }

        if (isset($validated['url']) && ! str_starts_with($validated['url'], 'https://')) {
            return ProblemDetails::refused('insecure-webhook-url', 'Webhook URLs must be https.');
        }

        if (isset($validated['event_types'])) {
            $unknown = array_values(array_diff($validated['event_types'], $this->registry->types()));

            if ($unknown !== []) {
                return ProblemDetails::refused('unknown-event-type',
                    'These event types are not published by SHEQ: '.implode(', ', $unknown),
                    ['unknown' => $unknown]);
            }
        }

        $update = array_filter([
            'name' => $validated['name'] ?? null,
            'url' => $validated['url'] ?? null,
            'event_types' => isset($validated['event_types'])
                ? json_encode(array_values($validated['event_types'])) : null,
        ], fn ($v) => $v !== null);

        if (array_key_exists('is_active', $validated) && $validated['is_active'] !== null) {
            $update['is_active'] = (bool) $validated['is_active'];

            // Re-enabling clears the failure count and the reason. Leaving them would mean the next
            // single failure trips the auto-disable threshold immediately, and the subscriber would
            // never get a fair run after fixing their endpoint.
            if ($validated['is_active']) {
                $update['consecutive_failures'] = 0;
                $update['disabled_at'] = null;
                $update['disabled_reason'] = null;
            }
        }

        $update['updated_at'] = now();

        DB::table('sheq_webhooks')->where('id', $webhook->id)->update($update);

        return response()->json(['data' => ['uuid' => $uuid, 'updated' => true]]);
    }

    /**
     * `POST /api/v1/sheq/webhooks/{uuid}/rotate`
     *
     * Rotation rather than retrieval is the only safe answer to "I lost the secret". It also gives
     * a subscriber a real response to suspected compromise, which is the situation where waiting on
     * a support ticket is worst.
     */
    public function rotate(Request $request, string $uuid): JsonResponse
    {
        if (! $request->user()?->can('sheq.configure')) {
            return $this->needsConfigure();
        }

        $webhook = $this->find($uuid);

        if ($webhook === null) {
            return ProblemDetails::make(404, 'not-found', 'No such webhook',
                'No webhook with that identifier exists in your company.');
        }

        $secret = 'whsec_'.Str::random(48);

        DB::table('sheq_webhooks')->where('id', $webhook->id)->update([
            'secret' => encrypt($secret),
            'secret_hint' => substr($secret, 0, 12).'…',
            'updated_at' => now(),
        ]);

        return response()->json([
            'data' => ['uuid' => $uuid, 'secret' => $secret],
            'warning' => 'The previous secret stopped working immediately. Deliveries signed with '
                .'it will fail verification until you deploy this one.',
        ]);
    }

    /** `DELETE /api/v1/sheq/webhooks/{uuid}` */
    public function destroy(Request $request, string $uuid): JsonResponse
    {
        if (! $request->user()?->can('sheq.configure')) {
            return $this->needsConfigure();
        }

        $webhook = $this->find($uuid);

        if ($webhook === null) {
            return ProblemDetails::make(404, 'not-found', 'No such webhook',
                'No webhook with that identifier exists in your company.');
        }

        // Soft delete: which endpoints received which events is part of the audit trail for who
        // knew what and when, and a hard delete would erase it.
        DB::table('sheq_webhooks')->where('id', $webhook->id)
            ->update(['deleted_at' => now(), 'is_active' => false, 'updated_at' => now()]);

        return response()->json(['data' => ['uuid' => $uuid, 'deleted' => true]]);
    }

    /** The events a subscription can ask for, with what each one means. */
    public function events(): JsonResponse
    {
        return response()->json(['data' => array_map(fn ($type, $schema) => [
            'type' => $type,
            'version' => $schema['version'],
            'describes' => $schema['describes'],
            'payload_keys' => $schema['required'],
        ], array_keys($this->registry->all()), $this->registry->all())]);
    }

    private function find(string $uuid): ?object
    {
        return DB::table('sheq_webhooks')
            ->where('company_id', $this->companyId())->where('uuid', $uuid)
            ->whereNull('deleted_at')->first(['id']);
    }

    private function needsConfigure(): JsonResponse
    {
        return ProblemDetails::make(403, 'entitlement-required', 'You do not have this permission',
            'Managing webhooks requires the `sheq.configure` permission.',
            ['required_permission' => 'sheq.configure']);
    }

    private function companyId(): int
    {
        return (int) $this->current->id();
    }
}
