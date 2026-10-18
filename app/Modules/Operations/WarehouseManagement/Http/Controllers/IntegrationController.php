<?php

namespace App\Modules\Operations\WarehouseManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Operations\Manufacturing\Integrations\RestAdapter;
use App\Modules\Operations\Manufacturing\Models\Integration;
use App\Modules\Operations\Manufacturing\Models\IntegrationEvent;
use App\Modules\Operations\Manufacturing\Models\IntegrationMapping;
use App\Modules\Operations\Manufacturing\Services\IntegrationHub;
use App\Modules\Operations\WarehouseManagement\Concerns\RefusesPolitely;
use App\Modules\Operations\WarehouseManagement\Models\Carrier;
use App\Modules\Operations\WarehouseManagement\Models\Webhook;
use App\Modules\Operations\WarehouseManagement\Services\Carriers;
use App\Modules\Operations\WarehouseManagement\Services\EpcisMapping;
use App\Modules\Operations\WarehouseManagement\Services\WarehouseErp;
use App\Modules\Operations\WarehouseManagement\Services\WarehouseEvents;
use App\Modules\Operations\WarehouseManagement\Services\WebhookDeliveries;
use App\Modules\Operations\WarehouseManagement\Support\RoleCatalog;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** WMS-170..173 on screen: carriers, webhooks and events, connected systems, standards mapping. */
class IntegrationController extends Controller
{
    use RefusesPolitely;

    // ── Carriers ─────────────────────────────────────────────────────────────────────────────

    public function carriers(Request $request, Carriers $carriers): Response
    {
        $this->authorize('viewAny', FinStockItem::class);

        return Inertia::render('Modules/Warehouse/Integrations/Carriers', [
            'carriers' => Carrier::query()->with('rates')->orderBy('name')->get()->map(fn (Carrier $c) => ['id' => $c->uuid, 'code' => $c->code, 'name' => $c->name,
                'driver' => $c->driver, 'account_ref' => $c->account_ref, 'tracking_url' => $c->tracking_url, 'is_active' => $c->is_active,
                'capabilities' => $carriers->driver($c)->capabilities(),
                'rates' => $c->rates->map(fn ($r) => ['service' => $r->service, 'zone' => $r->zone, 'max_weight_kg' => $r->max_weight_kg, 'price' => $r->price, 'transit_days' => $r->transit_days])->values()]),
            'drivers' => Carrier::DRIVERS,
            'can' => ['manage' => $request->user()->can(RoleCatalog::INTEGRATIONS)],
        ]);
    }

    public function saveCarrier(Request $request, ?string $uuid = null): RedirectResponse
    {
        abort_unless($request->user()->can(RoleCatalog::INTEGRATIONS), 403);
        $d = $request->validate(['code' => ['required', 'string', 'max:20'], 'name' => ['required', 'string', 'max:120'], 'driver' => ['required', Rule::in(Carrier::DRIVERS)],
            'account_ref' => ['nullable', 'string', 'max:60'], 'tracking_url' => ['nullable', 'string', 'max:300', 'starts_with:https://'], 'is_active' => ['boolean'],
            'rates' => ['array', 'max:200'], 'rates.*.service' => ['required', 'string', 'max:60'], 'rates.*.zone' => ['nullable', 'string', 'max:60'],
            'rates.*.max_weight_kg' => ['required', 'numeric', 'gt:0'], 'rates.*.price' => ['required', 'numeric', 'min:0'], 'rates.*.transit_days' => ['nullable', 'integer', 'between:0,90']]);

        return $this->attempt(fn () => DB::transaction(function () use ($d, $uuid) {
            $carrier = $uuid ? Carrier::query()->where('uuid', $uuid)->firstOrFail() : new Carrier(['company_id' => app(CurrentCompany::class)->id()]);
            if (Carrier::query()->where('code', $d['code'])->when($carrier->exists, fn ($q) => $q->whereKeyNot($carrier->id))->exists()) {
                throw new RuntimeException("Another carrier already has the code {$d['code']}.");
            }
            $carrier->fill(array_intersect_key($d, array_flip(['code', 'name', 'driver', 'account_ref', 'tracking_url'])) + ['is_active' => $d['is_active'] ?? true])->save();
            $carrier->rates()->delete();
            foreach ($d['rates'] ?? [] as $r) {
                $carrier->rates()->create($r + ['company_id' => $carrier->company_id]);
            }
        }), 'Carrier saved.');
    }

