<?php

namespace App\Modules\Operations\Manufacturing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\Manufacturing\Models\Bom;
use App\Modules\Operations\Manufacturing\Models\Factory;
use App\Modules\Operations\Manufacturing\Models\Item;
use App\Modules\Operations\Manufacturing\Models\Shutdown;
use App\Modules\Operations\Manufacturing\Models\Uom;
use App\Modules\Operations\Manufacturing\Models\WorkCentre;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The module's landing page.
 *
 * While MFG-001 and MFG-002 are the only parts built, this reports readiness rather than
 * production figures: a plant cannot plan anything until it has a rated work centre and an
 * approved BOM, and saying so plainly is more use than an empty throughput chart.
 *
 * Each number distinguishes "none configured" from "configured as zero". A factory with no rated
 * work centre is not the same as a factory with idle ones, and a dashboard that shows 0 for both
 * hides the one thing somebody has to go and fix.
 */
class DashboardController extends Controller
{
    public function index(): Response
    {
        $this->authorize('viewAny', Factory::class);

        $factories = Factory::query()->where('is_active', true)->count();
        $workCentres = WorkCentre::query()->where('is_active', true)->get();

        return Inertia::render('Modules/Manufacturing/Dashboard', [
            'readiness' => [
                'factories' => $factories,
                'work_centres' => $workCentres->count(),
                // A centre with no rate cannot be planned or costed against.
                'work_centres_rated' => $workCentres->filter(fn (WorkCentre $c) => $c->isRated())->count(),
                'units' => Uom::query()->where('is_active', true)->count(),
                'products' => Item::query()->where('is_active', true)->count(),
                'manufactured_products' => Item::query()
                    ->whereIn('kind', Item::MANUFACTURED_KINDS)->where('is_active', true)->count(),
                'boms_draft' => Bom::query()->where('status', Bom::DRAFT)->count(),
                'boms_approved' => Bom::query()->where('status', Bom::APPROVED)->count(),
            ],

            /*
             * Products that can be made but have no approved recipe. The single most useful thing
             * this page can say during configuration: these are the items a production order
             * cannot be raised for yet.
             */
            'products_without_bom' => Item::query()
                ->whereIn('kind', Item::MANUFACTURED_KINDS)
                ->where('is_active', true)
                ->whereDoesntHave('boms', fn ($q) => $q->where('status', Bom::APPROVED))
                ->with('stockItem:id,code,name')
                ->limit(25)
                ->get()
                ->map(fn (Item $item) => [
                    'id' => $item->uuid,
                    'code' => $item->stockItem?->code,
                    'name' => $item->stockItem?->name,
                    'kind' => $item->kind,
                ]),

            'open_shutdowns' => Shutdown::query()
                ->where('ends_at', '>=', now())
                ->with(['factory:id,uuid,name', 'productionLine:id,uuid,name'])
                ->orderBy('starts_at')
                ->limit(10)
                ->get()
                ->map(fn (Shutdown $s) => [
                    'id' => $s->uuid,
                    'reason' => $s->reason,
                    'kind' => $s->kind,
                    'factory' => $s->factory?->name,
                    // Null line means the whole factory stops, which the screen must not read as "none".
                    'line' => $s->productionLine?->name,
                    'whole_factory' => $s->production_line_id === null,
                    'starts_at' => $s->starts_at?->toIso8601String(),
                    'ends_at' => $s->ends_at?->toIso8601String(),
                ]),

            'can' => [
                'configure' => $this->userCan('configure', Factory::class),
                'view_boms' => $this->userCan('viewAny', Bom::class),
            ],
        ]);
    }

    private function userCan(string $ability, string $subject): bool
    {
        return request()->user()?->can($ability, $subject) ?? false;
    }
}
