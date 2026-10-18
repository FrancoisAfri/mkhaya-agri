<?php

namespace App\Modules\Operations\Sheq\Http\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Operations\Sheq\Access\AccessDecisionService;
use App\Modules\Operations\Sheq\Http\Api\ProblemDetails;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The inbound path: an access controller posts an event and we answer whether to open.
 *
 * For the very common case of an on-premise controller behind a customer's firewall, which we
 * cannot poll and which cannot hold a user session.
 *
 * The response is deliberately tiny and fast. A turnstile is waiting with somebody standing in front
 * of it; a slow answer is a queue, and a queue is how a site decides to switch the integration off.
 */
class GateWebhookController extends Controller
{
    public function __construct(private readonly AccessDecisionService $access) {}

    public function handle(Request $request, string $uuid): JsonResponse
    {
        $integration = DB::table('sheq_integrations')
            ->where('uuid', $uuid)->where('active', true)->whereNull('deleted_at')
            ->first(['id', 'company_id', 'site_id', 'api_key', 'enforce_access',
                'additional_config']);

        if ($integration === null) {
            return ProblemDetails::make(404, 'unknown-integration', 'No such gate',
                'This webhook URL does not match a configured integration.');
        }

        if (! $this->authenticates($request, $integration)) {
            return ProblemDetails::make(401, 'unauthenticated', 'The key was not accepted',
                'Send the integration key as a bearer token or an X-Api-Key header.');
        }

        $config = json_decode((string) $integration->additional_config, true) ?: [];
        $field = $config['identifier_field'] ?? 'identifier';

        $identifier = (string) ($request->input($field) ?? $request->input('identifier') ?? '');

        if ($identifier === '') {
            return ProblemDetails::make(422, 'missing-identifier', 'No identifier in the payload',
                "Expected a '{$field}' field holding the card or ID number.");
        }

        $decision = $this->access->decideAndRecord((int) $integration->company_id, $identifier, [
            'integration_id' => $integration->id,
            'site_id' => $integration->site_id,
            'direction' => strtoupper((string) $request->input('direction', 'ENTRY')),
            /*
             * What the person is here to do, where the reader knows (CON-15). A boom at the
             * entrance to a tank farm knows; a perimeter turnstile does not, and sending nothing
             * is the honest answer rather than guessing one.
             */
            'activity' => $request->input('activity')
                ? strtoupper(trim((string) $request->input('activity'))) : null,
            'device_ref' => $request->input('device'),
            'enforce' => (bool) $integration->enforce_access,
        ]);

        return response()->json([
            // The only field the hardware must understand.
            'allow' => $decision['open_gate'],
            'name' => $decision['person_name'],
            // Shown on the guard's screen. "Access denied" starts an argument; naming the lapsed
            // document ends it.
            'message' => $decision['allowed']
                ? 'Access granted'
                : implode(' ', $decision['reasons']),
            'monitor_mode' => ! (bool) $integration->enforce_access,
        ]);
    }

    /**
     * The integration's own key, sent either way round.
     *
     * Vendors are inconsistent about this and most cannot be reconfigured, so both are accepted
     * rather than forcing a customer to choose hardware that matches our preference.
     */
    private function authenticates(Request $request, object $integration): bool
    {
        $presented = $request->bearerToken() ?: $request->header('X-Api-Key');

        if ($presented === null || $integration->api_key === null) {
            return false;
        }

        try {
            $expected = decrypt($integration->api_key);
        } catch (\Throwable) {
            $expected = $integration->api_key;
        }

        return hash_equals((string) $expected, (string) $presented);
    }
}
