<?php

namespace App\Core\ManagerUser\Controllers;

use App\Core\Storage\StoredImage;
use App\Core\CompanyUnits\Models\CompanyUnit;
use App\Modules\Tenancy\Services\CurrentCompany;
use App\Core\ManagerUser\Mail\UserWelcomeMail;
use App\Core\ManagerUser\Notifications\UserWelcomeNotification;
use App\Core\ManagerUser\Service\ManagerUserService;
use App\Core\ManagerUser\Service\UserImportService;
use App\Core\Position\Models\HrPosition;
use App\Core\User\Models\User;
use App\Core\User\Trait\FileStorage;
use App\Core\UserManagement\Models\PasswordHistory;
use App\Core\UserManagement\Models\PasswordSecurity;
use App\Http\Controllers\Controller;
use App\Modules\HR\Hr\Models\HrPeopleChange;
use App\Modules\HR\Hr\Models\HRPerson;
use App\Modules\HR\Leave\Models\LeaveProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Inertia\Inertia;
use App\Core\Role\Models\Role;

class ManagerUserController extends Controller
{
    use FileStorage;

    protected $service;
    protected $userImportService;

    public function __construct(ManagerUserService $service, UserImportService $userImportService)
    {
        $this->service = $service;
        $this->userImportService = $userImportService;
    }

  public function index()
{
    try {
        // Tenant scope for all dropdowns. Regular tenant admins are already scoped by
        // the CompanyScope trait / RLS, but a PLATFORM ADMIN has no active company scope,
        // so without this they would see EVERY company's leave profiles, divisions, etc.
        // Resolve the current company (or fall back to the signed-in user's own company)
        // and filter every tenant list to it.
        $companyId = app(CurrentCompany::class)->id() ?? auth()->user()?->company_id;

        $users         = $this->service->getUsers();
        $roles         = Role::orderBy('name')->get(['id', 'name']);
        $positions     = HrPosition::when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->where('status', 1)->get();
        $leave_profile = LeaveProfile::when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->orderBy("name", "asc")->get();

        $managers = User::with(['person', 'roles'])
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
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

        $unitQuery = fn (string $type) => CompanyUnit::when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->where('type', $type)->where('active', true)
            ->orderBy('sort_order')->orderBy('name')
            ->get(['id', 'name', 'parent_id', 'type']);

        $companyUnits = [
            'divisions'   => $unitQuery('division'),
            'departments' => $unitQuery('department'),
            'teams'       => $unitQuery('team'),
            'sections'    => $unitQuery('section'),
            'units'       => $unitQuery('unit'),
        ];

        // Non-login employees (no user account) — listed separately so their leave
        // can be managed by a supervisor and a login can be added later.
        $nonLoginEmployees = HRPerson::when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->whereNull('user_id')
            ->where('status', 1)
            ->orderBy('first_name')
            ->get(['id', 'first_name', 'surname', 'employee_number', 'phone_number', 'email'])
            ->map(fn ($p) => [
                'id'              => $p->id,
                'first_name'      => $p->first_name,
                'surname'         => $p->surname,
                'employee_number' => $p->employee_number,
                'phone_number'    => $p->phone_number,
            ]);

        return Inertia::render('Core/ManagerUser/Index', [
            'users'          => $users,
            'roles'          => $roles,
            'positions'      => $positions,
            'leave_profiles' => $leave_profile ?? [],
            'companyUnits'   => $companyUnits,
            'managers'       => $managers,
            'nonLoginEmployees' => $nonLoginEmployees,
        ]);

    } catch (\Exception $e) {
        Log::error('Error loading manager user page: ' . $e->getMessage());

        return Inertia::render('Core/ManagerUser/Index', [
            'users'          => [],
            'roles'          => [],
            'positions'      => [],
            'leave_profiles' => [],
            'companyUnits'   => [
                'divisions'   => [],
                'departments' => [],
                'teams'       => [],
                'sections'    => [],
                'units'       => [],
            ],
            'managers' => [],
            'nonLoginEmployees' => [],
        ]);
    }
}

