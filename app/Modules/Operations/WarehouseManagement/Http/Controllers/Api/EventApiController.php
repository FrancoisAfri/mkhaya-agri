<?php

namespace App\Modules\Operations\WarehouseManagement\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Operations\WarehouseManagement\Models\CustomerReturn;
use App\Modules\Operations\WarehouseManagement\Models\Shipment;
use App\Modules\Operations\WarehouseManagement\Models\TrackingEvent;
use App\Modules\Operations\WarehouseManagement\Models\Webhook;
use App\Modules\Operations\WarehouseManagement\Services\Carriers;
use App\Modules\Operations\WarehouseManagement\Services\EpcisMapping;
use App\Modules\Operations\WarehouseManagement\Services\WarehouseEvents;
use App\Modules\Operations\WarehouseManagement\Services\WebhookDeliveries;
use App\Modules\Operations\WarehouseManagement\Support\RoleCatalog;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * The warehouse API beyond orders (WMS-172): the event feed and webhook subscriptions, stock
 * movements, reservations, shipments and their tracking, returns, and the EPCIS mapping.
 *
 * Every request is authenticated (Sanctum), inside the token user's company, checked against the
 * user's permission and the token's ability, and -- when the token carries `wms:warehouse:CODE`
 * abilities -- limited to those warehouses.
 */
class EventApiController extends Controller
{
    public function types(Request $request, WarehouseEvents $events): JsonResponse
    {
        $this->allow($request, RoleCatalog::VIEW, 'wms:read');

        return response()->json(['data' => $events->catalogue()]);
    }

    /** The feed: events after a sequence number, oldest first. A consumer stores `next_after`. */
    public function feed(Request $request): JsonResponse
    {
        $this->allow($request, RoleCatalog::VIEW, 'wms:read');
        $d = $request->validate(['after' => ['nullable', 'integer', 'min:0'], 'types' => ['nullable', 'array'], 'types.*' => [Rule::in(array_keys(WarehouseEvents::TYPES))],
            'warehouse' => ['nullable', 'string'], 'limit' => ['nullable', 'integer', 'between:1,500']]);
        $warehouses = $this->warehouses($request, $d['warehouse'] ?? null);
        $rows = DB::table('wms_outbox')->where('company_id', app(CurrentCompany::class)->id())->where('id', '>', (int) ($d['after'] ?? 0))
            ->when($d['types'] ?? null, fn ($q, $t) => $q->whereIn('event_type', $t))
            ->when($warehouses !== null, fn ($q) => $q->whereIn('warehouse_id', $warehouses))
            ->orderBy('id')->limit((int) ($d['limit'] ?? 200))->get();

        return response()->json(['data' => $rows->map(fn ($r) => WarehouseEvents::envelope($r))->all(),
            'next_after' => (int) ($rows->last()->id ?? ($d['after'] ?? 0))]);
    }

    public function replay(Request $request, string $eventId, WebhookDeliveries $deliveries): JsonResponse
    {
        $this->allow($request, RoleCatalog::INTEGRATIONS, 'wms:webhooks');
        abort_unless(DB::table('wms_outbox')->where('company_id', app(CurrentCompany::class)->id())->where('event_id', $eventId)->exists(), 404);
        $deliveries->replay($eventId);

        return response()->json(['message' => 'Queued for delivery again.'], 202);
    }

    public function webhooks(Request $request): JsonResponse
    {
        $this->allow($request, RoleCatalog::INTEGRATIONS, 'wms:webhooks');

        return response()->json(['data' => Webhook::query()->orderBy('name')->get()->map(fn (Webhook $h) => $this->hook($h))->all()]);
    }

