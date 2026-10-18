<?php

namespace App\Modules\Policies\Controllers;

use App\Http\Controllers\Controller;

use App\Modules\Policies\Requests\StorePolicyCategoryRequest;
use App\Modules\Policies\Requests\UpdatePolicyCategoryRequest;
use App\Modules\Policies\Services\PolicyCategoryService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class PolicyCategoryController extends Controller
{
    protected PolicyCategoryService $categoryService;

    public function __construct(PolicyCategoryService $categoryService)
    {
        $this->categoryService = $categoryService;
    }

    public function index(Request $request)
    {
        $modal = (string) $request->query('modal', '');
        $editCategoryId = $request->query('edit_category_id');
        $categories = $this->categoryService->getAll();
        $editCategory = null;

        if ($modal === 'edit' && is_numeric($editCategoryId)) {
            $editCategory = $this->categoryService->findById((int) $editCategoryId);
        }

        return Inertia::render('Modules/Policies/Categories/Index', [
            'categories' => $categories,
            'ui' => [
                'modal' => $modal,
                'edit_category_id' => is_numeric($editCategoryId) ? (int) $editCategoryId : null,
            ],
            'editCategory' => $editCategory,
        ]);
    }

    public function create()
    {
        return redirect()->route('policy-categories.index', ['modal' => 'create']);
    }

    public function store(StorePolicyCategoryRequest $request)
    {
        $this->categoryService->create($request->validated());
        return redirect()->route('policy-categories.index')
            ->with('success', 'Category created.');
    }

    public function edit($id)
    {
        return redirect()->route('policy-categories.index', [
            'modal' => 'edit',
            'edit_category_id' => $id,
        ]);
    }

    public function update(UpdatePolicyCategoryRequest $request, $id)
    {
        $updated = $this->categoryService->update($id, $request->validated());
        if (!$updated) {
            return redirect()->back()->with('error', 'Update failed.');
        }
        return redirect()->route('policy-categories.index')
            ->with('success', 'Category updated.');
    }

    public function destroy($id)
    {
        try {
            $this->categoryService->delete($id);
        } catch (\Exception $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
        return redirect()->route('policy-categories.index')
            ->with('success', 'Category deleted.');
    }
}
