<?php

namespace App\Modules\Agri\Http\Controllers;

use App\Modules\Agri\Models\CropPlan;
use App\Modules\Agri\Models\Farm;
use App\Modules\Agri\Models\FarmStore;
use App\Modules\Agri\Models\GrainSettlement;
use App\Modules\Agri\Models\GrainTicket;
use App\Modules\Agri\Models\GrainTicketPhoto;
use App\Modules\Agri\Models\SalesContract;
use App\Modules\Agri\Services\GrainGrading;
use App\Modules\Agri\Services\GrainLoads;
use App\Modules\Agri\Services\GrainSettlements;
use App\Modules\Agri\Services\Integrations;
use App\Modules\Agri\Support\RoleCatalog as R;
use App\Modules\Finance\Accounting\Models\FinParty;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\HR\Hr\Attendance\Models\ClockDevice;
use App\Modules\HR\Hr\Attendance\Services\ClockDevices;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Grain loads: the field log, the weighbridge (device and by hand), load tickets with their grade
 * and deductions, each bin's accountability, the lost-and-duplicate-load reconciliation, the
 * buyers' settlement statements matched to our tickets, and the contracts' balances. Also the
 * weighbridge's device endpoint, authenticated by the device's own secret.
 */
class GrainController extends AgriController
{
    public const TABS = ['loads', 'weighbridge', 'bins', 'reconciliation', 'settlements', 'contracts'];

    public function __construct(private readonly GrainLoads $loads, private readonly GrainSettlements $settlements) {}

