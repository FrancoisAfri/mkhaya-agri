<?php

namespace App\Modules\Operations\Manufacturing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Operations\Manufacturing\Models\Bom;
use App\Modules\Operations\Manufacturing\Models\Item;
use App\Modules\Operations\Manufacturing\Models\ItemCategory;
use App\Modules\Operations\Manufacturing\Models\ItemDocument;
use App\Modules\Operations\Manufacturing\Models\ItemQualityRequirement;
use App\Modules\Operations\Manufacturing\Models\ItemSubstitute;
use App\Modules\Operations\Manufacturing\Models\ItemVersion;
use App\Modules\Operations\Manufacturing\Models\Uom;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * MFG-002 — product engineering and master data.
 *
 * A product here is an *extension* of a finance stock item, never a second copy of it. Creating
 * one asks which stock item it describes; it does not ask for a code and a name, because those
 * already exist and a second set would drift from the first the moment anybody renamed one.
 */
class ProductController extends Controller
{
    use \App\Modules\Operations\Manufacturing\Concerns\ScopesValidationToTenant;

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Item::class);

        $filters = $request->validate([
            'kind' => ['nullable', Rule::in(Item::KINDS)],
            'category' => ['nullable', 'string'],
            'search' => ['nullable', 'string', 'max:120'],
            'without_bom' => ['nullable', 'boolean'],
        ]);

        $items = Item::query()
            ->with(['stockItem:id,code,name,uom', 'defaultUom:id,code', 'category:id,uuid,name'])
            ->withCount(['boms as approved_boms_count' => fn ($q) => $q->where('status', Bom::APPROVED)])
            ->when($filters['kind'] ?? null, fn ($q, $kind) => $q->where('kind', $kind))
            ->when($filters['category'] ?? null, fn ($q, $uuid) => $q->whereHas(
                'category', fn ($c) => $c->where('uuid', $uuid)
            ))
            ->when($filters['search'] ?? null, fn ($q, $search) => $q->whereHas(
                'stockItem',
                fn ($s) => $s->where('code', 'ilike', "%{$search}%")->orWhere('name', 'ilike', "%{$search}%")
            ))
            ->when($filters['without_bom'] ?? false, fn ($q) => $q
                ->whereIn('kind', Item::MANUFACTURED_KINDS)
                ->whereDoesntHave('boms', fn ($b) => $b->where('status', Bom::APPROVED)))
            ->orderBy('kind')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Item $item) => $this->summarise($item));

        return Inertia::render('Modules/Manufacturing/Engineering/Products', [
            'items' => $items,
            'filters' => $filters,
            'kinds' => Item::KINDS,
            'manufactured_kinds' => Item::MANUFACTURED_KINDS,
            // Stock items that have no engineering row yet -- what "add a product" can choose from.
            'available_stock_items' => FinStockItem::query()
                ->whereNotIn('id', Item::query()->select('stock_item_id'))
                ->orderBy('code')
                ->limit(200)
                ->get(['id', 'code', 'name', 'uom'])
                ->map(fn (FinStockItem $i) => [
                    'id' => $i->id, 'code' => $i->code, 'name' => $i->name, 'uom' => $i->uom,
                ]),
            'units' => $this->units(),
            'categories' => $this->categories(true),
            'can' => [
                'manage' => $request->user()->can('create', Item::class),
            ],
        ]);
    }

    public function show(Request $request, string $uuid): Response
    {
        $item = $this->findItem($uuid);
        $this->authorize('view', $item);

        $item->load([
            'stockItem:id,code,name,uom,standard_cost',
            'defaultUom:id,code,name',
            'versions' => fn ($q) => $q->orderByDesc('created_at'),
            'documents.document:id,uuid,title',
            'substitutes.substituteItem:id,code,name',
            'qualityRequirements',
            'boms' => fn ($q) => $q->orderByDesc('revision'),
            'category:id,uuid,name',
        ]);

        // summarise() reads this, and only the list query computed it -- so the detail payload
        // reported zero approved recipes for a product that had one.
        $item->loadCount(['boms as approved_boms_count' => fn ($q) => $q->where('status', Bom::APPROVED)]);

        return Inertia::render('Modules/Manufacturing/Engineering/ProductShow', [
            'item' => $this->detail($item),
            'kinds' => Item::KINDS,
            'units' => $this->units(),
            'categories' => $this->categories(),
            'document_kinds' => ItemDocument::KINDS,
            'inspection_stages' => ItemQualityRequirement::STAGES,
            'substitute_options' => FinStockItem::query()
                ->where('id', '!=', $item->stock_item_id)
                ->orderBy('code')->limit(200)
                ->get(['id', 'code', 'name'])
                ->map(fn (FinStockItem $i) => ['id' => $i->id, 'code' => $i->code, 'name' => $i->name]),
            /*
             * Files come from Document Management, which owns storage, encryption, versions and who
             * may open what. The panel renders nothing where the tenant has not bought it -- the
             * register below still records external references, so the screen works either way.
             */
            'documents_panel' => \App\Modules\Operations\DocumentManagement\Support\DocumentModule::panelFor(
                $item, $request->user()
            ),
            'can' => [
                'manage' => $request->user()->can('update', $item),
                'approve_substitution' => $request->user()->can('approveSubstitution', Item::class),
                'manage_boms' => $request->user()->can('create', Bom::class),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Item::class);

        $data = $request->validate([
            'stock_item_id' => [
                'required', 'integer', $this->tenantExists('fin_stock_items', 'id'),
                // One engineering row per stock item; the database enforces it too.
                Rule::unique('mfg_items', 'stock_item_id')->where('company_id', $this->companyId()),
            ],
            'kind' => ['required', Rule::in(Item::KINDS)],
            'category_id' => ['nullable', 'integer', $this->tenantExists('mfg_item_categories', 'id')],
            'default_uom_id' => ['nullable', 'integer', $this->tenantExists('mfg_uoms', 'id')],
            'specification' => ['nullable', 'string'],
            'material_grade' => ['nullable', 'string', 'max:64'],
            'colour' => ['nullable', 'string', 'max:64'],
            'size' => ['nullable', 'string', 'max:64'],
            'weight_kg' => ['nullable', 'numeric', 'min:0'],
            'shelf_life_days' => ['nullable', 'integer', 'min:1'],
            'storage_conditions' => ['nullable', 'string'],
            'handling_instructions' => ['nullable', 'string'],
            'is_lot_tracked' => ['nullable', 'boolean'],
            'is_serial_tracked' => ['nullable', 'boolean'],
        ]);

        $item = Item::create($data + ['created_by' => $request->user()->id, 'is_active' => true]);

        return redirect()
            ->route('manufacturing.engineering.products.show', $item->uuid)
            ->with('success', 'Product added to the engineering register.');
    }

    public function update(Request $request, string $uuid): RedirectResponse
    {
        $item = $this->findItem($uuid);
        $this->authorize('update', $item);

        $data = $request->validate([
            'kind' => ['sometimes', Rule::in(Item::KINDS)],
            'category_id' => ['nullable', 'integer', $this->tenantExists('mfg_item_categories', 'id')],
            'default_uom_id' => ['nullable', 'integer', $this->tenantExists('mfg_uoms', 'id')],
            'specification' => ['nullable', 'string'],
            'material_grade' => ['nullable', 'string', 'max:64'],
            'colour' => ['nullable', 'string', 'max:64'],
            'size' => ['nullable', 'string', 'max:64'],
            'weight_kg' => ['nullable', 'numeric', 'min:0'],
            // Kept together, because a length with no unit is not a measurement.
            'dimensions' => ['nullable', 'array'],
            'dimensions.length' => ['nullable', 'numeric', 'min:0'],
            'dimensions.width' => ['nullable', 'numeric', 'min:0'],
            'dimensions.height' => ['nullable', 'numeric', 'min:0'],
            'dimensions.uom' => ['nullable', 'string', 'max:16'],
            // Tenant-defined descriptors, sent as a list of pairs so the order the user typed survives.
            'attributes' => ['nullable', 'array', 'max:40'],
            'attributes.*.key' => ['required', 'string', 'max:64', 'distinct'],
            'attributes.*.value' => ['nullable', 'string', 'max:255'],
            'shelf_life_days' => ['nullable', 'integer', 'min:1'],
            'storage_conditions' => ['nullable', 'string'],
            'handling_instructions' => ['nullable', 'string'],
            'is_lot_tracked' => ['nullable', 'boolean'],
            'is_serial_tracked' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        /*
         * Changing a product from manufactured to a raw material while it carries an approved BOM
         * would leave a recipe for something the module says is bought rather than made, and the
         * planner would quietly stop exploding it.
         */
        if (isset($data['kind'])
            && ! in_array($data['kind'], Item::MANUFACTURED_KINDS, true)
            && $item->boms()->whereIn('status', [Bom::DRAFT, Bom::APPROVED])->exists()) {
            return back()->with('error',
                'This product has a live bill of materials, so it cannot become a bought item. '
                .'Retire the BOM revisions first.');
        }

        if (array_key_exists('attributes', $data)) {
            $data['attributes'] = collect($data['attributes'] ?? [])
                ->filter(fn ($pair) => trim((string) ($pair['key'] ?? '')) !== '')
                ->mapWithKeys(fn ($pair) => [trim($pair['key']) => $pair['value'] ?? null])
                ->all() ?: null;
        }

        if (array_key_exists('dimensions', $data)) {
            // All four empty is no dimensions at all, not a set of nulls.
            $data['dimensions'] = array_filter($data['dimensions'] ?? [], fn ($v) => $v !== null && $v !== '') ?: null;
        }

        $item->update($data);

        return back()->with('success', 'Product updated.');
    }

    public function destroy(string $uuid): RedirectResponse
    {
        $item = $this->findItem($uuid);
        $this->authorize('delete', $item);

        if ($item->boms()->exists()) {
            return back()->with('error',
                'This product has bills of materials on record. Deactivate it instead -- removing '
                .'it would orphan recipes that orders were costed against.');
        }

        $item->delete();

        return redirect()->route('manufacturing.engineering.products.index')
            ->with('success', 'Product removed from the engineering register.');
    }

    // ── Engineering change control ───────────────────────────────────────────────────────────

    public function storeVersion(Request $request, string $uuid): RedirectResponse
    {
        $item = $this->findItem($uuid);
        $this->authorize('update', $item);

        $data = $request->validate([
            'version' => [
                'required', 'string', 'max:24',
                Rule::unique('mfg_item_versions', 'version')
                    ->where('company_id', $this->companyId())
                    ->where('item_id', $item->id),
            ],
            'effective_from' => ['nullable', 'date'],
            'change_reason' => ['required', 'string', 'max:2000'],
        ]);

        $item->versions()->create($data + [
            'company_id' => $item->company_id,
            'status' => ItemVersion::DRAFT,
            'created_by' => $request->user()->id,
        ]);

        return back()->with('success', "Version {$data['version']} drafted.");
    }

    public function approveVersion(Request $request, string $uuid, string $versionUuid): RedirectResponse
    {
        $item = $this->findItem($uuid);
        $this->authorize('update', $item);

        $version = $item->versions()->where('uuid', $versionUuid)->firstOrFail();

        if ($version->status !== ItemVersion::DRAFT) {
            return back()->with('error', "Version {$version->version} is {$version->status}, not a draft.");
        }

        /*
         * Approving a version supersedes the one it replaces and closes its window the day before
         * the new one opens, so the two never both claim to be effective on the same date.
         */
        $effectiveFrom = $version->effective_from ?? now()->toDateString();

        $item->versions()
            ->where('status', ItemVersion::APPROVED)
            ->where('id', '!=', $version->id)
            ->get()
            ->each(fn (ItemVersion $previous) => $previous->update([
                'status' => ItemVersion::SUPERSEDED,
                'effective_to' => \Illuminate\Support\Carbon::parse($effectiveFrom)->subDay()->toDateString(),
            ]));

        $version->update([
            'status' => ItemVersion::APPROVED,
            'effective_from' => $effectiveFrom,
            'approved_by' => $request->user()->id,
            'approved_at' => now(),
        ]);

        return back()->with('success', "Version {$version->version} approved.");
    }

    // ── Documents ────────────────────────────────────────────────────────────────────────────

    public function storeDocument(Request $request, string $uuid): RedirectResponse
    {
        $item = $this->findItem($uuid);
        $this->authorize('update', $item);

        $data = $request->validate([
            'kind' => ['required', Rule::in(ItemDocument::KINDS)],
            'title' => ['required', 'string', 'max:255'],
            'document_uuid' => ['nullable', 'string'],
            'external_reference' => ['nullable', 'string', 'max:255'],
            'item_version_id' => ['nullable', 'integer', $this->tenantExists('mfg_item_versions', 'id')],
        ]);

        // A record pointing at nothing is not a record: it must hold a file or name an external one.
        if (empty($data['document_uuid']) && empty($data['external_reference'])) {
            return back()->with('error',
                'Choose a file filed against this product, or give an external reference -- otherwise there is nothing to find.');
        }

        $documentId = null;

        if (! empty($data['document_uuid'])) {
            /*
             * Only a file already filed against THIS product, and only one this person may open.
             * Taking any document id would let the register point at another product's drawing --
             * or at a confidential document the person was refused everywhere else.
             */
            $documentId = $item->libraryDocuments()
                ->visibleTo($request->user())
                ->where('uuid', $data['document_uuid'])
                ->value('documents.id');

            if (! $documentId) {
                return back()->with('error', 'That file is not filed against this product.');
            }
        }

        unset($data['document_uuid']);

        $item->documents()->create($data + ['company_id' => $item->company_id, 'document_id' => $documentId]);

        return back()->with('success', 'Document recorded against the product.');
    }

    public function destroyDocument(string $uuid, string $documentUuid): RedirectResponse
    {
        $item = $this->findItem($uuid);
        $this->authorize('update', $item);

        $item->documents()->where('uuid', $documentUuid)->firstOrFail()->delete();

        return back()->with('success', 'Document removed.');
    }

    // ── Substitutions ────────────────────────────────────────────────────────────────────────

    public function storeSubstitute(Request $request, string $uuid): RedirectResponse
    {
        $item = $this->findItem($uuid);
        $this->authorize('update', $item);

        $data = $request->validate([
            'substitute_stock_item_id' => ['required', 'integer', $this->tenantExists('fin_stock_items', 'id')],
            'ratio' => ['required', 'numeric', 'gt:0'],
            'restriction' => ['nullable', 'string', 'max:2000'],
            'requires_approval' => ['nullable', 'boolean'],
        ]);

        if ((int) $data['substitute_stock_item_id'] === (int) $item->stock_item_id) {
            return back()->with('error', 'An item cannot be its own substitute.');
        }

        $item->substitutes()->create($data + [
            'company_id' => $item->company_id,
            'requires_approval' => $data['requires_approval'] ?? true,
            'is_active' => true,
        ]);

        return back()->with('success', 'Substitution recorded.');
    }

    public function approveSubstitute(Request $request, string $uuid, string $substituteUuid): RedirectResponse
    {
        $item = $this->findItem($uuid);
        $this->authorize('approveSubstitution', Item::class);

        $substitute = $item->substitutes()->where('uuid', $substituteUuid)->firstOrFail();

        $substitute->update([
            'approved_by' => $request->user()->id,
            'approved_at' => now(),
        ]);

        return back()->with('success', 'Substitution approved for use.');
    }

    public function destroySubstitute(string $uuid, string $substituteUuid): RedirectResponse
    {
        $item = $this->findItem($uuid);
        $this->authorize('update', $item);

        $item->substitutes()->where('uuid', $substituteUuid)->firstOrFail()->delete();

        return back()->with('success', 'Substitution removed.');
    }

    // ── Quality ──────────────────────────────────────────────────────────────────────────────

    public function storeQualityRequirement(Request $request, string $uuid): RedirectResponse
    {
        $item = $this->findItem($uuid);
        $this->authorize('update', $item);

        $data = $request->validate([
            'standard_code' => ['nullable', 'string', 'max:64'],
            'standard_name' => ['required', 'string', 'max:255'],
            'inspection_stage' => ['required', Rule::in(ItemQualityRequirement::STAGES)],
            'inspection_plan_reference' => ['nullable', 'string', 'max:255'],
            'is_mandatory' => ['nullable', 'boolean'],
        ]);

        $item->qualityRequirements()->create($data + ['company_id' => $item->company_id]);

        return back()->with('success', 'Quality requirement recorded.');
    }

    public function destroyQualityRequirement(string $uuid, string $requirementUuid): RedirectResponse
    {
        $item = $this->findItem($uuid);
        $this->authorize('update', $item);

        $item->qualityRequirements()->where('uuid', $requirementUuid)->firstOrFail()->delete();

        return back()->with('success', 'Quality requirement removed.');
    }

    // ── Categories ───────────────────────────────────────────────────────────────────────────

    /**
     * Product categories are engineering master data, so they are maintained by whoever may
     * manage products -- not only by the person who configures the plant.
     */
    public function storeCategory(Request $request): RedirectResponse
    {
        $this->authorize('create', Item::class);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120', $this->uniqueCategoryName()],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        ItemCategory::create($data + [
            'is_active' => true,
            'sort_order' => (int) ItemCategory::query()->max('sort_order') + 1,
        ]);

        return back()->with('success', "Category \"{$data['name']}\" added.");
    }

    public function updateCategory(Request $request, string $uuid): RedirectResponse
    {
        $this->authorize('create', Item::class);

        $category = ItemCategory::query()->where('uuid', $uuid)->firstOrFail();

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120', $this->uniqueCategoryName($category->id)],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $category->update($data);

        return back()->with('success', 'Category updated.');
    }

    public function destroyCategory(string $uuid): RedirectResponse
    {
        $this->authorize('create', Item::class);

        $category = ItemCategory::query()->where('uuid', $uuid)->firstOrFail();

        /*
         * A category in use is retired, not deleted: deleting it would quietly blank the category
         * on every product filed under it. Retired, it stops being offered for new choices and the
         * products keep it.
         */
        if ($category->items()->exists()) {
            $category->update(['is_active' => false]);

            return back()->with('success',
                "\"{$category->name}\" is in use, so it was retired: existing products keep it and it is no longer offered.");
        }

        $category->delete();

        return back()->with('success', 'Category removed.');
    }

    /** @return list<array{id:int, uuid:string, name:string, is_active:bool, products:int}> */
    private function categories(bool $withCounts = false): array
    {
        return ItemCategory::query()
            ->when($withCounts, fn ($q) => $q->withCount('items'))
            ->orderBy('sort_order')->orderBy('name')
            ->get()
            ->map(fn (ItemCategory $c) => [
                'id' => $c->id,
                'uuid' => $c->uuid,
                'name' => $c->name,
                'description' => $c->description,
                'is_active' => (bool) $c->is_active,
                'products' => (int) ($c->items_count ?? 0),
            ])->all();
    }

    /** Unique within the tenant regardless of case -- "Brackets" and "brackets" are one category. */
    private function uniqueCategoryName(?int $ignoreId = null): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) use ($ignoreId) {
            $taken = ItemCategory::query()
                ->whereRaw('lower(name) = lower(?)', [trim((string) $value)])
                ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
                ->exists();

            if ($taken) {
                $fail('There is already a category with that name.');
            }
        };
    }

    // ── Helpers ──────────────────────────────────────────────────────────────────────────────

    private function findItem(string $uuid): Item
    {
        return Item::query()->where('uuid', $uuid)->firstOrFail();
    }

    private function companyId(): ?int
    {
        return request()->user()?->company_id;
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

    private function summarise(Item $item): array
    {
        return [
            'id' => $item->uuid,
            'code' => $item->stockItem?->code,
            'name' => $item->stockItem?->name,
            'kind' => $item->kind,
            'category' => $item->category?->name,
            'category_id' => $item->category_id,
            'uom' => $item->defaultUom?->code ?? $item->stockItem?->uom,
            'is_lot_tracked' => (bool) $item->is_lot_tracked,
            'is_active' => (bool) $item->is_active,
            'is_manufactured' => $item->isManufactured(),
            'approved_boms' => (int) ($item->approved_boms_count ?? 0),
        ];
    }

    private function detail(Item $item): array
    {
        return $this->summarise($item) + [
            'stock_item_id' => $item->stock_item_id,
            'specification' => $item->specification,
            'material_grade' => $item->material_grade,
            'colour' => $item->colour,
            'size' => $item->size,
            'weight_kg' => $item->weight_kg !== null ? (float) $item->weight_kg : null,
            'dimensions' => $item->dimensions,
            'attributes' => $item->attributes,
            'shelf_life_days' => $item->shelf_life_days,
            'storage_conditions' => $item->storage_conditions,
            'handling_instructions' => $item->handling_instructions,
            'is_serial_tracked' => (bool) $item->is_serial_tracked,
            'default_uom_id' => $item->default_uom_id,
            'standard_cost' => $item->stockItem?->standard_cost !== null
                ? (float) $item->stockItem->standard_cost : null,
            'versions' => $item->versions->map(fn (ItemVersion $v) => [
                'id' => $v->uuid,
                'version' => $v->version,
                'status' => $v->status,
                'effective_from' => $v->effective_from?->toDateString(),
                'effective_to' => $v->effective_to?->toDateString(),
                'change_reason' => $v->change_reason,
                'approved_at' => $v->approved_at?->toIso8601String(),
            ])->all(),
            'documents' => $item->documents->map(fn (ItemDocument $d) => [
                'id' => $d->uuid,
                'kind' => $d->kind,
                'title' => $d->title,
                // The file's public id, for a link to it -- never the internal one.
                'document_uuid' => $d->document?->uuid,
                'document_title' => $d->document?->title,
                'external_reference' => $d->external_reference,
            ])->all(),
            'substitutes' => $item->substitutes->map(fn (ItemSubstitute $s) => [
                'id' => $s->uuid,
                'stock_item_id' => $s->substitute_stock_item_id,
                'code' => $s->substituteItem?->code,
                'name' => $s->substituteItem?->name,
                'ratio' => (float) $s->ratio,
                'restriction' => $s->restriction,
                'requires_approval' => (bool) $s->requires_approval,
                'approved_at' => $s->approved_at?->toIso8601String(),
                // The screen must distinguish "awaiting approval" from "approved and usable".
                'is_usable' => $s->isUsable(),
            ])->all(),
            'quality_requirements' => $item->qualityRequirements
                ->map(fn (ItemQualityRequirement $q) => [
                    'id' => $q->uuid,
                    'standard_code' => $q->standard_code,
                    'standard_name' => $q->standard_name,
                    'inspection_stage' => $q->inspection_stage,
                    'inspection_plan_reference' => $q->inspection_plan_reference,
                    'is_mandatory' => (bool) $q->is_mandatory,
                ])->all(),
            'boms' => $item->boms->map(fn (Bom $b) => [
                'id' => $b->uuid,
                'revision' => (int) $b->revision,
                'status' => $b->status,
                'is_default' => (bool) $b->is_default,
                'effective_from' => $b->effective_from?->toDateString(),
                'effective_to' => $b->effective_to?->toDateString(),
            ])->all(),
        ];
    }
}