    public function storeUser(Request $request)
    {
        // Employees who won't sign in (e.g. cleaners) get a person-only record with
        // no user account — their supervisor manages their leave. Billed separately.
        if (! $request->boolean('will_login', true)) {
            return $this->storeNonLoginEmployee($request);
        }

        // SaaS plan quota: block adding users beyond the company's plan limit.
        if (! app(\App\Modules\Tenancy\Services\CompanyEntitlements::class)->currentCanAddUser()) {
            return back()->with('error', 'You have reached the user limit for your plan. Please upgrade to add more users.');
        }

        $request->validate([
            'email'                => ['required', 'email', \Illuminate\Validation\Rule::unique('users', 'email')->whereNull('deleted_at')],
            'position_id'          => 'nullable|exists:hr_positions,id',
            'first_name'           => 'required|string|max:255',
            'surname'              => 'required|string|max:255',
            'initial'              => 'nullable|string|max:10',
            'phone_number'         => 'nullable|string|max:20',
            'role'                 => 'nullable|string|exists:roles,name',
            'gender'               => 'nullable|integer|in:1,2',
            'employee_number'      => 'nullable|string|max:50',
            'date_joined'          => 'nullable|date',
            'date_left'            => 'nullable|date',
            'leave_profile'        => 'nullable|integer|max:100',
            'occupational_level'   => 'nullable|integer|max:100',
            'job_function'         => 'nullable|integer|max:100',
            'nature_of_disability' => 'nullable|string|max:255',
            'disabled'             => 'nullable|boolean',
            'company_id'           => 'nullable|array',
            'company_ids.*'         => 'integer|exists:company_units,id',
            'manager_id'            => 'nullable|integer|exists:users,id',
        ]);

        $companyIds = $request->company_ids;
        if (!is_array($companyIds)) {
            $companyIds = $companyIds ? [$companyIds] : [];
        }

        Log::info('Company IDs received:', $companyIds);

        $plainPassword = Str::random(10) . '!';

        // Re-adding someone who was deleted before? Restore the account (its email
        // is still reserved) instead of failing, so nothing is set up from scratch.
        $trashed = User::onlyTrashed()->where('email', $request->email)->first();
        if ($trashed) {
            $trashed->restore();
            $trashed->update(['password' => Hash::make($plainPassword), 'status' => 1]);
            $user = $trashed;
        } else {
            $user = User::create([
                'email'    => $request->email,
                'password' => Hash::make($plainPassword),
                'type'     => 1,
                'status'   => 1,
            ]);
        }

        $this->syncCompanyUnits($user, $companyIds);

        $personData = [
            'first_name'           => $request->first_name,
            'surname'              => $request->surname,
            'initial'              => $request->initial,
            'phone_number'         => $request->phone_number,
            'email'                => $request->email,
            'status'               => 1,
            'position_id'          => $request->position_id,
            'employee_number'      => $request->employee_number,
            'date_joined'          => strtotime($request->date_joined),
            'date_left'            => strtotime($request->date_left),
            'leave_profile'        => $request->leave_profile,
            'occupational_level'   => $request->occupational_level,
            'job_function'         => $request->job_function,
            'nature_of_disability' => $request->nature_of_disability,
            'disabled'             => filter_var($request->disabled, FILTER_VALIDATE_BOOLEAN),
            'gender'               => $request->gender,
        ];

        // hr_people is NOT soft-deletable (no deleted_at / SoftDeletes), so a
        // restored user still carries its original person row. Update it in place
        // if present; otherwise create a fresh one.
        $existingPerson = HRPerson::where('user_id', $user->id)->first();
        if ($existingPerson) {
            $existingPerson->fill($personData)->save();
        } else {
            $user->addPerson(new HRPerson($personData + ['uuid' => Str::uuid()]));
        }

        PasswordHistory::createPassword($user->id, Hash::make($plainPassword));
        PasswordSecurity::addExpiryDate($user->id);

        if ($request->filled('role')) {
            $user->syncRoles([$request->role]);
        }

        $fullName = "{$request->first_name} {$request->surname}";

        $emailed = true;
        try {
            Mail::to($user->email)->send(new UserWelcomeMail(
                name: $fullName,
                email: $request->email,
                password: $plainPassword,
            ));
        } catch (\Throwable $e) {
            $emailed = false;
            Log::warning("Welcome mail failed for {$user->email}: " . $e->getMessage());
        }

        try {
            $user->notify(new UserWelcomeNotification($fullName, $user->email));
        } catch (\Throwable $e) {
            Log::warning("Welcome notification failed for {$user->email}: " . $e->getMessage());
        }

        // Surface the credentials so the admin can copy + send them manually if the
        // email didn't arrive.
        return redirect()->back()
            ->with('success', 'user_created_successfully')
            ->with('credentials', [
                'title'     => 'User login details',
                'name'      => $fullName,
                'email'     => $request->email,
                'password'  => $plainPassword,
                'login_url' => rtrim(config('app.url'), '/') . '/login',
                'emailed'   => $emailed,
            ]);
    }

