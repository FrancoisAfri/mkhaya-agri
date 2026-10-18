<?php

namespace App\Modules\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Core\User\Models\User;
use App\Core\UserManagement\Models\PasswordHistory;
use App\Core\UserManagement\Models\PasswordSecurity;
use App\Modules\HR\Hr\Models\HRPerson;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class PlatformAdminController extends Controller
{
    public function index(): Response
    {
        $admins = User::where('is_platform_admin', true)
            ->with('person:id,user_id,first_name,surname', 'platformAdminGrantedBy.person:id,user_id,first_name,surname')
            ->orderByDesc('is_master_admin')
            ->orderBy('created_at')
            ->get()
            ->map(fn($u) => [
                'id'              => $u->id,
                'email'           => $u->email,
                'name'            => $u->name,
                'is_master_admin' => $u->is_master_admin,
                'granted_by'      => $u->platformAdminGrantedBy?->name,
                'created_at'      => $u->created_at->toDateString(),
            ]);

        $eligible = User::where('is_platform_admin', false)
            ->with('person:id,user_id,first_name,surname')
            ->orderBy('created_at')
            ->get()
            ->map(fn($u) => [
                'id'    => $u->id,
                'email' => $u->email,
                'name'  => $u->name,
            ]);

        return Inertia::render('Core/SystemAdmin/PlatformAdmin/Index', [
            'admins'   => $admins,
            'eligible' => $eligible,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'first_name'   => 'required|string|max:80',
            'surname'      => 'required|string|max:80',
            'email'        => 'required|email|unique:users,email',
            'phone_number' => 'nullable|string|max:20',
            'password'     => 'required|string|min:8|confirmed',
        ]);

        $actor = $request->user();

        $user = User::create([
            'uuid'                      => (string) Str::uuid(),
            'email'                     => $request->email,
            'password'                  => Hash::make($request->password),
            'type'                      => 3,
            'status'                    => 1,
            'is_platform_admin'         => true,
            'is_master_admin'           => false,
            'platform_admin_granted_by' => $actor->id,
        ]);

        $user->person()->save(new HRPerson([
            'uuid'         => (string) Str::uuid(),
            'first_name'   => $request->first_name,
            'surname'      => $request->surname,
            'initial'      => strtoupper(substr($request->first_name, 0, 1)),
            'email'        => $request->email,
            'phone_number' => $request->phone_number,
            'status'       => 1,
        ]));

        $user->assignRole('administrator');

        PasswordHistory::createPassword($user->id, Hash::make($request->password));
        PasswordSecurity::addExpiryDate($user->id);

        activity()
            ->causedBy($actor)
            ->performedOn($user)
            ->withProperties(['created_by' => $actor->email, 'new_user' => $user->email])
            ->log('platform_admin.created');

        return back()->with('success', "{$request->first_name} {$request->surname} has been added as a platform administrator.");
    }

    public function grant(Request $request, int $userId): RedirectResponse
    {
        $actor  = $request->user();
        $target = User::findOrFail($userId);

        if ($target->is_platform_admin) {
            return back()->with('error', "{$target->name} is already a platform administrator.");
        }

        $target->update([
            'is_platform_admin'         => true,
            'platform_admin_granted_by' => $actor->id,
        ]);

        activity()
            ->causedBy($actor)
            ->performedOn($target)
            ->withProperties(['granted_by' => $actor->email])
            ->log('platform_admin.granted');

        return back()->with('success', "{$target->name} has been granted platform administrator access.");
    }

    public function revoke(Request $request, int $userId): RedirectResponse
    {
        $actor  = $request->user();
        $target = User::findOrFail($userId);

        if ($target->is_master_admin) {
            return back()->with('error', 'The master administrator account cannot be revoked.');
        }

        if (! $target->is_platform_admin) {
            return back()->with('error', "{$target->name} is not a platform administrator.");
        }

        $target->update([
            'is_platform_admin'         => false,
            'platform_admin_granted_by' => null,
        ]);

        activity()
            ->causedBy($actor)
            ->performedOn($target)
            ->withProperties(['revoked_by' => $actor->email])
            ->log('platform_admin.revoked');

        return back()->with('success', "{$target->name}'s platform administrator access has been revoked.");
    }
}
