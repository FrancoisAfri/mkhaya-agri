<?php

namespace App\Modules\Agri\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Agri\Api\ApiAbilities;
use App\Modules\Agri\Api\ApiResources;
use App\Modules\Agri\Api\ApiSpec;
use App\Modules\Agri\Api\Webhooks;
use App\Modules\Agri\Models\Animal;
use App\Modules\Agri\Models\AnimalEvent;
use App\Modules\Agri\Models\ApiClient;
use App\Modules\Agri\Models\CropPlan;
use App\Modules\Agri\Models\Farm;
use App\Modules\Agri\Models\FarmField;
use App\Modules\Agri\Models\FarmStore;
use App\Modules\Agri\Models\FarmWorkOrder as WO;
use App\Modules\Agri\Models\FieldActivity;
use App\Modules\Agri\Models\ProduceLot;
use App\Modules\Agri\Models\WebhookSubscription;
use App\Modules\Agri\Services\FarmAccess;
use App\Modules\Agri\Services\FarmProfitability;
use App\Modules\Agri\Services\FarmRegistry;
use App\Modules\Agri\Services\FieldWork;
use App\Modules\Agri\Services\Livestock;
use App\Modules\Agri\Services\PostHarvest;
use App\Modules\Agri\Services\WorkOrders;
use App\Modules\Agri\Support\RoleCatalog as R;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * The public farm API, version 1 (/api/agri/v1). Reads go through ApiResources (the models, so the
 * tenant scope and the token's farms apply); writes go through the same services the screens use,
 * with the same checks, as the token's service user. Errors are problem documents.
 */
class FarmApiController extends Controller
{
    public function __construct(private readonly ApiResources $resources) {}

    /** GET /api/agri/v1/openapi.json — the contract; public, no token. */
    public function openapi(): JsonResponse
    {
        return response()->json(app(ApiSpec::class)->document(), 200, ['Access-Control-Allow-Origin' => '*'], JSON_UNESCAPED_SLASHES);
    }

    public function me(Request $request): JsonResponse
    {
        $client = $this->client($request);

        return response()->json(['data' => ['client' => $client->uuid, 'name' => $client->name, 'abilities' => $client->abilities,
            'farms' => $client->farm_ids === null ? 'all' : Farm::query()->whereIn('id', $client->farm_ids)->pluck('uuid')->all(),
            'service_user' => $request->user()->email, 'rate_per_minute' => (int) $client->rate_per_minute,
            'expires_at' => $client->expires_at?->utc()->toIso8601ZuluString()]]);
    }

    public function index(Request $request): JsonResponse
    {
        $resource = (string) $request->route('resource');
        $page = $this->resources->index($resource, $request->query());
        if ($request->query('format') === 'geojson' && ! empty($this->resources->definition($resource)['geojson'])) {
            return response()->json(ApiResources::featureCollection($page), 200, ['Content-Type' => 'application/geo+json']);
        }

        return response()->json($page + ['links' => ['next' => $page['meta']['next_cursor'] ? $request->fullUrlWithQuery(['cursor' => $page['meta']['next_cursor']]) : null]]);
    }

    public function show(Request $request): JsonResponse
    {
        // By name: the resource is a route default and would otherwise arrive in the id's place.
        return response()->json(['data' => $this->resources->show((string) $request->route('resource'), (string) $request->route('id'))]);
    }

    /** GET /lots/{id}/trace — where a lot came from (fields, inputs, operations) and where it went. */
    public function trace(string $id, PostHarvest $postHarvest): JsonResponse
    {
        /** @var ProduceLot $lot */
        $lot = $this->resources->find('lots', $id);

        return response()->json(['data' => ['lot' => $this->resources->rows('lots', collect([$lot]))[0],
            'backward' => collect($postHarvest->backward($lot))->except('lot')->all(), 'forward' => collect($postHarvest->forward($lot))->except('lot')->all()]]);
    }

    /** GET /stock — stock on hand in the farm stores the token reaches, by item, status and lot. */
    public function stock(Request $request): JsonResponse
    {
        $q = $request->query();
        $stores = FarmStore::query()->when(! empty($q['farm']), fn ($s) => $s->where('farm_id', $this->resources->idFor('farms', (string) $q['farm'])))->get(['id', 'uuid', 'farm_id', 'warehouse_id', 'name', 'kind']);
        $limit = $this->resources->limit($q);
        $rows = DB::table('fin_stock_positions as p')->join('fin_stock_items as i', 'i.id', '=', 'p.item_id')->leftJoin('fin_stock_lots as l', 'l.id', '=', 'p.lot_id')
            ->whereIn('p.warehouse_id', $stores->pluck('warehouse_id')->filter()->unique()->values()->all() ?: [0])->where('p.qty', '!=', 0)
            ->when(! empty($q['item']), fn ($w) => $w->where('i.code', (string) $q['item']))
            ->when(! empty($q['updated_since']), fn ($w) => $w->where('p.updated_at', '>=', $this->resources->moment($q['updated_since'])))
            ->when(! empty($q['cursor']), fn ($w) => $w->where('p.id', '>', $this->resources->decodeCursor((string) $q['cursor'])))
            ->orderBy('p.id')->limit($limit + 1)
            ->get(['p.id', 'p.warehouse_id', 'p.status', 'p.qty', 'p.updated_at', 'i.code', 'i.name', 'i.uom', 'i.avg_cost', 'i.standard_cost', 'l.lot_number']);
        $more = $rows->count() > $limit;
        $rows = $rows->take($limit);
        $farms = DB::table('farms')->whereIn('id', $stores->pluck('farm_id')->unique())->pluck('uuid', 'id');
        $byWarehouse = $stores->groupBy('warehouse_id');

        $data = $rows->map(function ($r) use ($byWarehouse, $farms) {
            $store = $byWarehouse[$r->warehouse_id]->first();
            $cost = (float) ($r->avg_cost ?: $r->standard_cost ?: 0);

            return ['farm' => $farms[$store->farm_id] ?? null, 'store' => $store->uuid, 'store_name' => $store->name, 'store_kind' => $store->kind,
                'item_code' => $r->code, 'item' => $r->name, 'uom' => $r->uom, 'status' => $r->status, 'lot' => $r->lot_number, 'quantity' => (float) $r->qty,
                'unit_cost' => round($cost, 4), 'value' => round($cost * (float) $r->qty, 2), 'updated_at' => ApiResources::value($r->updated_at, 'datetime')];
        })->values()->all();
        $next = $more ? $this->resources->encodeCursor((int) $rows->last()->id) : null;

        return response()->json(['data' => $data, 'meta' => ['limit' => $limit, 'count' => count($data), 'next_cursor' => $next, 'has_more' => $more],
            'links' => ['next' => $next ? $request->fullUrlWithQuery(['cursor' => $next]) : null]]);
    }

    /** GET /finance/summary — income, costs and margin per farm from the books (the farm-dimension journal lines). */
    public function financeSummary(Request $request, FarmProfitability $profitability): JsonResponse
    {
        $data = $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'], 'farm' => ['nullable', 'uuid']]);
        $from = $data['from'] ?? today()->startOfYear()->toDateString();
        $to = $data['to'] ?? today()->toDateString();
        $farms = Farm::query()->when(! empty($data['farm']), fn ($q) => $q->where('uuid', $data['farm']))->orderBy('code')->get();

        return response()->json(['data' => collect($profitability->summary($farms, $from, $to))->map(fn ($r) => ['farm' => $r['id']] + collect($r)->except('id')->all())->all(),
            'meta' => ['from' => $from, 'to' => $to, 'currency' => $farms->first()?->currency ?? 'ZAR',
                'basis' => 'Posted journal lines carrying the farm dimension, plus labour and machine time costed on completed work.']]);
    }

    /** POST /field-activities — plan a field activity (as the screen does: on an operational farm, a workable crop plan). */
    public function storeActivity(Request $request, FieldWork $work): JsonResponse
    {
        $data = $request->validate([
            'farm' => ['required', 'uuid'], 'field' => ['nullable', 'uuid'], 'crop_plan' => ['nullable', 'uuid'],
            'activity_type' => ['required', Rule::in(FieldActivity::TYPES)], 'title' => ['required', 'string', 'max:200'],
            'planned_on' => ['required', 'date_format:Y-m-d'], 'area_covered' => ['nullable', 'numeric', 'gt:0'], 'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $farm = $this->writableFarm($request, $data['farm']);
        $field = ! empty($data['field']) ? FarmField::query()->where('uuid', $data['field'])->where('farm_id', $farm->id)->first() : null;
        abort_if(! empty($data['field']) && ! $field, 422, 'That field is not on this farm.');
        $plan = ! empty($data['crop_plan']) ? CropPlan::query()->where('uuid', $data['crop_plan'])->first() : null;
        abort_if(! empty($data['crop_plan']) && ! $plan, 422, 'That crop plan is not one this token reaches.');

        $activity = $work->create($farm, $field, $plan, null, ['activity_type' => $data['activity_type'], 'title' => $data['title'], 'planned_on' => $data['planned_on'],
            'area_covered' => $data['area_covered'] ?? null, 'notes' => $data['notes'] ?? null], $request->user());

        return response()->json(['data' => $this->resources->rows('field-activities', collect([$activity->fresh()]))[0]], 201);
    }

    /** POST /animal-events — a birth, weaning, mating, pregnancy check, milk recording… on a tagged animal. */
    public function storeAnimalEvent(Request $request, Livestock $livestock): JsonResponse
    {
        $data = $request->validate(['animal' => ['required', 'uuid'], 'event_type' => ['required', Rule::in(array_diff(AnimalEvent::TYPES, ['exit']))],
            'occurred_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'], 'outcome' => ['nullable', 'string', 'max:200'], 'details' => ['nullable', 'array']]);
        /** @var Animal $animal */
        $animal = $this->resources->find('animals', $data['animal']);
        abort_unless(app(FarmAccess::class)->canWrite($request->user(), (int) $animal->farm_id), 403, 'This token\'s access to that farm is read-only.');
        $event = $livestock->event($animal, $data['event_type'], $data['occurred_on'], $request->user(), $data['outcome'] ?? null, $data['details'] ?? []);

        return response()->json(['data' => $this->resources->rows('animal-events', collect([$event->fresh()]))[0]], 201);
    }

    /** POST /work-orders/{id}/status — move a job on, with the same approval and verification rights as the screen. */
    public function workOrderStatus(Request $request, string $id, WorkOrders $orders): JsonResponse
    {
        $data = $request->validate(['to' => ['required', Rule::in(WO::STATUSES)], 'reason' => ['nullable', 'string', 'max:1000'], 'actual_output' => ['nullable', 'numeric', 'gte:0']]);
        /** @var WO $order */
        $order = $this->resources->find('work-orders', $id);
        $reopen = $order->status === WO::COMPLETED && $data['to'] === WO::IN_PROGRESS;
        $needs = $reopen ? [R::APPROVE_WORK_ORDERS, R::VERIFY_WORK_ORDERS] : match ($data['to']) {
            WO::APPROVED => [R::APPROVE_WORK_ORDERS],
            WO::COMPLETED, WO::REJECTED => [R::VERIFY_WORK_ORDERS],
            default => [R::MANAGE_WORK_ORDERS],
        };
        abort_unless(collect($needs)->contains(fn ($p) => $request->user()->can($p)), 403, 'This token may not move a job to '.str_replace('_', ' ', $data['to']).'.');
        abort_unless(app(FarmAccess::class)->canWrite($request->user(), (int) $order->farm_id), 403, 'This token\'s access to that farm is read-only.');
        $r = $orders->transition($order, $data['to'], $data['reason'] ?? null, $request->user(), ['actual_output' => $data['actual_output'] ?? null]);

        return response()->json(['data' => $this->resources->rows('work-orders', collect([$order->fresh()]))[0], 'meta' => ['warnings' => $r['warnings']]]);
    }

    // ── webhooks ─────────────────────────────────────────────────────────────────────────────

    public function webhookEvents(): JsonResponse
    {
        return response()->json(['data' => collect(Webhooks::EVENTS)->map(fn ($e, $k) => ['event' => $k, 'description' => $e[0]])->values()->all()]);
    }

    public function webhooks(Request $request, Webhooks $webhooks): JsonResponse
    {
        $client = $this->client($request);

        return response()->json(['data' => WebhookSubscription::query()->where('client_id', $client->id)->orderBy('id')->get()->map(fn ($s) => $webhooks->row($s))->all()]);
    }

    public function subscribe(Request $request, Webhooks $webhooks): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:120'], 'url' => ['required', 'url', 'max:500'], 'events' => ['required', 'array', 'min:1'], 'events.*' => ['string']]);
        $client = $this->client($request);
        abort_if(WebhookSubscription::query()->where('client_id', $client->id)->count() >= 20, 422, 'A client may hold at most 20 subscriptions.');
        ['subscription' => $s, 'secret' => $secret] = $webhooks->subscribe((int) $client->company_id, $data, $request->user(), $client->id);

        return response()->json(['data' => $webhooks->row($s) + ['secret' => $secret], 'meta' => ['note' => 'The secret is shown once. Verify X-Mkhaya-Signature with it.']], 201);
    }

    public function unsubscribe(Request $request, string $id): JsonResponse
    {
        $client = $this->client($request);
        abort_unless(Str::isUuid($id), 404, 'Not found.');
        $s = WebhookSubscription::query()->where('client_id', $client->id)->where('uuid', $id)->first() ?? abort(404, 'Not found.');
        $s->delete();

        return response()->json(null, 204);
    }

    private function client(Request $request): ApiClient
    {
        return $request->attributes->get('agri_api_client') ?? abort(401, 'Unauthenticated.');
    }

    private function writableFarm(Request $request, string $uuid): Farm
    {
        $farm = Farm::query()->where('uuid', $uuid)->first() ?? abort(404, 'That farm is not one this token reaches.');
        abort_unless(app(FarmAccess::class)->canWrite($request->user(), $farm->id), 403, 'This token\'s access to that farm is read-only.');
        app(FarmRegistry::class)->assertOperational($farm);

        return $farm;
    }

    /** For the OpenAPI document: what each ability gives. */
    public static function abilityDescriptions(): array
    {
        return collect(ApiAbilities::MAP)->map(fn ($perms, $a) => $perms ? 'Needs '.implode(', ', $perms) : 'Its own webhook subscriptions')->all();
    }
}
