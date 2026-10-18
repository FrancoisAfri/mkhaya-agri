<?php

namespace App\Core\HowTo\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use App\Core\HowTo\Services\HowToService;
use App\Core\HowTo\Models\HowTo;
use App\Core\Ribbon\Models\Ribbon;
use App\Core\Module\Models\Modules;
use App\Core\Category\Models\Category;
use Illuminate\Validation\Rule;

class HowToController extends Controller
{
    public function __construct(protected HowToService $service) {}

    // ──────────────────────────────────────────────────────────────────────────
    // Inertia Pages
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Main index: all categories with their modules
     * Route: GET /how-to
     */
    public function index(): InertiaResponse
    {
        $categories = Category::with(['modules' => function ($q) {
            $q->where('active', true)->orderBy('name');
        }])
            ->where('active', true)
            ->orderBy('name')
            ->get();

        return Inertia::render('Core/HowTo/Index', [
            'categories' => $categories,
        ]);
    }

    /**
     * View page for a specific ribbon's how-tos
     * Route: GET /how-to/view/{moduleId}/{ribbonId}
     */
    public function view(int $moduleId, int $ribbonId): InertiaResponse
    {
        $module = Modules::findOrFail($moduleId);
        $ribbon = Ribbon::where('id', $ribbonId)
            ->where('module_id', $moduleId)
            ->firstOrFail();

        $howtos = $this->service->getByRibbon($ribbonId);

        return Inertia::render('Core/HowTo/View', [
            'module' => $module,
            'ribbon' => $ribbon,
            'howtos' => $howtos,
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // JSON API — consumed by the store via axios
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Return ribbons + grouped how-tos for a module (axios call from store)
     * Route: GET /how-to/{moduleId}
     */
    public function byModule(int $moduleId): JsonResponse
    {
        // Ensure module exists and is active
        $module = Modules::where('id', $moduleId)
            ->where('active', true)
            ->firstOrFail();

        $ribbons = Ribbon::where('module_id', $module->id)
            ->where('active', true)
            ->orderBy('sort_order')
            ->get();

        $howtos = $this->service->getByModule($module->id);

        $grouped = $howtos
            ->groupBy('ribbon_id')
            ->map(fn($items) => $items->values());

        return response()->json([
            'ribbons' => $ribbons,
            'howtos'  => $grouped,
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Mutations — called by Inertia useForm
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Route: POST /how-to/store
     */
   public function store(Request $request)
    {
        $data = $request->validate([
            'module_id' => ['required', 'integer', Rule::exists(Modules::class, 'id')],
            'ribbon_id' => ['required', 'integer', Rule::exists(Ribbon::class,  'id')],
            'question'  => ['required', 'string', 'max:255'],
            'answer'    => ['nullable', 'string'],
            'active'    => ['sometimes', 'boolean'],
        ]);

        // Ensure ribbon belongs to the given module
        abort_unless(
            Ribbon::where('id', $data['ribbon_id'])
                ->where('module_id', $data['module_id'])
                ->exists(),
            422,
            'Ribbon does not belong to the specified module.'
        );

        $howto = $this->service->create($data);

        return redirect()->back();
    }

    /**
     * Route: PUT /how-to/edit/{id}
     */
    public function update(Request $request, int $id)
    {
        $howto = HowTo::findOrFail($id);

        $data = $request->validate([
            'question' => ['required', 'string', 'max:255'],
            'answer'   => ['nullable', 'string'],
            'active'   => ['sometimes', 'boolean'],
        ]);

        $howto = $this->service->update($howto, $data);

                return redirect()->back();

    }

    /**
     * Route: DELETE /how-to/delete/{id}
     */
    public function destroy(int $id): JsonResponse
    {
        $howto = HowTo::findOrFail($id);
        $this->service->delete($howto);

        return response()->json(['message' => 'How-to deleted successfully'], 200);
    }

    /**
     * Route: PATCH /how-to/toggle/{id}
     */
    public function toggle(int $id)
    {
        $howto = HowTo::findOrFail($id);
        $howto = $this->service->toggle($howto);


    }
}
