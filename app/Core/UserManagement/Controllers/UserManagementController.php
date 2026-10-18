<?php

namespace App\Core\UserManagement\Controllers;

use App\Core\Storage\StoredImage;
use App\Core\User\Models\User;
use App\Core\User\Trait\FileStorage;
use App\Http\Controllers\Controller;
use App\Modules\HR\Hr\Models\HRPerson;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Inertia\Inertia;
use App\Core\Role\Models\Role;

class UserManagementController extends Controller
{
    use FileStorage;


    private function buildPersonArray($person): ?array
    {
        if (!$person) return null;

        $personArray = $person->toArray();

        // A URL rather than an embedded image. The field keeps its name and its meaning — an
        // <img src> takes either — but the response no longer carries the photo itself.
        $personArray['profile_pic_url'] = StoredImage::url($person->profile_pic_path, $person->profile_pic);

        $personArray['proof_drive_permit_url']          = null;
        $personArray['proof_drive_permit_mime']          = null;
        $personArray['proof_drive_permit_original_name'] = null;

        if ($person->proof_drive_permit) {
            $decoded = is_string($person->proof_drive_permit)
                ? json_decode($person->proof_drive_permit, true)
                : $person->proof_drive_permit;
            if (isset($decoded['data'])) {
                $mime = $decoded['mime'] ?? 'application/pdf';
                $personArray['proof_drive_permit_url']          = 'data:' . $mime . ';base64,' . $decoded['data'];
                $personArray['proof_drive_permit_mime']          = $mime;
                $personArray['proof_drive_permit_original_name'] = $decoded['original_name'] ?? 'permit';
            }
        }

        return $personArray;
    }

    public function profile()
    {
        $user = User::with([
            'person.hrPosition',
            'person.manager',
            'companyUnits',
            'roles',
            'person.leaveProfileData',
        ])->findOrFail(Auth::id());

        return Inertia::render('Core/Profile/Index', [
            'user'             => array_merge($user->toArray(), ['person' => $this->buildPersonArray($user->person)]),
            'position'         => $user->person?->hrPosition,
            'companyUnits'     => $user->companyUnits,
            'leaveProfileData' => $user->person?->leaveProfileData,
        ]);
    }

    /**
     * A profile, addressed either by the personnel record or by the user.
     *
     * It used to take a personnel record's uuid and nothing else, which meant that anybody without
     * one had no profile URL in existence. That is not a rare corner: identity lives in
     * `hr_people`, and accounts created outside the employee flow — a client, a subcontractor, a
     * portal login — have no row there. They appeared in Manage Users like everybody else, and
     * clicking through answered "no profile for this user", because the front end had nothing to
     * put in the URL but the literal "undefined".
     *
     * So both are accepted. A personnel uuid still resolves exactly as before; a user uuid
     * resolves the user directly and the page renders what is known about the account — email,
     * role, status, access — with the employee sections simply empty, which is the truth about
     * somebody who is not an employee.
     */
    public function profileUser(string $uuid)
    {
        // Still guarded: Postgres casts the parameter to uuid and would 500 on anything else.
        abort_unless(Str::isUuid($uuid), 404);

        $relations = [
            'person.hrPosition',
            'person.manager',
            'companyUnits',
            'roles',
            'person.leaveProfileData',
        ];

        $hrperson = HRPerson::where('uuid', $uuid)->first();

        $user = $hrperson
            ? User::with($relations)->find($hrperson->user_id)
            : User::with($relations)->where('uuid', $uuid)->first();

        abort_unless($user, 404);

        return Inertia::render('Core/Profile/UserProfile', [
            'user'             => array_merge($user->toArray(), ['person' => $this->buildPersonArray($user->person)]),
            'position'         => $user->person?->hrPosition,
            'companyUnits'     => $user->companyUnits,
            'leaveProfileData' => $user->person?->leaveProfileData,
        ]);
    }

    public function editUser($uuid)
    {
        abort_unless(Str::isUuid($uuid), 404);

        $hrperson = HRPerson::where('uuid', $uuid)->firstOrFail();

        $user  = User::with(['person', 'roles', 'companyUnits', 'person.leaveProfileData'])->findOrFail($hrperson->user_id);
        $roles = Role::orderBy('name')->get(['id', 'name']);

        return Inertia::render('Core/Profile/EditProfile', [
            'user'  => $user,
            'roles' => $roles,
            'companyUnits' => $user->companyUnits,
            'leaveProfileData' => $user->person?->leaveProfileData,
        ]);
    }

    public function updateUser(Request $request, $id)
    {
        $user = User::with('hrPerson')->findOrFail($id);

        $request->validate([
            'email'        => 'required|email|unique:users,email,' . $id,
            'first_name'   => 'required|string|max:255',
            'surname'      => 'required|string|max:255',
            'password'     => 'nullable|string|min:6',
            'profile_pic'  => 'nullable|image|max:2048',
        ]);

        $user->update(['email' => $request->email, 'status' => $request->status]);

        if ($request->filled('role')) $user->syncRoles([$request->role]);
        if ($request->filled('password')) $user->update(['password' => Hash::make($request->password)]);

        if ($request->hasFile('profile_pic')) {
            // Still in the database and never on the web server's filesystem — on the shared disk,
            // where encryption at rest and the tenant storage quota already apply.
            $request->merge([
                'profile_pic' => null,
                'profile_pic_path' => StoredImage::put($request->file('profile_pic'), 'people/photos'),
            ]);
        }

        $user->hrPerson?->update($request->only([
            'first_name',
            'surname',
            'middle_name',
            'initial',
            'gender',
            'cell_number',
            'phone_number',
            'date_of_birth',
            'job_function',
            'title',
            'res_city',
            'profile_pic',
        ]));

        return redirect()->back()->with('success', 'Profile updated.');
    }
}
