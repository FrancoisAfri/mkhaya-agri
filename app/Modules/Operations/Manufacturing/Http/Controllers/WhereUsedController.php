<?php

namespace App\Modules\Operations\Manufacturing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Operations\Manufacturing\Models\Bom;
use App\Modules\Operations\Manufacturing\Services\BomExploder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Impact analysis, asked from the component end (MFG-002: "version comparison and impact
 * analysis").
 *
 * A buyer told a material is discontinued needs to know what it breaks, and does not yet know
 * which products to open. This answers that without guessing, and reports live revisions only --
 * a superseded BOM cannot be affected by a change, because nothing will be made from it again.
 */
class WhereUsedController extends Controller
{
    use \App\Modules\Operations\Manufacturing\Concerns\ScopesValidationToTenant;

    public function __construct(private readonly BomExploder $exploder) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Bom::class);

        $validated = $request->validate([
            'stock_item_id' => ['nullable', 'integer', $this->tenantExists('fin_stock_items', 'id')],
            'include_retired' => ['nullable', 'boolean'],
        ]);

        $stockItemId = $validated['stock_item_id'] ?? null;
        $includeRetired = (bool) ($validated['include_retired'] ?? false);

        return Inertia::render('Modules/Manufacturing/Engineering/WhereUsed', [
            // The components to choose from. Only those that actually appear on a BOM -- offering
            // every stock item would bury the twelve that matter in a list of hundreds.
            'components' => FinStockItem::query()
                ->whereIn('id', Bom::query()->getConnection()
                    ->table('mfg_bom_lines')->distinct()->pluck('component_stock_item_id'))
                ->orderBy('code')
                ->get(['id', 'code', 'name'])
                ->map(fn (FinStockItem $i) => ['id' => $i->id, 'code' => $i->code, 'name' => $i->name]),

            'selected' => $stockItemId,
            'include_retired' => $includeRetired,
            'usages' => $stockItemId
                ? $this->exploder->whereUsed((int) $stockItemId, ! $includeRetired)
                : [],
        ]);
    }
}
