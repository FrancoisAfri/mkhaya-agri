<?php

namespace App\Modules\Operations\Manufacturing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Operations\Manufacturing\Models\Integration;
use App\Modules\Operations\Manufacturing\Models\IntegrationEvent;
use App\Modules\Operations\Manufacturing\Models\IntegrationMapping;
use App\Modules\Operations\Manufacturing\Models\Item;
use App\Modules\Operations\Manufacturing\Models\ProductionOrder as PO;
use App\Modules\Operations\Manufacturing\Models\WorkCentre;
use App\Modules\Operations\Manufacturing\Integrations\RestAdapter;
use App\Modules\Operations\Manufacturing\Services\IntegrationHub;
use App\Modules\Tenancy\Services\TenantContext;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** External ERP and industrial integrations (MFG-022): set-up, validation, mappings, the message queue, files and webhooks. */
class IntegrationController extends Controller
{
    use \App\Modules\Operations\Manufacturing\Concerns\ScopesValidationToTenant;

    public function __construct(private readonly IntegrationHub $hub) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Integration::class);
        $counts = IntegrationEvent::query()->selectRaw('integration_id, status, COUNT(*) AS n')->groupBy('integration_id', 'status')->get()->groupBy('integration_id');

        return Inertia::render('Modules/Manufacturing/Integrations/Index', [
            'integrations' => Integration::query()->orderBy('name')->get()->map(fn (Integration $i) => [
                'id' => $i->uuid, 'name' => $i->name, 'connector' => $i->connector, 'method' => $i->method, 'status' => $i->status,
                'last_synced_at' => $i->last_synced_at?->toIso8601String(), 'last_sync_result' => $i->last_sync_result,
                'counts' => ($counts[$i->id] ?? collect())->pluck('n', 'status')->all(),
            ])->values(),
            'failures' => IntegrationEvent::query()->whereIn('status', ['FAILED', 'DEAD'])->with('integration:id,uuid,name')->latest('id')->limit(100)->get()
                ->map(fn (IntegrationEvent $e) => $this->eventRow($e))->values(),
            'connectors' => collect(Integration::CONNECTORS)->map(fn ($v, $k) => ['code' => $k, 'kind' => $v[0], 'method' => $v[1]])->values(),
            'can' => ['manage' => $request->user()->can('manage', Integration::class)],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('manage', Integration::class);
        $d = $request->validate(['name' => ['required', 'string', 'max:120'], 'connector' => ['required', Rule::in(array_keys(Integration::CONNECTORS))]]);
        [, $method] = Integration::CONNECTORS[$d['connector']];
        $preset = RestAdapter::PRESETS[$d['connector']] ?? [];
        $i = Integration::create(['company_id' => $this->tenantId($request), 'name' => $d['name'], 'connector' => $d['connector'], 'method' => $method,
            'base_url' => $preset['base_url'] ?? null, 'auth_type' => $preset['auth_type'] ?? ($method === 'API' ? 'API_KEY' : 'NONE'),
            'webhook_secret' => $method === 'WEBHOOK' ? $this->hub->newSecret() : null, 'settings' => [], 'status' => 'DRAFT', 'created_by' => $request->user()->id]);

        return redirect()->route('manufacturing.integrations.show', $i->uuid)->with('success', 'Integration created. Set it up, then validate it.');
    }

    public function show(Request $request, string $uuid): Response
    {
        $this->authorize('viewAny', Integration::class);
        $i = $this->find($uuid);
        $canManage = $request->user()->can('manage', Integration::class);

        return Inertia::render('Modules/Manufacturing/Integrations/Show', [
            'integration' => ['id' => $i->uuid, 'name' => $i->name, 'connector' => $i->connector, 'method' => $i->method, 'status' => $i->status,
                'base_url' => $i->base_url, 'auth_type' => $i->auth_type, 'settings' => $i->settings ?? [], 'sync_every_minutes' => $i->sync_every_minutes,
                'validation_notes' => $i->validation_notes, 'validated_at' => $i->validated_at?->toIso8601String(), 'last_synced_at' => $i->last_synced_at?->toIso8601String(),
                'last_sync_result' => $i->last_sync_result,
                // Which secrets are set -- never their values.
                'credential_keys' => array_keys((array) ($i->credentials ?? [])), 'has_webhook_secret' => (bool) $i->webhook_secret,
                'webhook_url' => $i->method === 'WEBHOOK' ? route('api.manufacturing.integrations.webhook', $i->uuid) : null],
            'mappings' => $i->mappings()->orderBy('entity')->orderBy('external_ref')->get()->map(fn ($m) => ['id' => $m->id, 'entity' => $m->entity,
                'local_id' => $m->local_id, 'local' => $this->localLabel($m->entity, (int) $m->local_id, (int) $i->company_id), 'external_ref' => $m->external_ref])->values(),
            'events' => $i->events()->latest('id')->limit(200)->get()->map(fn (IntegrationEvent $e) => $this->eventRow($e))->values(),
            'reconciliation' => $this->hub->reconcile($i),
            'options' => $canManage ? [
                'products' => Item::query()->with('stockItem:id,code')->limit(3000)->get()->map(fn ($x) => ['id' => $x->id, 'name' => $x->stockItem?->code])->values(),
                'warehouses' => FinWarehouse::query()->orderBy('code')->get(['id', 'code'])->map(fn ($w) => ['id' => $w->id, 'name' => $w->code])->values(),
                'work_centres' => WorkCentre::query()->orderBy('code')->get(['id', 'code'])->map(fn ($w) => ['id' => $w->id, 'name' => $w->code])->values(),
                'entities' => IntegrationMapping::ENTITIES,
            ] : null,
            'can' => ['manage' => $canManage],
        ]);
    }

    public function update(Request $request, string $uuid): RedirectResponse
    {
        $this->authorize('manage', Integration::class);
        $i = $this->find($uuid);
        $d = $request->validate([
            'name' => ['required', 'string', 'max:120'], 'base_url' => ['nullable', 'url:https', 'max:255'],
            'auth_type' => ['required', Rule::in(['NONE', 'API_KEY', 'BASIC', 'BEARER', 'OAUTH2'])],
            'settings' => ['nullable', 'array'], 'sync_every_minutes' => ['nullable', 'integer', 'min:5', 'max:1440'],
            // Secrets: only the ones given change; an empty field keeps what is stored.
            'credentials' => ['nullable', 'array'], 'credentials.*' => ['nullable', 'string', 'max:2000'],
        ]);
        $credentials = array_merge((array) ($i->credentials ?? []), array_filter((array) ($d['credentials'] ?? []), fn ($v) => $v !== null && $v !== ''));
        $settings = $this->hub->redact((array) ($d['settings'] ?? []));
        // A change to how it is reached undoes the validation.
        $reached = $d['base_url'] !== $i->base_url || $d['auth_type'] !== $i->auth_type || $credentials !== (array) ($i->credentials ?? []);
        $i->update(['name' => $d['name'], 'base_url' => $d['base_url'] ?? null, 'auth_type' => $d['auth_type'], 'settings' => $settings,
            'sync_every_minutes' => $d['sync_every_minutes'] ?? null, 'credentials' => $credentials ?: null]
            + ($reached && $i->status !== 'DRAFT' ? ['status' => 'DRAFT', 'validated_at' => null, 'validation_notes' => null, 'validated_by' => null] : []));

        return back()->with('success', $reached && $i->status === 'DRAFT' ? 'Saved. Validate it again before switching it on.' : 'Saved.');
    }

    public function act(Request $request, string $uuid, string $step): RedirectResponse
    {
        $this->authorize('manage', Integration::class);
        $i = $this->find($uuid);
        try {
            $message = match ($step) {
                'validate' => (fn ($r) => $r['ok'] ? 'Validated: '.$r['detail'] : 'Could not reach it: '.$r['detail'])(
                    $this->hub->validate($i, (string) $request->validate(['notes' => ['required', 'string', 'max:2000']])['notes'], $request->user())),
                'activate' => ($this->hub->activate($i) ? 'Switched on.' : ''),
                'pause' => ($this->hub->pause($i) ? 'Paused.' : ''),
                'sync' => 'Synced: '.json_encode($this->hub->sync($i)),
                'rotate-secret' => (function () use ($i) {
                    $i->update(['webhook_secret' => $this->hub->newSecret()]);

                    return 'A new webhook secret is set; give it to the device.';
                })(),
            };
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $message);
    }

    public function map(Request $request, string $uuid): RedirectResponse
    {
        $this->authorize('manage', Integration::class);
        $i = $this->find($uuid);
        $d = $request->validate(['entity' => ['required', Rule::in(IntegrationMapping::ENTITIES)], 'local_id' => ['required', 'integer'],
            'external_ref' => ['required', 'string', 'max:255'], 'remove' => ['boolean']]);
        if (! empty($d['remove'])) {
            IntegrationMapping::query()->where('integration_id', $i->id)->where('entity', $d['entity'])->where('local_id', $d['local_id'])->delete();

            return back()->with('success', 'Mapping removed.');
        }
        abort_unless($this->localLabel($d['entity'], (int) $d['local_id'], (int) $i->company_id) !== null, 422, 'That record is not in this company.');
        if (IntegrationMapping::query()->where('integration_id', $i->id)->where('entity', $d['entity'])->where('external_ref', $d['external_ref'])->where('local_id', '!=', $d['local_id'])->exists()) {
            return back()->with('error', 'That external reference is already mapped to another record.');
        }
        IntegrationMapping::query()->updateOrCreate(['integration_id' => $i->id, 'entity' => $d['entity'], 'local_id' => $d['local_id']],
            ['company_id' => $i->company_id, 'external_ref' => $d['external_ref']]);

        return back()->with('success', 'Mapping saved.');
    }

    public function event(Request $request, string $uuid, string $step): RedirectResponse
    {
        $this->authorize('manage', Integration::class);
        $e = IntegrationEvent::query()->where('uuid', $uuid)->firstOrFail();
        try {
            $e = $step === 'retry' ? $this->hub->retry($e) : $this->hub->discard($e, (string) $request->input('reason', ''));
        } catch (DomainException $ex) {
            return back()->with('error', $ex->getMessage());
        }

        return back()->with($e->status === 'PROCESSED' || $e->status === 'SKIPPED' ? 'success' : 'error',
            $e->status === 'PROCESSED' ? 'Processed.' : ($e->status === 'SKIPPED' ? 'Set aside.' : 'Still failing: '.$e->last_error));
    }

    /** A file the other system exported: each row is one message, processed once however often the file is uploaded. */
    public function import(Request $request, string $uuid): RedirectResponse
    {
        $this->authorize('manage', Integration::class);
        $i = $this->find($uuid);
        $d = $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'], 'type' => ['required', Rule::in(['ORDER_IMPORT', 'WEIGHT_READING', 'MACHINE_STATE'])]]);
        $rows = array_map('str_getcsv', file($d['file']->getRealPath(), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
        $head = array_map(fn ($h) => strtolower(trim($h)), array_shift($rows) ?? []);
        if (! in_array('id', $head, true)) {
            return back()->with('error', 'The file needs an "id" column: the other system\'s reference for each row, so nothing is imported twice.');
        }
        $done = ['processed' => 0, 'failed' => 0, 'duplicates' => 0];
        try {
            foreach ($rows as $row) {
                $data = array_combine($head, array_pad(array_slice($row, 0, count($head)), count($head), null));
                $before = IntegrationEvent::query()->where('integration_id', $i->id)->where('idempotency_key', $d['type'].':'.$data['id'])->exists();
                $e = $this->hub->ingest($i, $d['type'], (string) $data['id'], $data);
                $done[$before ? 'duplicates' : ($e->status === 'PROCESSED' ? 'processed' : 'failed')]++;
            }
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Imported: {$done['processed']} processed, {$done['failed']} failed, {$done['duplicates']} already imported.");
    }

    /** Outgoing messages for a file-exchange integration, as a CSV; downloading delivers them. */
    public function export(Request $request, string $uuid): StreamedResponse
    {
        $this->authorize('manage', Integration::class);
        $i = $this->find($uuid);
        $this->hub->queueOutputExports($i);
        $events = $i->events()->where('direction', 'OUT')->where('status', 'RECEIVED')->orderBy('id')->get();
        $events->each(fn ($e) => $e->update(['status' => 'PROCESSED', 'processed_at' => now(), 'result_type' => 'FILE', 'attempts' => $e->attempts + 1]));

        return response()->streamDownload(function () use ($events) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['message_id', 'type', 'product', 'warehouse', 'qty', 'lot', 'order', 'received_at']);
            foreach ($events as $e) {
                $p = $e->payload;
                fputcsv($out, array_map(fn ($c) => is_string($c) ? preg_replace('/^([=+\-@])/', "'$1", $c) : $c,
                    [$e->idempotency_key, $e->event_type, $p['product'] ?? null, $p['warehouse'] ?? null, $p['qty'] ?? null, $p['lot'] ?? null, $p['order'] ?? null, $p['received_at'] ?? null]));
            }
            fclose($out);
        }, 'manufacturing-export-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv']);
    }

    /** A device or gateway posting to us: signed with the integration's secret, or refused. */
    public function webhook(Request $request, string $uuid, TenantContext $tenants): JsonResponse
    {
        $i = Integration::withoutGlobalScopes()->where('uuid', $uuid)->where('method', 'WEBHOOK')->first();
        $body = $request->getContent();
        if (! $i || ! $i->webhook_secret || ! hash_equals($this->hub->signatureFor($i, $body), (string) $request->header('X-Mkhaya-Signature'))) {
            return response()->json(['error' => 'Unknown integration or bad signature.'], 401);
        }

        return $tenants->run((int) $i->company_id, function () use ($i, $request) {
            // The same subscription rules as the screens: the package must include integrations,
            // and an account in arrears takes no new transactions.
            $package = app(\App\Modules\Operations\Manufacturing\Services\ManufacturingPackage::class);
            if (! app(\App\Modules\Tenancy\Services\CompanyEntitlements::class)->currentAllowsModule('manufacturing')
                || ! $package->allowsFeature('integrations') || $package->readOnly()) {
                return response()->json(['error' => 'Manufacturing integrations are not available on this account right now.'], 403);
            }
            $messages = $request->input('events') ?? [$request->only(['type', 'id', 'data'])];
            $results = [];
            foreach ($messages as $m) {
                if (empty($m['type']) || empty($m['id'])) {
                    $results[] = ['id' => $m['id'] ?? null, 'status' => 'REJECTED', 'error' => 'type and id are required'];

                    continue;
                }
                try {
                    $e = $this->hub->ingest($i->fresh(), (string) $m['type'], (string) $m['id'], (array) ($m['data'] ?? []));
                    $results[] = ['id' => $m['id'], 'status' => $e->status, 'duplicate' => $e->duplicates > 0, 'reference' => $e->result_reference];
                } catch (DomainException $ex) {
                    $results[] = ['id' => $m['id'], 'status' => 'REJECTED', 'error' => $ex->getMessage()];
                }
            }

            return response()->json(['results' => $results]);
        });
    }

    private function eventRow(IntegrationEvent $e): array
    {
        return ['id' => $e->uuid, 'integration' => $e->integration?->name, 'integration_id' => $e->integration?->uuid, 'direction' => $e->direction, 'type' => $e->event_type,
            'external_id' => $e->external_id, 'status' => $e->status, 'attempts' => $e->attempts, 'duplicates' => $e->duplicates, 'error' => $e->last_error,
            'result' => $e->result_reference, 'next_attempt_at' => $e->next_attempt_at?->toIso8601String(), 'at' => $e->created_at?->toIso8601String()];
    }

    private function localLabel(string $entity, int $id, int $companyId): ?string
    {
        return match ($entity) {
            'PRODUCT' => Item::query()->whereKey($id)->with('stockItem:id,code')->first()?->stockItem?->code,
            'WAREHOUSE' => FinWarehouse::query()->whereKey($id)->value('code'),
            'WORK_CENTRE' => WorkCentre::query()->whereKey($id)->value('code'),
            'ORDER' => PO::query()->whereKey($id)->value('number'),
            // Plain tables: confined to this company by column, never by assumption.
            'SUPPLIER' => DB::table('suppliers')->where('id', $id)->where('company_id', $companyId)->value('company_name'),
            'CUSTOMER' => DB::table('fin_parties')->where('id', $id)->where('company_id', $companyId)->value('name'),
            'UNIT' => DB::table('mfg_uoms')->where('id', $id)->where(fn ($q) => $q->whereNull('company_id')->orWhere('company_id', $companyId))->value('code'),
            default => null,
        };
    }

    private function find(string $uuid): Integration
    {
        return Integration::query()->where('uuid', $uuid)->firstOrFail();
    }

    private function tenantId(Request $request): int
    {
        return (int) (app(\App\Modules\Tenancy\Services\CurrentCompany::class)->id() ?? $request->user()->company_id);
    }
}
