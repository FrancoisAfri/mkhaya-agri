<?php

namespace App\Modules\Assets\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Assets\Models\AssetLocation;
use App\Modules\Assets\Models\AssetPart;
use App\Modules\Assets\Models\AssetSupplier;
use App\Modules\Assets\Services\AssetPartStockService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Spare parts / MRO inventory. Thin controller — atomic stock movements are
 * delegated to AssetPartStockService.
 */
class AssetPartController extends Controller
{
    public function __construct(private readonly AssetPartStockService $stock) {}

    public function index(Request $request): Response
    {
        $query = AssetPart::query()->with(['location', 'supplier']);

        if ($category = $request->query('category')) {
            $query->where('category', $category);
        }

        if ($request->boolean('low_stock')) {
            $query->lowStock();
        }

        if ($search = trim((string) $request->query('search', ''))) {
            $query->where(function ($q) use ($search) {
                $q->where('part_number', 'ilike', "%{$search}%")
                    ->orWhere('name', 'ilike', "%{$search}%");
            });
        }

        $parts = $query->orderBy('name')->paginate(20)->withQueryString()
            ->through(fn (AssetPart $p) => $this->transform($p));

        // Stats over the whole (unfiltered, tenant-scoped) catalogue.
        $all = AssetPart::query();

        $stats = [
            'total_parts'      => (clone $all)->count(),
            'low_stock_count'  => AssetPart::query()->lowStock()->count(),
            'total_stock_value' => round(
                (float) (clone $all)->selectRaw('COALESCE(SUM(quantity_on_hand * unit_cost), 0) AS v')->value('v'),
                2,
            ),
        ];

        return Inertia::render('Modules/Assets/Parts/Index', [
            'parts'      => $parts,
            'stats'      => $stats,
            'categories' => $this->categories(),
            'filters'    => [
                'category'  => $category,
                'low_stock' => $request->boolean('low_stock'),
                'search'    => $search,
            ],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Modules/Assets/Parts/Create', [
            'locations' => $this->locationOptions(),
            'suppliers' => $this->supplierOptions(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validatePart($request);

        $part = AssetPart::create($data);

        return redirect()
            ->route('assets.parts.show', $part->uuid)
            ->with('success', 'Part created.');
    }

    public function show(AssetPart $part): Response
    {
        $part->load(['location', 'supplier']);

        $transactions = $part->transactions()
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit(200)
            ->get()
            ->map(fn ($t) => [
                'id'            => $t->id,
                'uuid'          => $t->uuid,
                'type'          => $t->type,
                'quantity'      => (float) $t->quantity,
                'balance_after' => (float) $t->balance_after,
                'unit_cost'     => $t->unit_cost !== null ? (float) $t->unit_cost : null,
                'reference'     => $t->reference,
                'work_order_id' => $t->work_order_id,
                'notes'         => $t->notes,
                'occurred_at'   => optional($t->occurred_at)->toIso8601String(),
            ]);

        return Inertia::render('Modules/Assets/Parts/Show', [
            'part'         => $this->transform($part),
            'transactions' => $transactions,
        ]);
    }

    public function update(Request $request, AssetPart $part): RedirectResponse
    {
        $data = $this->validatePart($request);

        // quantity_on_hand is never edited here — it only moves via stock actions.
        $part->update($data);

        return back()->with('success', 'Part updated.');
    }

    public function receive(Request $request, AssetPart $part): RedirectResponse
    {
        $data = $this->validateMovement($request);
        $this->stock->receive($part, (float) $data['quantity'], $data);

        return back()->with('success', 'Stock received.');
    }

    public function issue(Request $request, AssetPart $part): RedirectResponse
    {
        $data = $this->validateMovement($request);
        $this->stock->issue($part, (float) $data['quantity'], $data);

        return back()->with('success', 'Stock issued.');
    }

    public function adjust(Request $request, AssetPart $part): RedirectResponse
    {
        $data = $request->validate([
            'quantity'    => ['required', 'numeric', 'min:0'],
            'reference'   => ['nullable', 'string', 'max:255'],
            'notes'       => ['nullable', 'string', 'max:2000'],
            'occurred_at' => ['nullable', 'date'],
        ]);

        // For adjustments the submitted quantity is the new absolute on-hand count.
        $this->stock->adjust($part, (float) $data['quantity'], $data);

        return back()->with('success', 'Stock adjusted.');
    }

    /**
     * Reorder report: parts at/below their reorder level.
     */
    public function lowStock(): Response
    {
        $parts = AssetPart::query()
            ->with(['location', 'supplier'])
            ->lowStock()
            ->orderBy('name')
            ->get()
            ->map(fn (AssetPart $p) => $this->transform($p));

        return Inertia::render('Modules/Assets/Parts/Index', [
            'parts'      => ['data' => $parts, 'links' => [], 'meta' => null],
            'stats'      => [
                'total_parts'       => AssetPart::query()->count(),
                'low_stock_count'   => $parts->count(),
                'total_stock_value' => round(
                    (float) AssetPart::query()->selectRaw('COALESCE(SUM(quantity_on_hand * unit_cost), 0) AS v')->value('v'),
                    2,
                ),
            ],
            'categories' => $this->categories(),
            'filters'    => ['category' => null, 'low_stock' => true, 'search' => ''],
            'reorderView' => true,
        ]);
    }

    // ── helpers ────────────────────────────────────────────────────────────────

    private function transform(AssetPart $p): array
    {
        return [
            'id'               => $p->id,
            'uuid'             => $p->uuid,
            'part_number'      => $p->part_number,
            'name'             => $p->name,
            'description'      => $p->description,
            'category'         => $p->category,
            'unit_of_measure'  => $p->unit_of_measure,
            'unit_cost'        => (float) $p->unit_cost,
            'quantity_on_hand' => (float) $p->quantity_on_hand,
            'reorder_level'    => (float) $p->reorder_level,
            'reorder_quantity' => (float) $p->reorder_quantity,
            'status'           => $p->status,
            'notes'            => $p->notes,
            'is_low_stock'     => $p->isLowStock(),
            'stock_value'      => $p->stockValue(),
            'location'         => $p->location ? ['id' => $p->location->id, 'name' => $p->location->name] : null,
            'supplier'         => $p->supplier ? ['id' => $p->supplier->id, 'name' => $p->supplier->supplier_name] : null,
            'location_id'      => $p->location_id,
            'supplier_id'      => $p->supplier_id,
        ];
    }

    private function validatePart(Request $request): array
    {
        return $request->validate([
            'part_number'      => ['required', 'string', 'max:255'],
            'name'             => ['required', 'string', 'max:255'],
            'description'      => ['nullable', 'string', 'max:5000'],
            'category'         => ['nullable', 'string', 'max:255'],
            'unit_of_measure'  => ['required', 'string', 'max:50'],
            'unit_cost'        => ['required', 'numeric', 'min:0'],
            'reorder_level'    => ['required', 'numeric', 'min:0'],
            'reorder_quantity' => ['required', 'numeric', 'min:0'],
            'location_id'      => ['nullable', 'integer'],
            'supplier_id'      => ['nullable', 'integer'],
            'status'           => ['required', 'string', 'in:active,inactive,discontinued'],
            'notes'            => ['nullable', 'string', 'max:5000'],
        ]);
    }

    private function validateMovement(Request $request): array
    {
        return $request->validate([
            'quantity'      => ['required', 'numeric', 'gt:0'],
            'unit_cost'     => ['nullable', 'numeric', 'min:0'],
            'work_order_id' => ['nullable', 'integer'],
            'reference'     => ['nullable', 'string', 'max:255'],
            'notes'         => ['nullable', 'string', 'max:2000'],
            'occurred_at'   => ['nullable', 'date'],
        ]);
    }

    private function categories(): array
    {
        return AssetPart::query()
            ->whereNotNull('category')
            ->where('category', '!=', '')
            ->distinct()
            ->orderBy('category')
            ->pluck('category')
            ->values()
            ->all();
    }

    private function locationOptions(): array
    {
        return AssetLocation::query()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($l) => ['value' => $l->id, 'title' => $l->name])
            ->all();
    }

    private function supplierOptions(): array
    {
        return AssetSupplier::query()
            ->orderBy('supplier_name')
            ->get(['id', 'supplier_name'])
            ->map(fn ($s) => ['value' => $s->id, 'title' => $s->supplier_name])
            ->all();
    }
}