    /**
     * Create a non-login employee: a person (hr_people) record with no user account,
     * no email/password required. Identity is the (auto) employee number + cell.
     */
    private function storeNonLoginEmployee(Request $request)
    {
        if (! app(\App\Modules\Tenancy\Services\CompanyEntitlements::class)->currentCanAddNonLoginEmployee()) {
            return back()->with('error', 'You have reached your non-login employee limit. Add more non-login employee slots in billing to add more.');
        }

        $request->validate([
            'first_name'         => 'required|string|max:255',
            'surname'            => 'required|string|max:255',
            'phone_number'       => 'required|string|max:20',
            'initial'            => 'nullable|string|max:10',
            'email'              => 'nullable|email',
            'position_id'        => 'nullable|exists:hr_positions,id',
            'employee_number'    => 'nullable|string|max:50',
            'date_joined'        => 'nullable|date',
            'leave_profile'      => 'nullable|integer|max:100',
            'occupational_level' => 'nullable|integer|max:100',
            'job_function'       => 'nullable|integer|max:100',
            'gender'             => 'nullable|integer|in:1,2',
        ]);

        HRPerson::create([
            'uuid'               => (string) Str::uuid(),
            'user_id'            => null,
            'first_name'         => $request->first_name,
            'surname'            => $request->surname,
            'initial'            => $request->initial,
            'phone_number'       => $request->phone_number,
            'email'              => $request->email,
            'employee_number'    => $request->filled('employee_number') ? $request->employee_number : $this->nextEmployeeNumber(),
            'position_id'        => $request->position_id,
            'date_joined'        => $request->date_joined ? strtotime($request->date_joined) : null,
            'leave_profile'      => $request->leave_profile,
            'occupational_level' => $request->occupational_level,
            'job_function'       => $request->job_function,
            'gender'             => $request->gender,
            'status'             => 1,
        ]);

        return redirect()->back()->with('success', 'employee_created_successfully');
    }

    /**
     * Give an existing non-login employee a login account (when they later need to
     * sign in). Requires an email; consumes a user seat and emails the credentials.
     */
    public function enableLogin(Request $request, $id)
    {
        $person = HRPerson::findOrFail($id);

        abort_if($person->user_id !== null, 422, 'This employee already has a login account.');

        if (! app(\App\Modules\Tenancy\Services\CompanyEntitlements::class)->currentCanAddUser()) {
            return back()->with('error', 'You have reached the user limit for your plan. Please upgrade to add a login for this employee.');
        }

        $data = $request->validate([
            'email' => ['required', 'email', \Illuminate\Validation\Rule::unique('users', 'email')->whereNull('deleted_at')],
            'role'  => 'nullable|string|exists:roles,name',
        ]);

        $plainPassword = Str::random(10) . '!';

        $user = User::create([
            'email'    => $data['email'],
            'password' => Hash::make($plainPassword),
            'type'     => 1,
            'status'   => 1,
        ]);

        $person->fill(['user_id' => $user->id, 'email' => $data['email']])->save();

        PasswordHistory::createPassword($user->id, Hash::make($plainPassword));
        PasswordSecurity::addExpiryDate($user->id);

        if (! empty($data['role'])) {
            $user->syncRoles([$data['role']]);
        }

        $fullName = trim("{$person->first_name} {$person->surname}");
        $emailed  = true;
        try {
            Mail::to($user->email)->send(new UserWelcomeMail(name: $fullName, email: $user->email, password: $plainPassword));
        } catch (\Throwable $e) {
            $emailed = false;
            Log::warning("Welcome mail failed for {$user->email}: " . $e->getMessage());
        }

        return back()
            ->with('success', 'login_enabled_successfully')
            ->with('credentials', [
                'title'     => 'New login details',
                'name'      => $fullName,
                'email'     => $user->email,
                'password'  => $plainPassword,
                'login_url' => rtrim(config('app.url'), '/') . '/login',
                'emailed'   => $emailed,
            ]);
    }

    /** Next per-tenant employee number (EMPnnnnn). Reads are RLS-scoped on web. */
    private function nextEmployeeNumber(): string
    {
        $count = (int) HRPerson::count();

        return 'EMP' . str_pad((string) ($count + 1), 5, '0', STR_PAD_LEFT);
    }

