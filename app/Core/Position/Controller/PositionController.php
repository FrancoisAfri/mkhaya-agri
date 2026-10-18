<?php

namespace App\Core\Position\Controller;

use App\Core\Position\Service\PositionService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;

class PositionController extends Controller
{
    public function __construct(protected PositionService $service) {}

    // ── Page principale ──
    public function index()
    {
        return Inertia::render('Core/Positions/Index', [
            'categories' => $this->service->getJobCategories(),
        ]);
    }

    // ════════════════════════════
    // CATEGORY
    // ════════════════════════════
    public function storeCategory(Request $request)
    {
        $request->validate([
            'name'        => 'required|string|max:255',
            'description' => 'nullable|string',
            'status'      => 'nullable|integer|in:0,1',
        ]);

        $cat = $this->service->createCategory($request->all());

        return back()->with(['newCategory' => $cat]);
    }

    public function updateCategory(Request $request, int $id)
    {
        $request->validate([
            'name'        => 'required|string|max:255',
            'description' => 'nullable|string',
            'status'      => 'nullable|integer|in:0,1',
        ]);

        $cat = $this->service->updateCategory($id, $request->all());

        return back()->with(['updatedCategory' => $cat]);
    }

    public function destroyCategory(int $id)
    {
        $this->service->deleteCategory($id);
        return back()->with('success', 'Category deleted.');
    }

    // ════════════════════════════
    // POSITION
    // ════════════════════════════
public function storePosition(Request $request)
{
    $request->validate([
        'name'            => 'required|string|max:255',
        'description'     => 'nullable|string',
        'status'          => 'nullable|integer|in:0,1',
        'job_category_id' => 'required|integer|exists:job_categories,id',
    ]);

    $pos = $this->service->createPosition($request->all());

    return redirect()->back()->with('newPosition', $pos);
}

    public function updatePosition(Request $request, int $id)
    {
        $request->validate([
            'name'        => 'required|string|max:255',
            'description' => 'nullable|string',
            'status'      => 'nullable|integer|in:0,1',
        ]);

        $pos = $this->service->updatePosition($id, $request->all());

        return back()->with(['updatedPosition' => $pos]);
    }

    public function destroyPosition(int $id)
    {
        $this->service->deletePosition($id);
        return back()->with('success', 'Position deleted.');
    }
}
