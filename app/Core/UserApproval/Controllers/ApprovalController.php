<?php

namespace App\Core\UserApproval\Controllers;

use App\Core\UserApproval\Services\ProfileUpdateService;
use App\Http\Controllers\Controller;
use App\Modules\HR\Hr\Models\HrPeopleChange;
use App\Modules\HR\Hr\Models\HRPerson;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class ApprovalController extends Controller
{
    public function __construct(protected ProfileUpdateService $service) {}

    /**
     * ✅ Liste toutes les demandes en attente
     */
    public function index()
    {
        $approvals = HrPeopleChange::where('is_approved', 0)
            ->join('hr_people', 'hr_people_changes.hr_main_id', '=', 'hr_people.id')
            ->select(
                'hr_people_changes.*',
                'hr_people.first_name',
                'hr_people.surname',
                'hr_people.employee_number',
                'hr_people.profile_pic'
            )
            ->orderBy('hr_people_changes.created_at', 'desc')
            ->get();

        return Inertia::render('Core/ProfilUserApproval/Index', [
            'approvals' => $approvals,
        ]);
    }

    /**
     * ✅ Détail d'une demande — affiche les champs changés
     */
public function show(int $id)
{
    $change = HrPeopleChange::with(['leaveProfileData', 'province', 'country'])->findOrFail($id);

    $person = HRPerson::with(['leaveProfileData', 'province', 'country'])
                      ->find($change->hr_main_id);

    if (!$person) {
        abort(404, 'Original HR person not found.');
    }

    return Inertia::render('Core/ProfilUserApproval/Approval', [
        'approval' => $change,
        'person'   => $person,
    ]);
}

    public function approve(int $id)
    {
        $change   = HrPeopleChange::findOrFail($id);
        $approver = Auth::user();

        $this->service->approveChange($change, $approver);

        return redirect()->route('approvals.index')
            ->with('success', 'Profile changes approved and applied.');
    }

    /**
     * ✅ Rejette et supprime les changements
     */
    public function reject(Request $request, int $id)
    {
        $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        $change   = HrPeopleChange::findOrFail($id);
        $approver = Auth::user();

        $this->service->rejectChange($change, $approver, $request->reason ?? '');

        return redirect()->route('approvals.index')
            ->with('success', 'Profile change request rejected.');
    }
}