    /**
     * Synchronise les unités organisationnelles (plusieurs possibles)
     */
    private function syncCompanyUnits(User $user, array $companyIds): void
    {
        if (empty($companyIds)) {
            $user->companyUnits()->detach();
            Log::info("Aucune unité sélectionnée pour l'utilisateur {$user->id}");
            return;
        }

        $existingIds = CompanyUnit::whereIn('id', $companyIds)
            ->where('active', true)
            ->pluck('id')
            ->toArray();

        if (empty($existingIds)) {
            Log::warning('Aucun ID valide trouvé pour les unités: ', $companyIds);
            return;
        }

        if (count($existingIds) !== count($companyIds)) {
            $missingIds = array_diff($companyIds, $existingIds);
            Log::warning('IDs manquants dans company_units:', $missingIds);
        }

        $user->companyUnits()->sync($existingIds);

        Log::info("Unités synchronisées pour l'utilisateur {$user->id}: ", $existingIds);
    }

    public function toggleStatus(Request $request, $id)
    {
        $request->validate(['status' => 'required|integer|in:0,1']);
        User::findOrFail($id)->update(['status' => $request->status]);
        return redirect()->back();
    }

    public function deleteUser($id)
    {
        if ((int) $id === (int) auth()->id()) {
            return redirect()->back()->with('error', 'You cannot delete your own account.');
        }

        $user = User::with('person')->findOrFail($id);

        try {
            $this->removeUser($user);
        } catch (\Throwable $e) {
            Log::error('User delete failed', ['id' => $id, 'error' => $e->getMessage()]);
            return redirect()->back()->with('error', 'Could not delete this user.');
        }

        return redirect()->back()->with('success', 'User deleted successfully.');
    }

    /** Soft-delete one or more users by id (bulk). */
    public function bulkDelete(Request $request)
    {
        $data = $request->validate([
            'ids'   => 'required|array|min:1',
            'ids.*' => 'integer',
        ]);

        // Never let an admin delete themselves in a batch.
        $ids = array_values(array_filter($data['ids'], fn ($id) => (int) $id !== (int) auth()->id()));

        $deleted = 0;
        foreach (User::with('person')->whereIn('id', $ids)->get() as $user) {
            try {
                $this->removeUser($user);
                $deleted++;
            } catch (\Throwable $e) {
                Log::error('Bulk user delete failed', ['id' => $user->id, 'error' => $e->getMessage()]);
            }
        }

        $skipped = count($data['ids']) - $deleted;
        $msg = "{$deleted} user(s) deleted." . ($skipped > 0 ? " {$skipped} skipped." : '');

        return redirect()->back()->with($deleted > 0 ? 'success' : 'error', $msg);
    }

    /** Detach pivots and soft-delete the user (+ their person record). */
    private function removeUser(User $user): void
    {
        DB::transaction(function () use ($user) {
            $user->companyUnits()->detach();
            if ($user->person) {
                $user->person->delete(); // HrPerson soft-deletes
            }
            $user->delete();             // soft delete — preserves referential integrity
        });
    }

    public function editUser(string $uuid)
    {
        $hrPerson = HrPerson::where('uuid', $uuid)->firstOrFail();
        $user  = User::with('person', 'roles', 'companyUnits')->findOrFail($hrPerson->user_id);
        $roles = Role::orderBy('name')->get(['id', 'name']);

        return Inertia::render('Core/ManagerUser/Edit', [
            'user'  => $user,
            'roles' => $roles,
        ]);
    }

    public function updateUser(Request $request, $id)
    {
        $user = User::with('person')->findOrFail($id);

        $request->validate([
            'email'        => 'required|email|unique:users,email,' . $id,
            'lockout_time' => 'nullable|integer|min:1',
            'first_name'   => 'required|string|max:255',
            'surname'      => 'required|string|max:255',
            'initial'      => 'nullable|string|max:10',
            'cell_number'  => 'nullable|string|max:20',
            'password'     => 'nullable|string|min:6',
            'role'         => 'nullable|string|exists:roles,name',
            'profile_pic'  => 'nullable|image|max:2048',
            'company_id'   => 'nullable|array',
            'company_id.*' => 'integer|exists:company_units,id',
        ]);

        $user->update([
            'email'        => $request->email,
            'lockout_time' => $request->lockout_time ?? $user->lockout_time,
        ]);

        if ($request->filled('password')) {
            $user->update(['password' => Hash::make($request->password)]);
            PasswordHistory::createPassword($user->id, Hash::make($request->password));
        }

        if ($request->filled('role')) {
            $user->syncRoles([$request->role]);
        }

        if ($request->has('company_id')) {
            $companyIds = is_array($request->company_id) ? $request->company_id : [$request->company_id];
            $this->syncCompanyUnits($user, $companyIds);
        }

        if ($user->person) {
            $personData = [
                'first_name'  => $request->first_name,
                'surname'     => $request->surname,
                'initial'     => $request->initial,
                'cell_number' => $request->cell_number,
                'email'       => $request->email,
            ];

            if ($request->hasFile('profile_pic')) {
                // Store the avatar in the database (base64 JSON), never on disk.
                $personData['profile_pic'] = null;
                $personData['profile_pic_path'] = StoredImage::put($request->file('profile_pic'), 'people/photos');
            }

            $user->person->update($personData);
        }

        return redirect()->back()->with('success', 'User updated successfully.');
    }

