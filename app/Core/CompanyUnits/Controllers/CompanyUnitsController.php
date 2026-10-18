<?php

namespace App\Core\CompanyUnits\Controllers;

use App\Core\Storage\StoredImage;
use App\Core\CompanyUnits\Services\CompanyUnitsService;
use App\Http\Controllers\Controller;
use App\Modules\HR\Hr\Models\HRPerson;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class CompanyUnitsController extends Controller
{
    public function __construct(private CompanyUnitsService $service) {}


public function index()
{
    $companyId = Auth::user()->company_id ?? 1;

    // Managers/members of a unit are USERS (CompanyUnit.user_id and the
    // company_unit_user_pivots both reference users.id). Expose the user_id as the
    // option value — using hr_people.id here only worked when those ids happened to
    // align (the master company); a fresh tenant's don't, so the "manager" failed
    // exists:users,id validation and adding a unit silently did nothing.
    $users = HRPerson::select('id', 'user_id', 'first_name', 'surname', 'email', 'job_function', 'profile_pic', 'initial')
        ->whereNotNull('user_id')
        ->get()
        ->map(function ($p) {

            $profilePicUrl = StoredImage::url($p->profile_pic_path, $p->profile_pic);

            return [
                'id'           => $p->user_id, // users.id — the manager/member value
                'first_name'   => $p->first_name,
                'surname'      => $p->surname,
                'email'        => $p->email,
                'job_function' => $p->job_function,
                'profile_pic'  => $profilePicUrl,
                'initial'      => $p->initial,
            ];
        });

    return Inertia::render('Core/CompanyOrganization/Index', [
        'tree'      => $this->service->getTreeWithMembers($companyId),
        'companyId' => $companyId,
        'unitTypes' => ['division', 'department', 'team', 'section', 'unit'],
        'users'     => $users,
    ]);
}

    public function store(Request $request)
    {
        $request->validate([
            'name'      => 'required|string|max:255',
            'parent_id' => 'nullable|integer|exists:company_units,id',
            'user_id'   => 'nullable|integer|exists:users,id',
            'type'      => 'nullable|string|max:50',
        ]);
        $this->service->create([
            'name'       => $request->name,
            'parent_id'  => $request->parent_id,
            'type'       => $request->type,
            'company_id' => Auth::user()->company_id ?? 1,
            'user_id'    => $request->user_id,
        ]);
        return back()->with('success', 'Unit created.');
    }

    public function update(Request $request, int $id)
    {
        $request->validate(['name' => 'required|string|max:255', 'type' => 'nullable|string|max:50']);
        $this->service->rename($id, $request->name, $request->type, $request->user_id);
        return back()->with('success', 'Unit updated.');
    }

    public function move(Request $request, int $id)
    {
        $request->validate(['parent_id' => 'nullable|integer', 'sort_order' => 'required|integer']);
        $this->service->move($id, $request->parent_id, $request->sort_order);
        return back();
    }

    public function reorder(Request $request)
    {
        $request->validate(['items' => 'required|array']);
        $this->service->reorder($request->items);
        return response()->json(['success' => true]);
    }

    public function destroy(int $id)
    {
        $this->service->delete($id);
        return back()->with('success', 'Unit deleted.');
    }
}
