<?php

namespace App\Core\UserApproval\Controllers;

use App\Core\Storage\StoredImage;
use App\Core\CompanyUnits\Models\CompanyUnit;
use App\Core\Position\Models\HrPosition;
use App\Core\User\Models\Country;
use App\Core\User\Models\Province;
use App\Core\User\Models\User;
use App\Core\User\Trait\FileStorage;
use App\Core\UserManagement\Models\PasswordHistory;
use App\Core\UserManagement\Models\PasswordSecurity;
use App\Core\UserApproval\Mails\ProfileChangeApprovedMail;
use App\Core\UserApproval\Request\ProfileUpdateRequest;
use App\Core\UserApproval\Services\ProfileUpdateService;
use App\Http\Controllers\Controller;
use App\Modules\HR\Hr\Models\HrPeopleChange;
use App\Modules\HR\Hr\Models\HRPerson;
use App\Modules\HR\Leave\Models\LeaveProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;
use App\Core\Role\Models\Role;

class UserApprovalController extends Controller
{
    use FileStorage;
    public function __construct(protected ProfileUpdateService $service) {}
    public function index()
    {
        $currentUser = Auth::user();

        if (!$this->service->canUpdateDirectly($currentUser)) {
            abort(403, 'Unauthorized action.');
        }

        $approvals = HrPeopleChange::where('is_approved', 0)
            ->orderBy('created_at', 'desc')
            ->get();

        return Inertia::render('Core/ProfilUserApproval/Index', [
            'approvals' => $approvals
        ]);
    }


    public function show($id)
    {
        $change = HrPeopleChange::with(['leaveProfileData', 'province', 'country'])->findOrFail($id);

        $person = HRPerson::with(['leaveProfileData', 'province', 'country'])
            ->find($change->hr_main_id);

        if (!$person) {
            abort(404, 'Original HR person not found.');
        }

        // The proposed photo and the one it would replace, side by side for the approver.
        $newProfilePicUrl = StoredImage::url($change->profile_pic_path, $change->profile_pic);
        $currentProfilePicUrl = StoredImage::url($person->profile_pic_path, $person->profile_pic);

        return Inertia::render('Core/ProfilUserApproval/Approval', [
            'approval' => array_merge($change->toArray(), [
                'profile_pic_url' => $newProfilePicUrl,
            ]),
            'hrPerson' => array_merge($person->toArray(), [
                'profile_pic_url' => $currentProfilePicUrl,
            ]),
        ]);
    }

    public function approve($id)
    {
        $currentUser = Auth::user();
        $change = HrPeopleChange::findOrFail($id);

        $person = HRPerson::find($change->hr_main_id);

        if (!$person) {
            return redirect()->back()->with('error', 'Person not found.');
        }

        $user = $person->user;

        $dataToUpdate = collect($change->toArray())
            ->except([
                'id',
                'hr_main_id',
                'is_approved',
                'created_at',
                'updated_at',
                'uuid',
                'company_unit_ids',
                'user_changes'
            ])
            ->filter(function ($value) {
                return !is_null($value) && $value !== '';
            })
            ->toArray();

        $dateFields = [
            'date_of_birth',
            'date_joined',
            'date_left',
            'med_start_date',
            'provident_start_date',
            'drivers_licence_exp_date',
            'proof_drive_permit_exp_date',
        ];

        foreach ($dateFields as $field) {
            if (isset($dataToUpdate[$field]) && !is_null($dataToUpdate[$field])) {
                $dataToUpdate[$field] = is_numeric($dataToUpdate[$field])
                    ? $dataToUpdate[$field]
                    : strtotime($dataToUpdate[$field]);
            }
        }

        $person->update($dataToUpdate);

        $change->update(['is_approved' => 1]);

        if ($user && $user->email) {
            try {
                Mail::to($user->email)->send(new ProfileChangeApprovedMail(
                    person: $person,
                    change: $change,
                    approver: $currentUser
                ));

                Log::info('Approval email sent to: ' . $user->email);
            } catch (\Exception $e) {
                Log::error('Failed to send approval email: ' . $e->getMessage());
            }
        }

        activity()
            ->causedBy($currentUser)
            ->performedOn($change)
            ->withProperties(['approved' => $dataToUpdate])
            ->log("Profile change approved by '{$currentUser->email}'");

        return redirect()->back()->with('success', 'Changes approved successfully. Notification email sent.');
    }

