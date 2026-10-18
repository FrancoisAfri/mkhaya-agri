<?php

namespace App\Modules\Operations\Manufacturing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Operations\Manufacturing\Models\Bom;
use App\Modules\Operations\Manufacturing\Models\BomApproval;
use App\Modules\Operations\Manufacturing\Models\BomLine;
use App\Modules\Operations\Manufacturing\Models\Item;
use App\Modules\Operations\Manufacturing\Models\Uom;
use App\Modules\Operations\Manufacturing\Services\BomExploder;
use App\Modules\Operations\Manufacturing\Services\BomVersioning;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Bills of materials (MFG-002).
 *
 * Every write that touches the recipe goes through a draft. The database refuses an edit to an
 * approved revision outright, so this controller's job is to make the right thing easy: a screen
 * on an approved BOM offers "Revise" rather than "Edit", and never shows a save button that would
 * raise a database exception.
 *
 * DomainException from the services is the expected failure here -- an empty BOM, a self-approval,
 * a loop in the explosion -- so it comes back as a flash message naming the problem, not a 500.
 */
class BomController extends Controller
{
    use \App\Modules\Operations\Manufacturing\Concerns\ScopesValidationToTenant;

    public function __construct(
        private readonly BomVersioning $versioning,
        private readonly BomExploder $exploder,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Bom::class);

        $filters = $request->validate([
            'status' => ['nullable', Rule::in(Bom::STATUSES)],
            'search' => ['nullable', 'string', 'max:120'],
        ]);

