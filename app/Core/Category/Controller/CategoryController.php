<?php

namespace App\Core\Category\Controller;

use App\Core\Category\Models\Category;
use App\Core\Category\Models\CategoryAccess;
use App\Core\User\Models\User;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;


class CategoryController extends Controller
{
       public function index()
    {
        $categories = Category::orderBy('created_at', 'desc')->get();

        return Inertia::render('Core/Category/Index', [
            'categories' => $categories,
        ]);
    }

    private function giveSuperAdminAccessToCategory($categoryId)
{
    // Récupérer tous les super admins (Spatie)
    $superAdmins = User::role('administrator')->get();

    foreach ($superAdmins as $user) {
        CategoryAccess::updateOrCreate(
            [
                'user_id'     => $user->id,
                'category_id' => $categoryId,
            ],
            [
                'access_level' => 5,
                'active'       => true,
            ]
        );
    }
}

  public function store(Request $request)
{
    $validator = Validator::make($request->all(), [
        'name'        => 'required|string|max:255|unique:security_categories,name',
        'icon'        => 'required|string|max:100',
        'description' => 'required|string|max:1000',
        'active'      => 'required|boolean',
    ]);

    if ($validator->fails()) {
        return response()->json([
            'success' => false,
            'errors'  => $validator->errors(),
        ], 422);
    }

    $category = Category::create([
        'name'        => $request->name,
        'icon'        => $request->icon,
        'description' => $request->description,
        'active'      => $request->active,
    ]);

    $this->giveSuperAdminAccessToCategory($category->id);

    return redirect()->back()->with('success', 'Category created successfully.');
}

     public function update(Request $request, $id)
    {
        $category = Category::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'name'        => 'required|string|max:255|unique:security_categories,name,' . $id,
            'icon'        => 'required|string|max:100',
            'description' => 'required|string|max:1000',
            'active'      => 'required|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors'  => $validator->errors(),
            ], 422);
        }

        $category->update([
            'name'        => $request->name,
            'icon'        => $request->icon,
            'description' => $request->description,
            'active'      => $request->active,
        ]);

        return redirect()->back()->with('success', 'Category updated successfully.');
    }


}