    // ── Webhooks and events ──────────────────────────────────────────────────────────────────

    public function events(Request $request, WarehouseEvents $events): Response
    {
        $this->authorize('viewAny', FinStockItem::class);
        $manage = $request->user()->can(RoleCatalog::INTEGRATIONS);
        $recent = DB::table('wms_outbox')->where('company_id', app(CurrentCompany::class)->id())
            ->when($request->query('state') === 'dead', fn ($q) => $q->whereNotNull('failed_at'))->orderByDesc('id')->limit(100)->get();

        return Inertia::render('Modules/Warehouse/Integrations/Events', [
            'catalogue' => $events->catalogue(),
            'webhooks' => $manage ? Webhook::query()->orderBy('name')->get()->map(fn (Webhook $h) => ['id' => $h->uuid, 'name' => $h->name, 'url' => $h->url,
                'event_types' => $h->event_types, 'secret_hint' => $h->secret_hint, 'is_active' => $h->is_active, 'disabled_reason' => $h->disabled_reason,
                'last_delivered_at' => $h->last_delivered_at?->toIso8601String(), 'failures' => $h->consecutive_failures,
                'warehouses' => $h->warehouse_ids ? FinWarehouse::query()->whereIn('id', $h->warehouse_ids)->pluck('code')->all() : null]) : [],
            'events' => $recent->map(fn ($r) => ['id' => $r->event_id, 'sequence' => (int) $r->id, 'type' => $r->event_type, 'version' => (int) $r->schema_version,
                'subject' => "{$r->subject_type} {$r->subject_ref}", 'occurred_at' => Carbon::parse($r->occurred_at)->toIso8601String(),
                'state' => $r->failed_at ? 'DEAD' : ($r->published_at ? 'DELIVERED' : ($r->attempts > 0 ? 'RETRYING' : 'PENDING')),
                'attempts' => (int) $r->attempts, 'error' => $manage ? $r->last_error : null])->all(),
            'counts' => ['pending' => DB::table('wms_outbox')->where('company_id', app(CurrentCompany::class)->id())->whereNull('published_at')->whereNull('failed_at')->count(),
                'dead' => DB::table('wms_outbox')->where('company_id', app(CurrentCompany::class)->id())->whereNotNull('failed_at')->count()],
            'state' => $request->query('state'),
            'warehouses' => FinWarehouse::query()->where('is_wms_managed', true)->orderBy('code')->get(['id', 'code'])->map(fn ($w) => ['value' => $w->id, 'title' => $w->code]),
            'api' => ['base' => url('api/v1/warehouse'), 'openapi' => route('api.warehouse.openapi')],
            'secret' => session('webhook_secret'),
            'can' => ['manage' => $manage],
        ]);
    }

    public function webhook(Request $request, WebhookDeliveries $deliveries, ?string $uuid = null): RedirectResponse
    {
        abort_unless($request->user()->can(RoleCatalog::INTEGRATIONS), 403);
        $action = $request->input('action', 'save');
        if ($uuid && $action !== 'save') {
            $hook = Webhook::query()->where('uuid', $uuid)->firstOrFail();

            return match ($action) {
                'rotate' => back()->with('webhook_secret', $deliveries->rotate($hook))->with('success', 'New secret made. Copy it now; it is not shown again.'),
                'delete' => tap(back()->with('success', 'Webhook removed.'), fn () => $hook->delete()),
                'toggle' => $this->attempt(fn () => $deliveries->change($hook, ['is_active' => ! ($hook->is_active && ! $hook->disabled_at)]), 'Webhook updated.'),
                default => abort(422),
            };
        }
        $d = $request->validate(['name' => ['required', 'string', 'max:120'], 'url' => ['required', 'url', 'max:500'], 'event_types' => ['required', 'array', 'min:1'],
            'event_types.*' => [Rule::in(array_keys(WarehouseEvents::TYPES))], 'warehouse_ids' => ['nullable', 'array'], 'warehouse_ids.*' => ['integer']]);
        if (! empty($d['warehouse_ids'])) {
            $d['warehouse_ids'] = FinWarehouse::query()->whereIn('id', $d['warehouse_ids'])->pluck('id')->all();
        }
        try {
            if ($uuid) {
                $deliveries->change(Webhook::query()->where('uuid', $uuid)->firstOrFail(), $d);

                return back()->with('success', 'Webhook updated.');
            }
            [, $secret] = $deliveries->subscribe(app(CurrentCompany::class)->id(), $d, $request->user());
        } catch (RuntimeException $e) {
            return back()->withErrors(['refused' => $e->getMessage()])->withInput();
        }

        return back()->with('webhook_secret', $secret)->with('success', 'Webhook added. Copy its secret now; it is not shown again.');
    }