    public function subscribe(Request $request, WebhookDeliveries $deliveries): JsonResponse
    {
        $this->allow($request, RoleCatalog::INTEGRATIONS, 'wms:webhooks');
        $d = $this->validateHook($request, true);
        try {
            [$hook, $secret] = $deliveries->subscribe(app(CurrentCompany::class)->id(), $d, $request->user());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($this->hook($hook) + ['secret' => $secret], 201);
    }

    public function change(Request $request, string $uuid, WebhookDeliveries $deliveries): JsonResponse
    {
        $this->allow($request, RoleCatalog::INTEGRATIONS, 'wms:webhooks');
        $hook = Webhook::query()->where('uuid', $uuid)->firstOrFail();
        try {
            $hook = $deliveries->change($hook, $this->validateHook($request, false));
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($this->hook($hook));
    }

    public function rotate(Request $request, string $uuid, WebhookDeliveries $deliveries): JsonResponse
    {
        $this->allow($request, RoleCatalog::INTEGRATIONS, 'wms:webhooks');

        return response()->json(['secret' => $deliveries->rotate(Webhook::query()->where('uuid', $uuid)->firstOrFail())]);
    }

    public function unsubscribe(Request $request, string $uuid): JsonResponse
    {
        $this->allow($request, RoleCatalog::INTEGRATIONS, 'wms:webhooks');
        Webhook::query()->where('uuid', $uuid)->firstOrFail()->delete();

        return response()->json(null, 204);
    }

    public function movements(Request $request): JsonResponse
    {
        $this->allow($request, RoleCatalog::VIEW, 'wms:read');
        $d = $request->validate(['warehouse' => ['nullable', 'string'], 'since' => ['nullable', 'date'], 'type' => ['nullable', 'string', 'max:20'],
            'item' => ['nullable', 'string'], 'limit' => ['nullable', 'integer', 'between:1,1000']]);
        $warehouses = $this->warehouses($request, $d['warehouse'] ?? null) ?? FinWarehouse::query()->where('is_wms_managed', true)->pluck('id')->all();
        $rows = DB::table('fin_stock_movements as m')->join('fin_stock_items as i', 'i.id', '=', 'm.item_id')->join('fin_warehouses as w', 'w.id', '=', 'm.warehouse_id')
            ->leftJoin('fin_stock_lots as l', 'l.id', '=', 'm.lot_id')->where('m.company_id', app(CurrentCompany::class)->id())->whereIn('m.warehouse_id', $warehouses)
            ->when($d['since'] ?? null, fn ($q, $s) => $q->where('m.created_at', '>=', Carbon::parse($s)))
            ->when($d['type'] ?? null, fn ($q, $t) => $q->where('m.type', $t))->when($d['item'] ?? null, fn ($q, $c) => $q->where('i.code', $c))
            ->orderBy('m.id')->limit((int) ($d['limit'] ?? 200))
            ->get(['m.uuid', 'm.type', 'i.code as item', 'w.code as warehouse', 'm.qty', 'l.lot_number as lot', 'm.stock_status as status', 'm.to_stock_status as to_status',
                'm.reason_code', 'm.reference', 'm.occurred_at', 'm.created_at']);

        return response()->json(['data' => $rows->map(fn ($r) => ['movement' => $r->uuid, 'type' => $r->type, 'item' => $r->item, 'warehouse' => $r->warehouse,
            'qty' => (float) $r->qty, 'lot' => $r->lot, 'status' => $r->status, 'to_status' => $r->to_status, 'reason_code' => $r->reason_code, 'reference' => $r->reference,
            'occurred_at' => Carbon::parse($r->occurred_at ?? $r->created_at)->toIso8601String()])->all()]);
    }

    public function reservations(Request $request): JsonResponse
    {
        $this->allow($request, RoleCatalog::VIEW, 'wms:read');
        $d = $request->validate(['warehouse' => ['nullable', 'string'], 'item' => ['nullable', 'string'], 'status' => ['nullable', Rule::in(['OPEN', 'CONSUMED', 'RELEASED'])]]);
        $warehouses = $this->warehouses($request, $d['warehouse'] ?? null) ?? FinWarehouse::query()->where('is_wms_managed', true)->pluck('id')->all();
        $rows = DB::table('fin_stock_reservations as r')->join('fin_stock_items as i', 'i.id', '=', 'r.item_id')->join('fin_warehouses as w', 'w.id', '=', 'r.warehouse_id')
            ->where('r.company_id', app(CurrentCompany::class)->id())->whereIn('r.warehouse_id', $warehouses)->where('r.status', $d['status'] ?? 'OPEN')
            ->when($d['item'] ?? null, fn ($q, $c) => $q->where('i.code', $c))->orderBy('r.id')->limit(1000)
            ->get(['r.uuid', 'i.code as item', 'w.code as warehouse', 'r.qty', 'r.qty_consumed', 'r.status', 'r.source_type', 'r.created_at']);

        return response()->json(['data' => $rows->map(fn ($r) => ['reservation' => $r->uuid, 'item' => $r->item, 'warehouse' => $r->warehouse, 'qty' => (float) $r->qty,
            'qty_consumed' => (float) $r->qty_consumed, 'status' => $r->status, 'source_type' => $r->source_type])->all()]);
    }

    public function shipment(Request $request, string $number): JsonResponse
    {
        $this->allow($request, RoleCatalog::VIEW, 'wms:read');
        $s = $this->findShipment($request, $number);

        return response()->json($this->presentShipment($s));
    }

    /** A carrier or partner reports what happened. The same `external_id` twice is kept once. */
    public function track(Request $request, string $number, Carriers $carriers): JsonResponse
    {
        $this->allow($request, RoleCatalog::DISPATCH, 'wms:tracking');
        $s = $this->findShipment($request, $number);
        $d = $request->validate(['status' => ['required', Rule::in(TrackingEvent::STATUSES)], 'occurred_at' => ['required', 'date'],
            'location' => ['nullable', 'string', 'max:160'], 'detail' => ['nullable', 'string', 'max:500'], 'external_id' => ['required', 'string', 'max:120'],
            'received_by' => ['nullable', 'string', 'max:160']]);
        try {
            $event = $carriers->track($s, $d['status'], Carbon::parse($d['occurred_at'])->toIso8601String(), $d['location'] ?? null, $d['detail'] ?? null, 'API',
                $d['external_id'], $request->user(), $d['received_by'] ?? null);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($this->presentShipment($s->fresh()), $event ? 201 : 200);
    }

    public function returns(Request $request): JsonResponse
    {
        $this->allow($request, RoleCatalog::VIEW, 'wms:read');
        $d = $request->validate(['status' => ['nullable', Rule::in(CustomerReturn::STATUSES)], 'warehouse' => ['nullable', 'string']]);
        $warehouses = $this->warehouses($request, $d['warehouse'] ?? null);
        $rows = CustomerReturn::query()->when($d['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($warehouses !== null, fn ($q) => $q->whereIn('warehouse_id', $warehouses))->latest('id')->limit(500)->get();

        return response()->json(['data' => $rows->map(fn (CustomerReturn $r) => ['return' => $r->number, 'status' => $r->status, 'customer' => $r->customer_name,
            'reference' => $r->reference, 'received_at' => $r->received_at?->toIso8601String(), 'completed_at' => $r->completed_at?->toIso8601String()])->all()]);
    }

    public function epcis(Request $request, EpcisMapping $mapping): JsonResponse
    {
        $this->allow($request, RoleCatalog::VIEW, 'wms:read');
        $d = $request->validate(['from' => ['required', 'date'], 'to' => ['required', 'date', 'after:from'], 'warehouse' => ['nullable', 'string']]);
        $warehouses = $this->warehouses($request, $d['warehouse'] ?? null);
        // A token limited to some warehouses names the one it wants.
        abort_if($warehouses !== null && empty($d['warehouse']), 422, 'Name the warehouse: this token is limited to some warehouses.');

        return response()->json($mapping->document(Carbon::parse($d['from']), Carbon::parse($d['to']), $warehouses[0] ?? null),
            200, ['Content-Type' => 'application/ld+json']);
    }

    /**
     * The warehouses this request may see: the one named (it must be in the company and run by the
     * module), narrowed by the token's `wms:warehouse:CODE` abilities if it has any. Null: no limit.
     *
     * @return list<int>|null
     */
    private function warehouses(Request $request, ?string $code): ?array
    {
        $token = $request->user()->currentAccessToken();
        $allowed = $token && is_array($token->abilities ?? null)
            ? collect($token->abilities)->filter(fn ($a) => str_starts_with($a, 'wms:warehouse:'))->map(fn ($a) => substr($a, 14))->values()->all() : [];
        if ($code !== null && $code !== '') {
            if ($allowed !== [] && ! in_array($code, $allowed, true)) {
                abort(403, "This token may not read warehouse {$code}.");
            }
            $id = FinWarehouse::query()->where('code', $code)->where('is_wms_managed', true)->value('id');
            abort_unless($id, 422, "No warehouse {$code} is run by the warehouse module.");
            abort_unless(app(\App\Modules\Operations\WarehouseManagement\Services\WarehouseAccess::class)->mayUse($request->user(), (int) $id), 403, "You may not work in warehouse {$code}.");

            return [(int) $id];
        }

        $byToken = $allowed === [] ? null : FinWarehouse::query()->whereIn('code', $allowed)->pluck('id')->map(fn ($i) => (int) $i)->all();
        // And never beyond the warehouses the token's user may work in (WMS-200).
        $byUser = app(\App\Modules\Operations\WarehouseManagement\Services\WarehouseAccess::class)->visibleIds($request->user());

        return match (true) { $byToken === null => $byUser, $byUser === null => $byToken, default => array_values(array_intersect($byToken, $byUser)) };
    }

    private function findShipment(Request $request, string $number): Shipment
    {
        $s = Shipment::query()->where('number', $number)->with(['trackingEvents', 'packages:id,sscc'])->firstOrFail();
        $allowed = $this->warehouses($request, null);
        abort_if($allowed !== null && ! in_array((int) $s->warehouse_id, $allowed, true), 404);

        return $s;
    }

    private function presentShipment(Shipment $s): array
    {
        return ['shipment' => $s->number, 'status' => $s->status, 'carrier' => $s->carrier, 'service' => $s->service, 'tracking_reference' => $s->tracking_reference,
            'booking_reference' => $s->booking_reference, 'tracking_status' => $s->tracking_status, 'dispatched_at' => $s->dispatched_at?->toIso8601String(),
            'delivered_at' => $s->delivered_at?->toIso8601String(), 'received_by' => $s->pod_received_by, 'packages' => $s->packages->pluck('sscc')->all(),
            'tracking' => $s->trackingEvents->map(fn (TrackingEvent $t) => ['status' => $t->status, 'occurred_at' => $t->occurred_at?->toIso8601String(),
                'location' => $t->location, 'detail' => $t->detail, 'source' => $t->source])->all()];
    }

    private function validateHook(Request $request, bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return $request->validate(['name' => [$req, 'string', 'max:120'], 'url' => [$req, 'url', 'max:500'], 'event_types' => [$req, 'array', 'min:1'],
            'event_types.*' => [Rule::in(array_keys(WarehouseEvents::TYPES))], 'warehouses' => ['nullable', 'array'], 'warehouses.*' => ['string'],
            'is_active' => ['sometimes', 'boolean']]) + ($request->has('warehouses') ? ['warehouse_ids' => $request->input('warehouses')
                ? FinWarehouse::query()->whereIn('code', $request->input('warehouses'))->pluck('id')->all() : null] : []);
    }

    private function hook(Webhook $h): array
    {
        return ['id' => $h->uuid, 'name' => $h->name, 'url' => $h->url, 'event_types' => $h->event_types, 'secret_hint' => '…'.$h->secret_hint,
            'warehouses' => $h->warehouse_ids ? FinWarehouse::query()->whereIn('id', $h->warehouse_ids)->pluck('code')->all() : null,
            'is_active' => $h->is_active && ! $h->disabled_at, 'disabled_reason' => $h->disabled_reason, 'last_delivered_at' => $h->last_delivered_at?->toIso8601String()];
    }

    private function allow(Request $request, string $permission, string $ability): void
    {
        $user = $request->user();
        abort_unless($user->can($permission), 403, 'This user may not do that in the warehouse.');
        $token = $user->currentAccessToken();
        if ($token && method_exists($token, 'can') && ! $token->can($ability) && ! $token->can('*')) {
            abort(403, "This token lacks the {$ability} ability.");
        }
    }
}