    public function index(Request $request, string $tab = 'loads'): Response
    {
        abort_unless(in_array($tab, self::TABS, true), 404);
        $this->allow($request, R::VIEW_STOCK, R::RECORD_HARVEST);
        $user = $request->user();
        $farms = Farm::query()->operational()->orderBy('name')->get();
        $farm = $this->farmFilter($request) ?? $farms->first();
        $sales = $user->can(R::VIEW_SALES);
        abort_if(in_array($tab, ['settlements', 'contracts'], true) && ! $sales, 403, 'You do not have permission to do that.');
        app(GrainGrading::class)->ensureDefaults((int) $user->company_id);
        $stores = $farm ? FarmStore::query()->where('farm_id', $farm->id)->whereNotIn('kind', ['chemical_store', 'fuel_tank', 'workshop'])->orderBy('name')->get() : collect();

        return Inertia::render('Modules/Agri/Grain/Index', [
            'tab' => $tab,
            'farm' => $farm ? ['id' => $farm->uuid, 'name' => $farm->name] : null,
            'farms' => $farms->map(fn ($f) => ['id' => $f->uuid, 'name' => $f->name])->values(),
            'data' => match ($tab) {
                'loads' => ['tickets' => GrainTicket::query()->when($farm, fn ($q) => $q->where('farm_id', $farm->id))
                    ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
                    ->orderByRaw("CASE status WHEN 'weighed' THEN 0 WHEN 'weighing' THEN 1 WHEN 'dispatched' THEN 2 ELSE 3 END")->orderByDesc('id')->limit(200)->get()
                    ->map(fn ($t) => $this->loads->row($t))->values(), 'status' => $request->string('status')->value() ?: null],
                'weighbridge' => ['open' => GrainTicket::query()->when($farm, fn ($q) => $q->where('farm_id', $farm->id))->whereIn('status', ['weighing', 'weighed'])->orderBy('first_weighed_at')->get()
                    ->map(fn ($t) => $this->loads->row($t))->values(),
                    'flagged' => GrainTicket::query()->when($farm, fn ($q) => $q->where('farm_id', $farm->id))->whereNotNull('flags')->where('status', '!=', 'void')->orderByDesc('id')->limit(50)->get()
                        ->map(fn ($t) => $this->loads->row($t))->values(),
                    'devices' => ClockDevice::query()->where('kind', 'weighbridge')->when($farm, fn ($q) => $q->where('farm_id', $farm->id))->orderBy('name')->get()
                        ->map(fn ($d) => ['id' => $d->uuid, 'name' => $d->name, 'active' => (bool) $d->is_active, 'last_seen_at' => $d->last_seen_at?->format('Y-m-d H:i'),
                            'events' => $d->events_received, 'endpoint' => route('api.agri.devices.weighings', $d->uuid)])->values(),
                    'tolerance_pct' => GrainLoads::TARE_TOLERANCE * 100, 'max_gross_kg' => GrainLoads::MAX_GROSS_KG],
                'bins' => ['bins' => $stores->map(fn ($s) => $this->loads->bin($s, $request->input('from'), $request->input('to')))->values()],
                'reconciliation' => $this->loads->reconcile($farm?->id),
                'settlements' => ['settlements' => GrainSettlement::query()->orderByDesc('statement_date')->orderByDesc('id')->limit(50)->get()->map(fn ($s) => $this->settlements->row($s))->values()],
                'contracts' => ['contracts' => SalesContract::query()->whereIn('status', [...SalesContract::LIVE, 'completed'])
                    ->whereIn('crop_type_id', \App\Modules\Agri\Models\CropType::query()->whereIn('category', ['grain', 'oilseed', 'legume'])->pluck('id'))
                    ->orderByDesc('id')->get()->map(fn ($c) => $this->settlements->contractBalance($c))->values()],
            },
            'options' => [
                'plans' => $farm ? CropPlan::query()->where('farm_id', $farm->id)->whereIn('status', [CropPlan::PLANTED, CropPlan::HARVESTING])->with('cropType:id,name')->orderBy('code')->get()
                    ->map(fn ($p) => ['value' => $p->uuid, 'title' => "{$p->code} · {$p->cropType?->name}"])->values() : [],
                'stores' => $stores->map(fn ($s) => ['value' => $s->uuid, 'title' => $s->name])->values(),
                'buyers' => $sales || $user->can(R::RECORD_HARVEST) ? FinParty::customers()->where('is_active', true)->orderBy('name')->get(['id', 'name'])->map(fn ($b) => ['value' => $b->id, 'title' => $b->name])->values() : [],
                'contracts' => SalesContract::query()->where('status', 'active')->orderBy('reference')->get()->map(fn ($c) => ['value' => $c->uuid, 'title' => "{$c->reference} · {$c->product}", 'party_id' => $c->party_id])->values(),
                'items' => FinStockItem::query()->where('is_active', true)->whereIn('uom', ['t', 'kg'])->orderBy('name')->get(['id', 'code', 'name', 'uom'])
                    ->map(fn ($i) => ['value' => $i->id, 'title' => "{$i->name} ({$i->uom})"])->values(),
                'movements' => GrainTicket::MOVEMENTS,
            ],
            'can' => ['record' => $user->can(R::RECORD_HARVEST), 'sales' => $sales, 'manage_sales' => $user->can(R::MANAGE_SALES), 'devices' => $user->can(R::MANAGE_INTEGRATIONS)],
            'secret' => session('weighbridge_secret'),
        ]);
    }

    // ── loads ────────────────────────────────────────────────────────────────────────────────

    public function dispatchLoad(Request $request): RedirectResponse
    {
        $this->allow($request, R::RECORD_HARVEST);
        $data = $request->validate($this->ticketRules() + ['farm_id' => ['required', 'string'], 'dispatched_at' => ['nullable', 'date']]);
        $t = $this->loads->dispatch($this->workingFarm($request, $data['farm_id']), $this->quality($data), $request->user());

        return back()->with('success', "Load {$t->number} logged leaving; the weighbridge will find it by {$t->vehicle_reg}.");
    }

    public function capture(Request $request): RedirectResponse
    {
        $this->allow($request, R::RECORD_HARVEST);
        $data = $request->validate($this->ticketRules() + ['farm_id' => ['required', 'string'], 'gross_kg' => ['required', 'numeric', 'gt:0'], 'tare_kg' => ['required', 'numeric', 'gt:0'],
            'weighed_in_at' => ['nullable', 'date'], 'weighed_out_at' => ['nullable', 'date'], 'dispatched_at' => ['nullable', 'date']]);
        $t = $this->loads->capture($this->workingFarm($request, $data['farm_id']), $this->quality($data), $request->user());
        $this->storePhotos($request, $t);

        return back()->with('success', "Ticket {$t->number}: net {$t->net_kg} kg, settled {$t->settled_kg} kg, grade {$t->grade}.");
    }