    public function reject(Request $request, $id)
    {
        $currentUser = Auth::user();

        if (!$this->service->canUpdateDirectly($currentUser)) {
            abort(403, 'Unauthorized action.');
        }

        $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        $change = HrPeopleChange::findOrFail($id);
        $person = HRPerson::find($change->hr_main_id);
        $user = $change->person?->user;

        $reason = $request->input('reason', 'No specific reason provided.');

        try {
            $this->service->rejectChange($change, $currentUser, $reason);

            $message = 'Changes rejected successfully.';
            if ($user) {
                $message .= ' A notification email has been sent to ' . $user->email . '.';
            }

            return redirect()->route('approvals.index')->with('success', $message);
        } catch (\Exception $e) {
            Log::error('Rejection error: ' . $e->getMessage(), [
                'change_id' => $id,
                'approver_id' => $currentUser->id,
            ]);

            return redirect()->back()->with('error', 'Failed to reject changes: ' . $e->getMessage());
        }
    }



    public function shows()
    {
        // $user = Auth::user()->load(['hrPerson', 'roles', 'companies']);
        $id = Auth::id();
        $user  = User::with(['person', 'roles', 'companies'])->findOrFail($id);
        $roles = Role::orderBy('name')->get(['id', 'name']);

        return Inertia::render('Core/Profile/Index', [
            'user'  => $user,
            'roles' => $roles,
        ]);
    }