    public function replay(Request $request, string $eventId, WebhookDeliveries $deliveries): RedirectResponse
    {
        abort_unless($request->user()->can(RoleCatalog::INTEGRATIONS), 403);
        abort_unless(DB::table('wms_outbox')->where('company_id', app(CurrentCompany::class)->id())->where('event_id', $eventId)->exists(), 404);

        return $this->attempt(fn () => $deliveries->replay($eventId), 'Queued to send again.');
    }

    // ── Connected systems (Manufacturing's integration hub) ──────────────────────────────────

    public function systems(Request $request, IntegrationHub $hub): Response
    {
        $this->authorize('viewAny', FinStockItem::class);
        $manage = $request->user()->can(RoleCatalog::INTEGRATIONS);
        $chosen = $request->query('system') ? Integration::query()->where('uuid', $request->query('system'))->first() : null;
        $chosen ??= Integration::query()->whereIn('connector', $this->erpConnectors())->orderBy('name')->first();

        return Inertia::render('Modules/Warehouse/Integrations/Systems', [
            'systems' => Integration::query()->whereIn('connector', $this->erpConnectors())->orderBy('name')->get()
                ->map(fn (Integration $i) => ['id' => $i->uuid, 'name' => $i->name, 'connector' => $i->connector, 'status' => $i->status])->values(),
            'system' => $chosen ? [
                'id' => $chosen->uuid, 'name' => $chosen->name, 'connector' => $chosen->connector, 'method' => $chosen->method, 'status' => $chosen->status,
                'base_url' => $chosen->base_url, 'exports' => array_values(array_intersect((array) ($chosen->settings['export'] ?? []), array_keys(WarehouseErp::EXPORTS))),
                'validation_notes' => $chosen->validation_notes, 'last_synced_at' => $chosen->last_synced_at?->toIso8601String(), 'last_sync_result' => $chosen->last_sync_result,
                'webhook_url' => $chosen->method === 'WEBHOOK' ? route('api.manufacturing.integrations.webhook', $chosen->uuid) : null,
                'mappings' => IntegrationMapping::query()->where('integration_id', $chosen->id)->whereIn('entity', WarehouseErp::ENTITIES)->orderBy('entity')->get()
                    ->map(fn ($m) => ['entity' => $m->entity, 'local_id' => (int) $m->local_id, 'external_ref' => $m->external_ref,
                        'local' => $m->entity === 'STOCK_ITEM' ? FinStockItem::query()->whereKey($m->local_id)->value('code') : FinWarehouse::query()->whereKey($m->local_id)->value('code')])->values(),
                'queue' => IntegrationEvent::query()->where('integration_id', $chosen->id)->where('event_type', 'like', 'WMS_%')->latest('id')->limit(100)->get()
                    ->map(fn (IntegrationEvent $e) => ['id' => $e->uuid, 'direction' => $e->direction, 'type' => $e->event_type, 'external_id' => $e->external_id,
                        'status' => $e->status, 'attempts' => (int) $e->attempts, 'error' => $e->last_error, 'result' => $e->result_reference,
                        'next_attempt_at' => $e->next_attempt_at?->toIso8601String(), 'at' => $e->created_at?->toIso8601String()])->values(),
                'reconciliations' => DB::table('wms_erp_reconciliations')->where('integration_id', $chosen->id)->orderByDesc('id')->limit(5)->get()
                    ->map(fn ($r) => ['at' => Carbon::parse($r->created_at)->toIso8601String(), 'compared' => $r->compared, 'differences' => $r->differences,
                        'unmapped' => $r->unmapped, 'lines' => collect(json_decode($r->lines, true))->filter(fn ($l) => $l['difference'] === null || abs($l['difference']) > 1e-6)->values()])->values(),
            ] : null,
            'connectors' => $this->erpConnectors(),
            'exports' => array_keys(WarehouseErp::EXPORTS),
            'items' => $chosen ? FinStockItem::query()->orderBy('code')->limit(1000)->get(['id', 'code'])->map(fn ($i) => ['value' => $i->id, 'title' => $i->code]) : [],
            'warehouses' => FinWarehouse::query()->orderBy('code')->get(['id', 'code'])->map(fn ($w) => ['value' => $w->id, 'title' => $w->code]),
            'can' => ['manage' => $manage],
        ]);
    }