    /** A weighing typed in at the bridge (the device is down, or there is none). */
    public function weighing(Request $request): RedirectResponse
    {
        $this->allow($request, R::RECORD_HARVEST);
        $data = $request->validate(['farm_id' => ['required', 'string'], 'vehicle_reg' => ['nullable', 'string', 'max:20'], 'mass_kg' => ['required', 'numeric', 'gt:0'],
            'kind' => ['nullable', Rule::in(['gross', 'tare'])], 'weighed_at' => ['nullable', 'date'], 'ticket' => ['nullable', 'string', 'max:60']]);
        $farm = $this->workingFarm($request, $data['farm_id']);
        ['ticket' => $t] = $this->loads->weigh((int) $farm->company_id, array_merge($data, ['farm_id' => $farm->id]), null, $request->user());

        return back()->with('success', $t->status === 'weighing' ? "First weighing on {$t->number}; the second pairs with it." : "Ticket {$t->number} is {$t->status}: net {$t->net_kg} kg.");
    }

    public function assess(Request $request, GrainTicket $ticket): RedirectResponse
    {
        $this->allow($request, R::RECORD_HARVEST);
        $this->assertWritable($request, $ticket->farm_id);
        $data = $request->validate($this->ticketRules(false));
        $t = $this->loads->assess($ticket, $this->quality($data), $request->user());
        $this->storePhotos($request, $t);

        return back()->with('success', $t->status === 'complete' ? "Ticket {$t->number} complete: settled {$t->settled_kg} kg, grade {$t->grade}." : "Ticket {$t->number} updated.");
    }

    public function void(Request $request, GrainTicket $ticket): RedirectResponse
    {
        $this->allow($request, R::RECORD_HARVEST);
        $this->assertWritable($request, $ticket->farm_id);
        $data = $request->validate(['reason' => ['required', 'string', 'max:300']]);
        $this->loads->void($ticket, $data['reason'], $request->user());

        return back()->with('success', "Ticket {$ticket->number} voided.");
    }

    public function photos(Request $request, GrainTicket $ticket): RedirectResponse
    {
        $this->allow($request, R::RECORD_HARVEST);
        $this->assertWritable($request, $ticket->farm_id);
        $request->validate(['photos' => ['required', 'array', 'min:1', 'max:6'], 'photos.*' => ['file', 'image', 'max:8192']]);
        $n = $this->storePhotos($request, $ticket);

        return back()->with('success', "{$n} photo(s) on {$ticket->number}.");
    }

    public function photo(Request $request, GrainTicketPhoto $photo): \Symfony\Component\HttpFoundation\Response
    {
        $this->allow($request, R::VIEW_STOCK, R::RECORD_HARVEST);

        return response(base64_decode($photo->makeVisible('data')->data), 200, ['Content-Type' => $photo->mime, 'Content-Disposition' => 'inline; filename="'.addslashes($photo->name).'"']);
    }

    /** A weighbridge on the farm, with its secret shown once. */
    public function registerWeighbridge(Request $request): RedirectResponse
    {
        $this->allow($request, R::MANAGE_INTEGRATIONS);
        $data = $request->validate(['farm_id' => ['required', 'string'], 'name' => ['required', 'string', 'max:120']]);
        [$device, $secret] = app(ClockDevices::class)->register($this->workingFarm($request, $data['farm_id']), $data['name'], 'weighbridge', $request->user());

        return back()->with('success', "Weighbridge {$device->name} registered. Copy its secret now: it is not shown again.")
            ->with('weighbridge_secret', ['name' => $device->name, 'secret' => $secret, 'endpoint' => route('api.agri.devices.weighings', $device->uuid)]);
    }

    // ── settlements ──────────────────────────────────────────────────────────────────────────

