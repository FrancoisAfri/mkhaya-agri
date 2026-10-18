<?php

namespace App\Core\Ribbon\Controller;

use App\Core\Module\Models\Modules;
use App\Core\Ribbon\Models\Ribbon;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;


class RibbonController extends Controller
{
      public function index($id)
    {
        $module = Modules::findOrFail($id);
        return Inertia::render('Core/Ribbons/Index', [
            'ribbons'  => $module->moduleRibbons?? [],
            'moduleId' => (int) $id,
        ]);
    }

        public function store(Request $request)
    {
        $request->validate([
            'module_id'    => 'required|integer|exists:security_modules,id',
            'ribbon_name'  => 'required|string|max:255',
            'font_awesome' => 'required|string|max:100',
            'ribbon_path'  => 'required|string|max:255',
            'description'  => 'required|string',
            'sort_order'   => 'required|integer',
            'access_level' => 'required|integer|min:0|max:5',
            'active'       => 'required|boolean',
        ]);

        Ribbon::create(array_merge(
            $request->all(),
            ['uuid' => Str::uuid()]
        ));

        return redirect()->back()->with('success', 'Ribbon created successfully.');
    }

        public function update(Request $request, $id)
    {
        $ribbon = Ribbon::findOrFail($id);
        $request->validate([
            'ribbon_name'  => 'required|string|max:255',
            'font_awesome' => 'required|string|max:100',
            'ribbon_path'  => 'required|string|max:255',
            'description'  => 'required|string',
            'sort_order'   => 'required|integer',
            'access_level' => 'required|integer|min:0|max:5',
            'active'       => 'required|boolean',
        ]);
        $ribbon->update($request->all());
        return redirect()->back()->with('success', 'Ribbon updated successfully.');
    }

    public function delete($id)
    {
        $ribbon = Ribbon::findOrFail($id);
        $ribbon->delete();
        return redirect()->back();

    }
}