    public function systemAct(Request $request, IntegrationHub $hub, ?string $uuid = null): RedirectResponse
    {
        abort_unless($request->user()->can(RoleCatalog::INTEGRATIONS), 403);
        $action = $request->input('action');
        if (! $uuid) {
            $d = $request->validate(['name' => ['required', 'string', 'max:120'], 'connector' => ['required', Rule::in($this->erpConnectors())]]);
            [, $method] = Integration::CONNECTORS[$d['connector']];
            $preset = RestAdapter::PRESETS[$d['connector']] ?? [];
            $i = Integration::create(['company_id' => app(CurrentCompany::class)->id(), 'name' => $d['name'], 'connector' => $d['connector'], 'method' => $method,
                'base_url' => $preset['base_url'] ?? null, 'auth_type' => $preset['auth_type'] ?? ($method === 'API' ? 'API_KEY' : 'NONE'),
                'webhook_secret' => $method === 'WEBHOOK' ? $hub->newSecret() : null, 'settings' => [], 'status' => 'DRAFT', 'created_by' => $request->user()->id]);

            return redirect()->route('warehouse.integrations.systems', ['system' => $i->uuid])->with('success', 'Connection created. Map products and warehouses, then validate it.');
        }
        $i = Integration::query()->where('uuid', $uuid)->firstOrFail();
        if (in_array($action, ['exports', 'map', 'validate', 'activate', 'pause', 'retry', 'discard'], true)) {
            app(\App\Modules\Operations\WarehouseManagement\Services\WarehouseAudit::class)->record((int) $i->company_id, "integration.{$action}", 'INTEGRATION', $i->name, "Connection {$i->name}: {$action}",
                null, $request->only(['exports', 'entity', 'local_id', 'external_ref', 'event', 'reason']));
        }

        return match ($action) {
            'exports' => $this->attempt(function () use ($request, $i) {
                $chosen = array_values(array_intersect((array) $request->input('exports', []), array_keys(WarehouseErp::EXPORTS)));
                $keep = array_values(array_diff((array) ($i->settings['export'] ?? []), array_keys(WarehouseErp::EXPORTS)));
                $i->update(['settings' => array_merge($i->settings ?? [], ['export' => array_merge($keep, $chosen)])]);
            }, 'Exports saved.'),
            'map' => $this->attempt(function () use ($request, $i) {
                $d = $request->validate(['entity' => ['required', Rule::in(WarehouseErp::ENTITIES)], 'local_id' => ['required', 'integer'], 'external_ref' => ['nullable', 'string', 'max:255']]);
                $exists = $d['entity'] === 'STOCK_ITEM' ? FinStockItem::query()->whereKey($d['local_id'])->exists() : FinWarehouse::query()->whereKey($d['local_id'])->exists();
                abort_unless($exists, 422);
                if (blank($d['external_ref'])) {
                    IntegrationMapping::query()->where('integration_id', $i->id)->where('entity', $d['entity'])->where('local_id', $d['local_id'])->delete();

                    return;
                }
                if (IntegrationMapping::query()->where('integration_id', $i->id)->where('entity', $d['entity'])->where('external_ref', $d['external_ref'])->where('local_id', '!=', $d['local_id'])->exists()) {
                    throw new RuntimeException('That external reference is already mapped to another record.');
                }
                IntegrationMapping::query()->updateOrCreate(['integration_id' => $i->id, 'entity' => $d['entity'], 'local_id' => $d['local_id']],
                    ['company_id' => $i->company_id, 'external_ref' => $d['external_ref']]);
            }, 'Mapping saved.'),
            'validate' => $this->attempt(fn () => $this->domain(fn () => $hub->validate($i, (string) $request->validate(['notes' => ['required', 'string', 'min:10', 'max:2000']])['notes'], $request->user())), 'Validated.'),
            'activate' => $this->attempt(fn () => $this->domain(fn () => $hub->activate($i)), 'Switched on.'),
            'pause' => $this->attempt(fn () => $this->domain(fn () => $hub->pause($i)), 'Paused.'),
            'sync' => $this->attempt(fn () => $this->domain(fn () => $hub->sync($i)), 'Synchronised.'),
            'retry', 'discard' => $this->attempt(function () use ($request, $i, $hub, $action) {
                $e = IntegrationEvent::query()->where('integration_id', $i->id)->where('uuid', (string) $request->input('event'))->firstOrFail();
                $this->domain(fn () => $action === 'retry' ? $hub->retry($e) : $hub->discard($e, (string) $request->input('reason')));
            }, $action === 'retry' ? 'Tried again.' : 'Set aside.'),
            default => abort(422),
        };
    }

