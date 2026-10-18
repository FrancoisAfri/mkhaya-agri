<?php


namespace App\Core\Module\Controller;

use App\Core\Category\Models\Category;
use App\Core\Module\Models\Modules;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;


class ModulesController extends Controller
{
    public function index($id)
    {
        $category = Category::findOrFail($id);
        $module = $category->modules;

        return Inertia::render('Core/Module/Index', [
            'modules' => $module,
            'categoryId' => $id,
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name'         => 'required|string|max:255',
            'font_awesome' => 'required|string|max:100',
            'code_name'    => 'required|string|max:255|unique:security_modules,code_name',
            'path'         => 'required|string|max:255',
            'category_id'  => 'required|integer|exists:security_categories,id',
            'active'       => 'required|boolean',
        ]);

        Modules::create([
            'uuid'         => Str::uuid(),
            'name'         => $request->name,
            'font_awesome' => $request->font_awesome,
            'code_name'    => $request->code_name,
            'path'         => $request->path,
            'category_id'  => $request->category_id,
            'active'       => $request->active,
        ]);

        return redirect()->back()->with('success', 'Module created successfully.');
    }

    public function update(Request $request, $id)
    {

        $module = Modules::findOrFail($id);
        $request->validate([
            'name'         => 'required|string|max:255',
            'font_awesome' => 'required|string|max:100',
            'code_name'    => 'required|string|max:255|unique:security_modules,code_name,' . $id,
            'path'         => 'required|string|max:255',
            'active'       => 'required|boolean',
        ]);
        $module->update([
            'name'         => $request->name,
            'font_awesome' => $request->font_awesome,
            'code_name'    => $request->code_name,
            'path'         => $request->path,
            'active'       => $request->active,
        ]);

        return redirect()->back()->with('success', 'Module Updated successfully.');
    }
}