    public function storeSettlement(Request $request): RedirectResponse
    {
        $this->allow($request, R::MANAGE_SALES);
        $data = $request->validate($this->settlementRules() + ['lines' => ['required', 'array', 'min:1'], 'lines.*.buyer_ticket_ref' => ['nullable', 'string', 'max:60'],
            'lines.*.our_ticket_ref' => ['nullable', 'string', 'max:60'], 'lines.*.vehicle_reg' => ['nullable', 'string', 'max:20'], 'lines.*.delivered_on' => ['nullable', 'date'],
            'lines.*.gross_kg' => ['nullable', 'numeric'], 'lines.*.tare_kg' => ['nullable', 'numeric'], 'lines.*.net_kg' => ['nullable', 'numeric'],
            'lines.*.moisture_pct' => ['nullable', 'numeric', 'between:0,60'], 'lines.*.foreign_matter_pct' => ['nullable', 'numeric', 'between:0,60'], 'lines.*.grade' => ['nullable', 'string', 'max:16'],
            'lines.*.deductions_kg' => ['nullable', 'numeric'], 'lines.*.settled_kg' => ['nullable', 'numeric'], 'lines.*.price_per_t' => ['nullable', 'numeric'], 'lines.*.amount' => ['nullable', 'numeric']]);
        $s = $this->settlements->capture((int) $request->user()->company_id, $this->withFarm($request, $data), $data['lines'], $request->user());

        return back()->with('success', $this->matchedMessage($s));
    }

    public function importSettlement(Request $request): RedirectResponse
    {
        $this->allow($request, R::MANAGE_SALES);
        $data = $request->validate($this->settlementRules() + ['file' => ['required', 'file', 'max:2048', 'mimes:csv,txt'], 'mass_unit' => ['nullable', Rule::in(['kg', 't'])]]);
        $s = $this->settlements->import((int) $request->user()->company_id, $this->withFarm($request, $data), $request->file('file'), $request->user());

        return back()->with('success', $this->matchedMessage($s));
    }

    public function matchSettlement(Request $request, GrainSettlement $settlement): RedirectResponse
    {
        $this->allow($request, R::MANAGE_SALES);
        $s = $this->settlements->match($settlement);

        return back()->with('success', $this->matchedMessage($s));
    }

    public function postSettlement(Request $request, GrainSettlement $settlement): RedirectResponse
    {
        $this->allow($request, R::MANAGE_SALES);
        $s = $this->settlements->post($settlement, $request->user());

        return back()->with('success', "Statement {$s->reference} posted: R ".number_format((float) ($s->totals['net_value'] ?? 0), 2, '.', ' ').' owed by the buyer, in '.count($s->journal_ids ?? []).' journal(s).');
    }

    // ── the device ───────────────────────────────────────────────────────────────────────────

    /**
     * POST /api/agri/devices/{uuid}/weighings — {"weighings": [{id, vehicle_reg, mass_kg, kind: gross|tare, weighed_at, ticket}]}.
     * Each weighing through the integration log: kept first, processed once, a repeat answered from the first.
     */
    public function deviceWeighings(Request $request, string $uuid, TenantContext $tenants): JsonResponse
    {
        $device = app(ClockDevices::class)->authenticate($uuid, $request->bearerToken());
        if (! $device || $device->kind !== 'weighbridge') {
            return response()->json(['error' => 'Unknown or switched-off weighbridge, or a wrong secret.'], 401);
        }
        $weighings = $request->input('weighings');
        if (! is_array($weighings) || ! $weighings || count($weighings) > 200) {
            return response()->json(['error' => 'Send "weighings": a list of 1 to 200 weighings.'], 422);
        }

        return $tenants->run((int) $device->company_id, function () use ($device, $weighings) {
            $log = app(Integrations::class);
            $out = [];
            foreach ($weighings as $k => $w) {
                $w = is_array($w) ? $w : [];
                $key = isset($w['id']) ? (string) $w['id'] : 'h:'.sha1(json_encode($w));
                ['event' => $e, 'replayed' => $replayed] = $log->receive((int) $device->company_id, 'weighbridge', "{$device->id}:{$key}", array_merge($w, ['external_id' => "{$device->id}:{$key}"]), $device->id, $device->farm_id);
                $out[] = ['index' => $k, 'ok' => $e->status === 'processed', 'replayed' => $replayed, 'ticket' => $e->result['ticket'] ?? null, 'status' => $e->result['status'] ?? null,
                    'net_kg' => $e->result['net_kg'] ?? null, 'flags' => $e->result['flags'] ?? [], 'error' => $e->status === 'processed' ? null : $e->error];
            }
            $device->update(['last_seen_at' => now(), 'events_received' => (int) $device->events_received + count($weighings)]);

            return response()->json(['results' => $out]);
        });
    }