    // ── Standards (EPCIS mapping) ────────────────────────────────────────────────────────────

    public function standards(Request $request, EpcisMapping $mapping): Response
    {
        $this->authorize('viewAny', FinStockItem::class);
        $from = Carbon::parse($request->query('from', now()->subDays(30)->toDateString()))->startOfDay();
        $to = Carbon::parse($request->query('to', now()->toDateString()))->endOfDay();
        $doc = $mapping->document($from, $to, null, 2000);

        return Inertia::render('Modules/Warehouse/Integrations/Standards', [
            'from' => $from->toDateString(), 'to' => $to->toDateString(),
            'coverage' => $doc['mkhaya:coverage'], 'conformance' => $doc['mkhaya:conformance'],
            'by_type' => collect($doc['epcisBody']['eventList'])->countBy(fn ($e) => $e['type'].' · '.($e['bizStep'] ?? '')),
            'sample' => array_slice($doc['epcisBody']['eventList'], 0, 3),
            'movements' => EpcisMapping::MOVEMENTS, 'dispositions' => EpcisMapping::DISPOSITIONS,
            'warehouses' => FinWarehouse::query()->where('is_wms_managed', true)->orderBy('code')->get()->map(fn ($w) => ['id' => $w->uuid, 'code' => $w->code, 'gln' => $w->policies['gln'] ?? null]),
            'can' => ['manage' => $request->user()->can(RoleCatalog::INTEGRATIONS)],
        ]);
    }

    public function epcisDownload(Request $request, EpcisMapping $mapping): StreamedResponse
    {
        $this->authorize('viewAny', FinStockItem::class);
        $d = $request->validate(['from' => ['required', 'date'], 'to' => ['required', 'date']]);
        $doc = $mapping->document(Carbon::parse($d['from'])->startOfDay(), Carbon::parse($d['to'])->endOfDay());

        return response()->streamDownload(fn () => print(json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
            "epcis-{$d['from']}-{$d['to']}.jsonld", ['Content-Type' => 'application/ld+json']);
    }

    public function saveGln(Request $request, string $warehouse): RedirectResponse
    {
        abort_unless($request->user()->can(RoleCatalog::INTEGRATIONS), 403);
        $w = FinWarehouse::query()->where('uuid', $warehouse)->firstOrFail();
        $d = $request->validate(['gln' => ['nullable', 'regex:/^\d{13}$/']]);

        return $this->attempt(function () use ($w, $d, $request) {
            if ($d['gln'] && ! \App\Modules\Operations\WarehouseManagement\Support\Gs1Parser::checkDigitValid($d['gln'])) {
                throw new RuntimeException("{$d['gln']} has a wrong check digit.");
            }
            app(\App\Modules\Operations\WarehouseManagement\Services\WarehousePolicies::class)->apply($w, ['gln' => $d['gln'] ?: null], $request->user(), 'GLN set on the standards screen');
        }, 'GLN saved.');
    }

    /** ERP and commerce connectors -- devices and line systems stay on Manufacturing's screen. */
    private function erpConnectors(): array
    {
        return collect(Integration::CONNECTORS)->filter(fn ($c) => $c[0] === 'ERP')->keys()->all();
    }

    /** Manufacturing refuses with DomainException; this screen shows refusals politely. */
    private function domain(callable $fn): mixed
    {
        try {
            return $fn();
        } catch (\DomainException $e) {
            throw new RuntimeException($e->getMessage(), 0, $e);
        }
    }
}