    public function userImport(Request $request)
    {
        return $this->userImportService->bulkImportUsers($request, $request->boolean('non_login'));
    }

    public function report()
    {
        $managerCount = DB::table('company_user_pivots')
            ->where('type', 1)
            ->distinct('user_id')
            ->count('user_id');

        $stats = [
            'total_users' => User::count(),
            'active_users' => User::where('status', 1)->count(),
            'inactive_users' => User::where('status', 0)->count(),
            'pending_approvals' => HrPeopleChange::where('is_approved', 0)->count(),
            'approved_changes' => HrPeopleChange::where('is_approved', 1)
                ->whereMonth('created_at', now()->month)
                ->count(),
            'rejected_changes' => 0,
            'admin_users' => User::role(['administrator', 'executive'])->count(),
            'manager_users' => $managerCount,
            'employee_users' => User::where('type', 1)->count(),
        ];

        $usersByRole = [
            'labels' => ['Admin', 'Manager', 'Employee', 'Contact'],
            'counts' => [
                $stats['admin_users'],
                $stats['manager_users'],
                $stats['employee_users'],
                User::where('type', 2)->count(),
            ],
        ];

        $approvalsByMonth = $this->getApprovalsByMonth();

        $recentActivities = $this->getRecentActivities();

        return Inertia::render('Core/ManagerUser/Report', [
            'stats' => $stats,
            'usersByRole' => $usersByRole,
            'approvalsByMonth' => $approvalsByMonth,
            'recentActivities' => $recentActivities,
        ]);
    }


    private function getApprovalsByMonth(): array
    {
        $data = HrPeopleChange::select(
            DB::raw("TO_CHAR(created_at, 'Mon') as month"),
            DB::raw('COUNT(*) as count')
        )
            ->where('created_at', '>=', now()->subMonths(6))
            ->groupBy('month')
            ->orderBy(DB::raw("MIN(created_at)"))
            ->get();

        return [
            'labels' => $data->pluck('month')->toArray(),
            'counts' => $data->pluck('count')->toArray(),
        ];
    }


    private function getRecentActivities(): array
    {
        $activities = [];

        $newUsers = User::with('person')
            ->latest()
            ->take(5)
            ->get()
            ->map(function ($u) {
                $userName = $u->person
                    ? $u->person->first_name . ' ' . $u->person->surname
                    : 'System';

                return [
                    'id' => $u->id,
                    'type' => 'user_created',
                    'description' => "New user created: {$u->email}",
                    'user' => $userName,
                    'created_at' => $u->created_at->toISOString(),
                ];
            });

        $approvals = HrPeopleChange::with('hrPerson')
            ->latest()
            ->take(5)
            ->get()
            ->map(function ($a) {
                $name = $a->hrPerson
                    ? $a->hrPerson->first_name . ' ' . $a->hrPerson->surname
                    : 'User #' . $a->hr_main_id;

                return [
                    'id' => $a->id,
                    'type' => $a->is_approved ? 'approval_approved' : 'approval_requested',
                    'description' => $a->is_approved
                        ? "Profile changes approved for {$name}"
                        : "Profile changes requested for {$name}",
                    'user' => 'HR System',
                    'created_at' => $a->created_at->toISOString(),
                ];
            });

        $accessChanges = DB::table('activity_log')
            ->where('log_name', 'User Management')
            ->whereIn('event', ['updated', 'deleted', 'created'])
            ->latest()
            ->take(5)
            ->get()
            ->map(function ($log) {
                $properties = json_decode($log->properties, true);
                $userName = $properties['causer_name'] ?? 'System';

                return [
                    'id' => $log->id,
                    'type' => 'access_changed',
                    'description' => $log->description ?? 'User access modified',
                    'user' => $userName,
                    'created_at' => $log->created_at,
                ];
            });

        return $newUsers->merge($approvals)
            ->merge($accessChanges)
            ->sortByDesc('created_at')
            ->take(10)
            ->values()
            ->toArray();
    }
}