    // ── helpers ──────────────────────────────────────────────────────────────────────────────

    private function ticketRules(bool $create = true): array
    {
        return ['movement' => [$create ? 'required' : 'nullable', Rule::in(GrainTicket::MOVEMENTS)], 'crop_plan_id' => ['nullable', 'string'], 'to_store_id' => ['nullable', 'string'],
            'from_store_id' => ['nullable', 'string'], 'stock_item_id' => ['nullable', 'integer'], 'party_id' => ['nullable', 'integer'], 'contract_id' => ['nullable', 'string'],
            'destination_name' => ['nullable', 'string', 'max:160'], 'vehicle_reg' => [$create ? 'required' : 'nullable', 'string', 'max:20'], 'trailer_reg' => ['nullable', 'string', 'max:20'],
            'driver' => ['nullable', 'string', 'max:120'], 'load_ref' => ['nullable', 'string', 'max:40'], 'moisture_pct' => ['nullable', 'numeric', 'between:0,60'],
            'foreign_matter_pct' => ['nullable', 'numeric', 'between:0,60'], 'quality' => ['nullable', 'array'], 'quality.*' => ['nullable', 'numeric'], 'grade' => ['nullable', 'string', 'max:16'],
            'buyer_ticket_ref' => ['nullable', 'string', 'max:60'], 'unit_value' => ['nullable', 'numeric', 'gte:0'], 'notes' => ['nullable', 'string', 'max:1000'],
            'photos' => ['nullable', 'array', 'max:6'], 'photos.*' => ['file', 'image', 'max:8192'],
            'defects_pct' => ['nullable', 'numeric', 'between:0,100'], 'other_colour_pct' => ['nullable', 'numeric', 'between:0,100'],
            'protein_pct' => ['nullable', 'numeric', 'between:0,40'], 'hectolitre_kg' => ['nullable', 'numeric', 'between:30,100']];
    }

    /** The grader's other readings, sent as fields of their own, as the ticket's quality. */
    private function quality(array $data): array
    {
        $q = array_filter(array_intersect_key($data, array_flip(['defects_pct', 'other_colour_pct', 'protein_pct', 'hectolitre_kg'])), fn ($v) => $v !== null && $v !== '');
        if ($q) {
            $data['quality'] = array_merge((array) ($data['quality'] ?? []), $q);
        }

        return $data;
    }

    private function settlementRules(): array
    {
        return ['party_id' => ['required', 'integer', 'exists:fin_parties,id'], 'contract_id' => ['nullable', 'string'], 'farm_id' => ['nullable', 'string'],
            'reference' => ['required', 'string', 'max:60'], 'statement_date' => ['required', 'date'], 'period_from' => ['nullable', 'date'], 'period_to' => ['nullable', 'date', 'after_or_equal:period_from'],
            'charges' => ['nullable', 'numeric', 'gte:0'], 'charges_note' => ['nullable', 'string', 'max:300']];
    }

    private function withFarm(Request $request, array $data): array
    {
        $data['farm_id'] = ! empty($data['farm_id']) ? $this->workingFarm($request, $data['farm_id'])->id : null;

        return $data;
    }

    private function storePhotos(Request $request, GrainTicket $t): int
    {
        $n = 0;
        foreach ((array) $request->file('photos', []) as $file) {
            $this->loads->photo($t, $file, $request->user());
            $n++;
        }

        return $n;
    }

    private function matchedMessage(GrainSettlement $s): string
    {
        $t = $s->totals ?? [];

        return "Statement {$s->reference}: {$t['matched']} matched, {$t['differs']} with differences, {$t['unmatched']} with no load of ours, {$t['duplicate']} duplicate.";
    }
}