    // ── Page edit ──
    public function edit($uuid)
    {
        $hrPerson=HrPerson::where('uuid',$uuid)->firstOrFail();
        $currentUser = Auth::user();

        $canEditDirectly = $this->service->canUpdateDirectly($currentUser);

        $user = User::with([
            'person.hrPosition',
            'person.manager',
            'companyUnits',
            'roles',
            'person.leaveProfileData',
            'person.country',
            'person.province',
            'person.documents',
        ])->findOrFail($hrPerson->user_id);

        $roles = Role::orderBy('name')->get(['id', 'name']);
        $countries = Country::orderBy('name')->get(['id', 'name']);
        $provinces = Province::orderBy('name')->get(['id', 'name']);
        $positions = HrPosition::where('status', 1)
            ->orderBy('name')
            ->get(['id', 'name']);
        $leaves = LeaveProfile::all();
        $managers = User::with('person')
            ->whereDoesntHave('roles', function ($query) {
                $query->where('name', 'user');
            })
            ->get()
            ->map(fn($u) => [
                'id'     => $u->id,
                'email'  => $u->email,
                'status' => $u->status,
                'roles'  => $u->roles->map(fn($r) => ['id' => $r->id, 'name' => $r->name]),
                'person' => $u->person ? [
                    'first_name' => $u->person->first_name,
                    'surname'    => $u->person->surname,
                ] : null,
            ]);

        $companyUnits = [
            'divisions'   => CompanyUnit::where('type', 'division')->get(['id', 'name', 'parent_id', 'type']),
            'departments' => CompanyUnit::where('type', 'department')->get(['id', 'name', 'parent_id', 'type']),
            'teams'       => CompanyUnit::where('type', 'team')->get(['id', 'name', 'parent_id', 'type']),
            'sections'    => CompanyUnit::where('type', 'section')->get(['id', 'name', 'parent_id', 'type']),
            'units'       => CompanyUnit::where('type', 'unit')->get(['id', 'name', 'parent_id', 'type']),
        ];

        $selectedCompanyIds = $user->companyUnits->pluck('id')->toArray();

        return Inertia::render('Core/Profile/EditProfile', [
            'user' => array_merge($user->toArray(), [
                'person' => $user->person ? array_merge($user->person->toArray(), [
                    'profile_pic_url' => $user->person->profile_pic
                        ? (function () use ($user) {
                            $pic = $user->person->profile_pic;
                            $decoded = is_string($pic) ? json_decode($pic, true) : $pic;
                            if (!isset($decoded['data'])) return null;
                            return $this->getFileForDisplay(
                                $decoded['data'],
                                $decoded['mime'] ?? 'image/jpeg',
                                'image'
                            )['url'];
                        })()
                        : null,
                ]) : null,
            ]),
            'roles'              => $roles,
            'positions'          => $positions,
            'companyUnits'       => $companyUnits,
            'selectedCompanyIds' => $selectedCompanyIds,
            'canEditDirectly'    => $canEditDirectly,
            'isOwnProfile'       => $currentUser->id === $user->id,
            'leaves'             => $leaves,
            'countries'          => $countries,
            'provinces'          => $provinces,
            'managers'           => $managers,
            'documents' => $user->person?->documents
                ?->map(fn($d) => [
                    'id'              => $d->id,
                    'doc_description' => $d->doc_description,
                    'date_from'       => $d->date_from,
                    'expirydate'      => $d->expirydate,
                    'supporting_docs' => $d->supporting_docs,
                    'hr_person_id'    => $d->hr_person_id,
                    'hr_change_id'    => $d->hr_change_id,
                    'category_id'     => $d->category_id,
                    'doc_type_id'     => $d->doc_type_id,
                ])
                ->toArray() ?? [],
        ]);
    }
    public function update(ProfileUpdateRequest $request, $uuid)
    {
        $hrperson=HRPerson::where('uuid', $uuid)->firstOrFail();

        $user = User::with([
            'person.hrPosition',
            'person.manager',
            'companyUnits',
            'roles',
            'person.leaveProfileData',
            'person.country',
            'person.province',
        ])->findOrFail($hrperson->user_id);

        // ── Conversion des dates en timestamp ──
        $dateFields = [
            'date_of_birth',
            'date_joined',
            'date_left',
            'med_start_date',
            'provident_start_date',
            'drivers_licence_exp_date',
            'proof_drive_permit_exp_date',
        ];

        $requestData = $request->all();
        foreach ($dateFields as $field) {
            $requestData[$field] = $request->filled($field)
                ? strtotime($request->input($field))
                : null;
        }

        // Password is a credential: always apply it directly to the profile
        // owner (never store plaintext in the HR approval queue).
        if ($request->filled('password')) {
            $user->update(['password' => Hash::make($request->password)]);
            PasswordHistory::createPassword($user->id, $user->password);
            PasswordSecurity::addExpiryDate($user->id);
        }

        $currentUser     = Auth::user();
        $canEditDirectly = $this->service->canUpdateDirectly($currentUser);

        if ($canEditDirectly) {
            if ($request->filled('role')) {
                $user->syncRoles([$request->role]);
            }
            if ($request->has('status')) {
                $user->update(['status' => $request->status]);
            }
            if ($request->filled('email')) {
                $user->update(['email' => $request->email]);
            }
        }

        $modifiedRequest = new Request($requestData);

        if ($request->hasFile('profile_pic')) {
            $modifiedRequest->files->set('profile_pic', $request->file('profile_pic'));
        }
        if ($request->hasFile('proof_drive_permit')) {
            $modifiedRequest->files->set('proof_drive_permit', $request->file('proof_drive_permit'));
        }

        if ($canEditDirectly) {
            $this->service->updateDirectly($user, $modifiedRequest);
            return redirect()->back()->with('success', 'Profile updated successfully.');
        }

        $this->service->submitForApproval($user, $modifiedRequest);
        return redirect()->back()->with('pending', 'Your changes have been submitted for HR review.');
    }
}