        $boms = Bom::query()
            ->with(['item.stockItem:id,code,name', 'yieldUom:id,code'])
            ->withCount('lines')
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['search'] ?? null, fn ($q, $search) => $q->whereHas(
                'item.stockItem',
                fn ($s) => $s->where('code', 'ilike', "%{$search}%")->orWhere('name', 'ilike', "%{$search}%")
            ))
            ->orderByDesc('updated_at')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Bom $bom) => $this->summarise($bom));

        return Inertia::render('Modules/Manufacturing/Engineering/Boms', [
            'boms' => $boms,
            'filters' => $filters,
            'statuses' => Bom::STATUSES,
            // Only things that can be made may carry a recipe. A raw material is bought.
            'manufacturable' => Item::query()
                ->whereIn('kind', Item::MANUFACTURED_KINDS)
                ->where('is_active', true)
                ->with('stockItem:id,code,name')
                ->orderBy('id')
                ->get()
                ->map(fn (Item $i) => [
                    'id' => $i->uuid,
                    'code' => $i->stockItem?->code,
                    'name' => $i->stockItem?->name,
                    'kind' => $i->kind,
                ]),
            'units' => $this->units(),
            'can' => ['manage' => $request->user()->can('create', Bom::class)],
        ]);
    }

    public function show(Request $request, string $uuid): Response
    {
        $bom = $this->findBom($uuid);
        $this->authorize('view', $bom);

        $bom->load([
            'item.stockItem:id,code,name,uom',
            'yieldUom:id,code,name',
            'lines.uom:id,code,name',
            'lines.component:id,code,name',
            'lines.alternates',
            'approvals.bom',
            'supersededBy:id,uuid,revision',
        ]);

        $user = $request->user();

        return Inertia::render('Modules/Manufacturing/Engineering/BomShow', [
            // MFG-023: the record's documents, through Document Management's own visibility rules.
            'documents_panel' => \App\Modules\Operations\DocumentManagement\Support\DocumentModule::panelFor($bom, $request->user()),
            'bom' => $this->detail($bom),
            // Other revisions of the same product, for the comparison control.
            'siblings' => Bom::query()
                ->where('item_id', $bom->item_id)
                ->where('id', '!=', $bom->id)
                ->orderByDesc('revision')
                ->get(['uuid', 'revision', 'status'])
                ->map(fn ($b) => ['id' => $b->uuid, 'revision' => (int) $b->revision, 'status' => $b->status]),
            'components' => FinStockItem::query()->orderBy('code')->limit(300)
                ->get(['id', 'code', 'name', 'uom'])
                ->map(fn (FinStockItem $i) => [
                    'id' => $i->id, 'code' => $i->code, 'name' => $i->name, 'uom' => $i->uom,
                ]),
            'units' => $this->units(),
            'line_kinds' => BomLine::KINDS,
            /*
             * The screen is driven by these rather than by re-deriving the status in Vue. An
             * approved BOM offers Revise and not Edit, which is the whole point of the
             * immutability rule being visible rather than a surprise at save time.
             */
            'can' => [
                'edit' => $user->can('update', $bom),
                'approve' => $user->can('approve', $bom),
                'revise' => $user->can('revise', $bom),
                'obsolete' => $user->can('obsolete', $bom),
                'delete' => $user->can('delete', $bom),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Bom::class);

        $data = $request->validate([
            'item_uuid' => ['required', 'string', $this->tenantExists('mfg_items', 'uuid', true)],
            'name' => ['nullable', 'string', 'max:255'],
            'yield_quantity' => ['required', 'numeric', 'gt:0'],
            'yield_uom_id' => ['nullable', 'integer', $this->tenantExists('mfg_uoms', 'id')],
            'batch_size' => ['nullable', 'numeric', 'gt:0'],
            'process_loss_percent' => ['nullable', 'numeric', 'min:0', 'lt:100'],
            'notes' => ['nullable', 'string'],
        ]);

        $item = Item::query()->where('uuid', $data['item_uuid'])->firstOrFail();

        if (! $item->isManufactured()) {
            return back()->with('error',
                'Only a finished product, intermediate or subassembly can carry a bill of materials.');
        }

        $revision = (int) Bom::query()->where('item_id', $item->id)->max('revision') + 1;

        $bom = Bom::create([
            'company_id' => $item->company_id,
            'item_id' => $item->id,
            'revision' => $revision,
            'name' => $data['name'] ?? null,
            'status' => Bom::DRAFT,
            'yield_quantity' => $data['yield_quantity'],
            'yield_uom_id' => $data['yield_uom_id'] ?? $item->default_uom_id,
            'batch_size' => $data['batch_size'] ?? null,
            'process_loss_percent' => $data['process_loss_percent'] ?? 0,
            'notes' => $data['notes'] ?? null,
            'created_by' => $request->user()->id,
        ]);

        return redirect()->route('manufacturing.engineering.boms.show', $bom->uuid)
            ->with('success', "Revision {$revision} drafted. Add its components, then submit it.");
    }

    public function update(Request $request, string $uuid): RedirectResponse
    {
        $bom = $this->findBom($uuid);
        $this->authorize('update', $bom);

        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'yield_quantity' => ['sometimes', 'numeric', 'gt:0'],
            'yield_uom_id' => ['nullable', 'integer', $this->tenantExists('mfg_uoms', 'id')],
            'batch_size' => ['nullable', 'numeric', 'gt:0'],
            'process_loss_percent' => ['nullable', 'numeric', 'min:0', 'lt:100'],
            'effective_from' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ]);

        $bom->update($data);

        return back()->with('success', 'Draft updated.');
    }

    public function destroy(string $uuid): RedirectResponse
    {
        $bom = $this->findBom($uuid);
        $this->authorize('delete', $bom);

        $bom->delete();

        return redirect()->route('manufacturing.engineering.boms.index')
            ->with('success', 'Draft discarded.');
    }

    // ── Lines ────────────────────────────────────────────────────────────────────────────────

    public function storeLine(Request $request, string $uuid): RedirectResponse
    {
        $bom = $this->findBom($uuid);
        $this->authorize('update', $bom);

        $data = $request->validate([
            'component_stock_item_id' => ['required', 'integer', $this->tenantExists('fin_stock_items', 'id')],
            'quantity_per' => ['required', 'numeric', 'gt:0'],
            'uom_id' => ['nullable', 'integer', $this->tenantExists('mfg_uoms', 'id')],
            // 100% scrap has no finite requirement, so the ceiling is exclusive.
            'scrap_percent' => ['nullable', 'numeric', 'min:0', 'lt:100'],
            'is_phantom' => ['nullable', 'boolean'],
            'line_kind' => ['required', Rule::in(BomLine::KINDS)],
            'alternate_group' => ['nullable', 'string', 'max:32'],
            'is_optional' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string'],
        ]);

        // A product cannot be a direct component of itself; the exploder catches deeper loops.
        if ((int) $data['component_stock_item_id'] === (int) $bom->item->stock_item_id) {
            return back()->with('error', 'A product cannot be a component of itself.');
        }

        $lineNo = (int) $bom->lines()->max('line_no') + 10;

        $bom->lines()->create($data + [
            'company_id' => $bom->company_id,
            'line_no' => $lineNo,
            'scrap_percent' => $data['scrap_percent'] ?? 0,
        ]);

        return back()->with('success', 'Component added.');
    }

    public function updateLine(Request $request, string $uuid, string $lineUuid): RedirectResponse
    {
        $bom = $this->findBom($uuid);
        $this->authorize('update', $bom);

        $line = $bom->lines()->where('uuid', $lineUuid)->firstOrFail();

        $data = $request->validate([
            'quantity_per' => ['sometimes', 'numeric', 'gt:0'],
            'uom_id' => ['nullable', 'integer', $this->tenantExists('mfg_uoms', 'id')],
            'scrap_percent' => ['nullable', 'numeric', 'min:0', 'lt:100'],
            'is_phantom' => ['nullable', 'boolean'],
            'line_kind' => ['sometimes', Rule::in(BomLine::KINDS)],
            'alternate_group' => ['nullable', 'string', 'max:32'],
            'is_optional' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string'],
        ]);

        $line->update($data);

        return back()->with('success', 'Component updated.');
    }

    public function destroyLine(string $uuid, string $lineUuid): RedirectResponse
    {
        $bom = $this->findBom($uuid);
        $this->authorize('update', $bom);

        $bom->lines()->where('uuid', $lineUuid)->firstOrFail()->delete();

        return back()->with('success', 'Component removed.');
    }

    public function storeAlternate(Request $request, string $uuid, string $lineUuid): RedirectResponse
    {
        $bom = $this->findBom($uuid);
        $this->authorize('update', $bom);

        $line = $bom->lines()->where('uuid', $lineUuid)->firstOrFail();

        $data = $request->validate([
            'stock_item_id' => ['required', 'integer', $this->tenantExists('fin_stock_items', 'id')],
            'ratio' => ['required', 'numeric', 'gt:0'],
            'priority' => ['nullable', 'integer', 'min:1'],
            'requires_approval' => ['nullable', 'boolean'],
        ]);

        $line->alternates()->create($data + [
            'company_id' => $bom->company_id,
            'priority' => $data['priority'] ?? 1,
            'requires_approval' => $data['requires_approval'] ?? true,
        ]);

        return back()->with('success', 'Alternative component recorded.');
    }

    // ── Lifecycle ────────────────────────────────────────────────────────────────────────────

    public function submit(Request $request, string $uuid): RedirectResponse
    {
        $bom = $this->findBom($uuid);
        $this->authorize('update', $bom);

        return $this->attempt(fn () => $this->versioning->submit(
            $bom, $request->user()->id, $request->string('comment')->toString() ?: null
        ), 'Submitted for approval.');
    }

    public function approve(Request $request, string $uuid): RedirectResponse
    {
        $bom = $this->findBom($uuid);
        $this->authorize('approve', $bom);

        return $this->attempt(fn () => $this->versioning->approve(
            $bom, $request->user()->id, $request->string('comment')->toString() ?: null
        ), 'Revision approved and made the default.');
    }

    public function reject(Request $request, string $uuid): RedirectResponse
    {
        $bom = $this->findBom($uuid);
        $this->authorize('approve', $bom);

        $reason = $request->validate(['reason' => ['required', 'string', 'max:2000']])['reason'];

        return $this->attempt(
            fn () => $this->versioning->reject($bom, $request->user()->id, $reason),
            'Revision rejected.'
        );
    }

    public function revise(Request $request, string $uuid): RedirectResponse
    {
        $bom = $this->findBom($uuid);
        $this->authorize('revise', $bom);

        try {
            $draft = $this->versioning->revise(
                $bom, $request->user()->id, $request->string('reason')->toString() ?: null
            );
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('manufacturing.engineering.boms.show', $draft->uuid)
            ->with('success', "Revision {$draft->revision} drafted from revision {$bom->revision}.");
    }

    public function obsolete(Request $request, string $uuid): RedirectResponse
    {
        $bom = $this->findBom($uuid);
        $this->authorize('obsolete', $bom);

        return $this->attempt(fn () => $this->versioning->obsolete(
            $bom, $request->user()->id, $request->string('reason')->toString() ?: null
        ), 'Revision retired.');
    }

    // ── Analysis ─────────────────────────────────────────────────────────────────────────────

    public function explode(Request $request, string $uuid): Response
    {
        $bom = $this->findBom($uuid);
        $this->authorize('view', $bom);

        $input = $request->validate([
            'quantity' => ['nullable', 'numeric', 'gt:0'],
            'whole_batches' => ['nullable', 'boolean'],
        ]);

        $quantity = (float) ($input['quantity'] ?? $bom->yield_quantity ?? 1);
        $wholeBatches = (bool) ($input['whole_batches'] ?? true);

        $bom->load(['item.stockItem:id,code,name', 'lines.uom', 'lines.component:id,code,name', 'yieldUom']);

        $result = null;
        $error = null;

        try {
            $result = $this->exploder->explode($bom, $quantity, $wholeBatches);
        } catch (DomainException $e) {
            // A loop or a phantom with nothing behind it. Named, not a 500.
            $error = $e->getMessage();
        }

        return Inertia::render('Modules/Manufacturing/Engineering/BomExplode', [
            'bom' => $this->summarise($bom),
            'quantity' => $quantity,
            'whole_batches' => $wholeBatches,
            'result' => $result,
            'error' => $error,
        ]);
    }

    public function compare(string $uuid, string $otherUuid): Response
    {
        $left = $this->findBom($uuid);
        $right = $this->findBom($otherUuid);
        $this->authorize('view', $left);

        if ($left->item_id !== $right->item_id) {
            abort(404, 'Those two revisions belong to different products.');
        }

        return Inertia::render('Modules/Manufacturing/Engineering/BomCompare', [
            'left' => $this->summarise($left),
            'right' => $this->summarise($right),
            'diff' => $this->exploder->compare($left, $right),
        ]);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────────────────────

    /** Run a service call, turning its domain refusals into a flash message rather than a 500. */
    private function attempt(callable $work, string $success): RedirectResponse
    {
        try {
            $work();
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $success);
    }

    private function findBom(string $uuid): Bom
    {
        return Bom::query()->with('item')->where('uuid', $uuid)->firstOrFail();
    }

    /** @return array<int, array<string, mixed>> */
    private function units(): array
    {
        return Uom::query()->where('is_active', true)->orderBy('dimension')->orderBy('code')
            ->get(['id', 'code', 'name', 'dimension', 'decimal_places'])
            ->map(fn (Uom $u) => [
                'id' => $u->id, 'code' => $u->code, 'name' => $u->name,
                'dimension' => $u->dimension, 'decimal_places' => (int) $u->decimal_places,
            ])->all();
    }

    private function summarise(Bom $bom): array
    {
        return [
            'id' => $bom->uuid,
            'revision' => (int) $bom->revision,
            'name' => $bom->name,
            'status' => $bom->status,
            'is_default' => (bool) $bom->is_default,
            'product_code' => $bom->item?->stockItem?->code,
            'product_name' => $bom->item?->stockItem?->name,
            'product_id' => $bom->item?->uuid,
            'yield_quantity' => (float) $bom->yield_quantity,
            'yield_uom' => $bom->yieldUom?->code,
            'batch_size' => $bom->batch_size !== null ? (float) $bom->batch_size : null,
            'process_loss_percent' => (float) $bom->process_loss_percent,
            'effective_from' => $bom->effective_from?->toDateString(),
            'effective_to' => $bom->effective_to?->toDateString(),
            'lines_count' => (int) ($bom->lines_count ?? $bom->lines()->count()),
            'is_editable' => $bom->isEditable(),
        ];
    }

    private function detail(Bom $bom): array
    {
        return $this->summarise($bom) + [
            'notes' => $bom->notes,
            'approved_at' => $bom->approved_at?->toIso8601String(),
            'superseded_by' => $bom->supersededBy
                ? ['id' => $bom->supersededBy->uuid, 'revision' => (int) $bom->supersededBy->revision]
                : null,
            'lines' => $bom->lines->map(fn (BomLine $line) => [
                'id' => $line->uuid,
                'line_no' => (int) $line->line_no,
                'component_stock_item_id' => $line->component_stock_item_id,
                'component_code' => $line->component?->code,
                'component_name' => $line->component?->name,
                'quantity_per' => (float) $line->quantity_per,
                // Shown next to the net figure, because the difference is what gets issued.
                'gross_quantity_per' => $this->grossOrNull($line),
                'uom' => $line->uom?->code,
                'uom_id' => $line->uom_id,
                'scrap_percent' => (float) $line->scrap_percent,
                'is_phantom' => (bool) $line->is_phantom,
                'line_kind' => $line->line_kind,
                'alternate_group' => $line->alternate_group,
                'is_optional' => (bool) $line->is_optional,
                'notes' => $line->notes,
                'alternates' => $line->alternates->map(fn ($a) => [
                    'id' => $a->uuid,
                    'stock_item_id' => $a->stock_item_id,
                    'ratio' => (float) $a->ratio,
                    'priority' => (int) $a->priority,
                    'is_usable' => $a->isUsable(),
                ])->all(),
            ])->all(),
            'history' => $bom->approvals->map(fn (BomApproval $a) => [
                'id' => $a->uuid,
                'action' => $a->action,
                'actor_id' => $a->actor_id,
                'acted_at' => $a->acted_at?->toIso8601String(),
                'comment' => $a->comment,
            ])->all(),
        ];
    }

    /** A line scrapping 100% has no finite requirement; the screen says so instead of crashing. */
    private function grossOrNull(BomLine $line): ?float
    {
        try {
            return round($line->grossQuantityPer(), 6);
        } catch (DomainException) {
            return null;
        }
    }
}
