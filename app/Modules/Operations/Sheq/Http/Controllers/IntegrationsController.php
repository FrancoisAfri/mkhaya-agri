<?php

namespace App\Modules\Operations\Sheq\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\Sheq\Access\AccessDecisionService;
use App\Modules\Operations\Sheq\Access\Integrations\AccessIntegrationManager;
use App\Modules\Operations\Sheq\Support\ResolvesCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Configuring the gate: credentials in, connection tested, events flowing.
 *
 * The screen the whole access-control layer exists for. An administrator picks their vendor, pastes
 * an endpoint and a key, presses Test, and sees either a green line or the reason it failed — no
 * developer, no per-customer code.
 *
 * The enforcement toggle is deliberately the last thing on the page and starts off. Switching a live
 * gate to enforcement before the data is clean strands a shift outside in the dark, so the impact
 * report sits directly above it: *this is who would have been refused in the last 30 days, and why.*
 * Nobody should have to discover that by stranding people.
 */
class IntegrationsController extends Controller
{
    use ResolvesCompany;

    public function __construct(
        private readonly AccessIntegrationManager $manager,
        private readonly AccessDecisionService $access,
    ) {}

    public function index(Request $request): Response
    {
        $companyId = $this->companyId($request);

        return Inertia::render('Modules/Sheq/Integrations/Index', [
            'noCompany' => $companyId === null,
            'canManage' => (bool) $request->user()?->can('sheq.configure'),
            'catalogue' => $this->manager->catalogue(),
            'integrations' => $companyId === null ? [] : $this->list($companyId),
            'sites' => $companyId === null ? [] : DB::table('sheq_sites')
                ->where('company_id', $companyId)->where('is_active', true)
                ->orderBy('name')->get(['id', 'name'])->map(fn ($s) => (array) $s)->all(),
            'impact' => $companyId === null ? null
                : $this->access->enforcementImpact($companyId),
            'recentEvents' => $companyId === null ? [] : $this->recentEvents($companyId),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $companyId = $this->requireCompany($request);
        abort_unless($request->user()?->can('sheq.configure'), 403,
            'ENTITLEMENT_REQUIRED: sheq.configure');

        $validated = $request->validate([
            'provider' => ['required', Rule::in($this->manager->providers())],
            'label' => ['required', 'string', 'max:120'],
            'integration_type' => ['required', Rule::in(['ACCESS_CONTROL', 'CLOCK_IN',
                'BIOMETRIC', 'VISITOR_KIOSK'])],
            'api_endpoint' => ['nullable', 'string', 'max:255'],
            'api_key' => ['nullable', 'string', 'max:500'],
            'api_secret' => ['nullable', 'string', 'max:500'],
            'site_id' => ['nullable', 'integer'],
            'additional_config' => ['nullable', 'array'],
        ]);

        $uuid = (string) Str::uuid();

        DB::table('sheq_integrations')->insert([
            'uuid' => $uuid,
            'company_id' => $companyId,
            'provider' => $validated['provider'],
            'integration_type' => $validated['integration_type'],
            'label' => $validated['label'],
            'api_endpoint' => $validated['api_endpoint'] ?? null,
            // Encrypted: these credentials open a door, so a database read is a physical security
            // incident, not merely a data one.
            'api_key' => empty($validated['api_key']) ? null : encrypt($validated['api_key']),
            'api_secret' => empty($validated['api_secret']) ? null : encrypt($validated['api_secret']),
            'additional_config' => json_encode($validated['additional_config'] ?? []),
            'site_id' => $validated['site_id'] ?? null,
            // Never on by default. See the class note.
            'enforce_access' => false,
            'active' => true,
            'created_by' => $request->user()?->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return back()->with('success',
            'Connection saved in monitor mode. Test it, watch what it would refuse, then enforce.');
    }

    public function test(Request $request, string $uuid): RedirectResponse
    {
        $companyId = $this->requireCompany($request);
        abort_unless($request->user()?->can('sheq.configure'), 403,
            'ENTITLEMENT_REQUIRED: sheq.configure');

        $result = $this->manager->testOne($companyId, $uuid);

        return back()->with($result['ok'] ? 'success' : 'warning', $result['message']);
    }

    public function sync(Request $request, string $uuid): RedirectResponse
    {
        $companyId = $this->requireCompany($request);

        $result = $this->manager->syncOne($companyId, $uuid);

        return back()->with($result['ok'] ? 'success' : 'warning',
            $result['message'] ?? 'Sync finished.');
    }

    /**
     * Turn enforcement on or off.
     *
     * Separate from the general update, and confirmed on the screen, because this is the setting
     * that decides whether somebody stands outside in the dark.
     */
    public function setEnforcement(Request $request, string $uuid): RedirectResponse
    {
        $companyId = $this->requireCompany($request);
        abort_unless($request->user()?->can('sheq.configure'), 403,
            'ENTITLEMENT_REQUIRED: sheq.configure');

        $validated = $request->validate(['enforce' => ['required', 'boolean']]);

        DB::table('sheq_integrations')->where('company_id', $companyId)->where('uuid', $uuid)
            ->update(['enforce_access' => $validated['enforce'], 'updated_at' => now()]);

        return back()->with('success', $validated['enforce']
            ? 'Enforcement on. Refusals will now stop people at the gate.'
            : 'Back to monitor mode. Refusals are recorded but nobody is stopped.');
    }

    /** @return list<array<string,mixed>> */
    private function list(int $companyId): array
    {
        return DB::table('sheq_integrations as i')
            ->leftJoin('sheq_sites as s', 's.id', '=', 'i.site_id')
            ->where('i.company_id', $companyId)->whereNull('i.deleted_at')
            ->orderBy('i.label')
            ->get(['i.uuid', 'i.provider', 'i.label', 'i.integration_type', 'i.api_endpoint',
                'i.enforce_access', 'i.active', 'i.last_sync_status', 'i.last_sync_message',
                'i.last_sync_at', 's.name as site_name'])
            ->map(fn ($i) => (array) $i + [
                // Never the key itself, only whether one is set.
                'has_credentials' => DB::table('sheq_integrations')->where('uuid', $i->uuid)
                    ->whereNotNull('api_key')->exists(),
                'webhook_url' => url("/api/v1/sheq/gate/{$i->uuid}"),
            ])->values()->all();
    }

    /** @return list<array<string,mixed>> */
    private function recentEvents(int $companyId): array
    {
        return DB::table('sheq_access_events')
            ->where('company_id', $companyId)
            ->orderByDesc('occurred_at')->limit(50)
            ->get(['uuid', 'person_name', 'identifier', 'direction', 'allowed', 'reasons',
                'was_monitor_only', 'occurred_at', 'subject_type'])
            ->map(fn ($e) => [
                'uuid' => $e->uuid,
                // The identifier is shown only when we could not name the person — otherwise a
                // screen full of ID numbers is a POPIA problem for no operational gain.
                'person_name' => $e->person_name ?: $e->identifier,
                'direction' => $e->direction,
                'allowed' => (bool) $e->allowed,
                'monitor_only' => (bool) $e->was_monitor_only,
                'reasons' => json_decode((string) $e->reasons, true) ?: [],
                'occurred_at' => $e->occurred_at,
                'subject_type' => $e->subject_type,
            ])->values()->all();
    }
}
