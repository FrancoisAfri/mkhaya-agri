<?php

namespace App\Modules\Fleet\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class FleetProjectController extends Controller
{
    public function index(): Response
    {
        $projects = DB::table('fleet_projects as p')
            ->leftJoin('company_units as cu', 'cu.id', '=', 'p.company_unit_id')
            ->select('p.*', 'cu.name as department')
            ->orderBy('p.name')
            ->get();

        $companyUnits = DB::table('company_units')
            ->where('active', 1)
            ->orderBy('name')
            ->get(['id', 'name']);

        return Inertia::render('Modules/Fleet/Projects/Index', [
            'projects'     => $projects,
            'companyUnits' => $companyUnits,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'name'            => 'required|string|max:100',
            'description'     => 'nullable|string|max:300',
            'company_unit_id' => 'nullable|exists:company_units,id',
        ]);

        DB::table('fleet_projects')->insert([
            'name'            => $request->name,
            'description'     => $request->description,
            'company_unit_id' => $request->company_unit_id,
            'active'          => true,
            'created_by'      => $request->user()->id,
            'updated_by'      => $request->user()->id,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        return back()->with('success', "Project '{$request->name}' added.");
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $request->validate([
            'name'            => 'required|string|max:100',
            'description'     => 'nullable|string|max:300',
            'company_unit_id' => 'nullable|exists:company_units,id',
            'active'          => 'boolean',
        ]);

        DB::table('fleet_projects')->where('id', $id)->update([
            'name'            => $request->name,
            'description'     => $request->description,
            'company_unit_id' => $request->company_unit_id,
            'active'          => $request->boolean('active', true),
            'updated_by'      => $request->user()->id,
            'updated_at'      => now(),
        ]);

        return back()->with('success', 'Project updated.');
    }

    public function destroy(int $id): RedirectResponse
    {
        $inUse = DB::table('fleet_bookings')->where('project_id', $id)->exists();
        if ($inUse) {
            DB::table('fleet_projects')->where('id', $id)->update(['active' => false, 'updated_at' => now()]);
            return back()->with('success', 'Project de-activated (it has bookings linked to it).');
        }

        DB::table('fleet_projects')->where('id', $id)->delete();
        return back()->with('success', 'Project deleted.');
    }
}
