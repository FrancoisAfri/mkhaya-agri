<?php

namespace App\Modules\Tenancy\Http\Controllers;

use App\Core\User\Models\User;
use App\Http\Controllers\Controller;
use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\Models\CompanyMembership;
use App\Modules\Tenancy\Notifications\AddedToOrganisation;
use App\Modules\Tenancy\Services\Memberships;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

/**
 * "Organisations & members": the organisations a person belongs to (and switching between them),
 * and — for an organisation's administrators — bringing in people who already have an account in
 * another organisation: an agronomist, a contractor, an owner's second business.
 *
 * Each action checks its own authority: anybody may switch among their own memberships; only an
 * administrator of the organisation being worked in may add, re-role or suspend its members.
 */
class OrganisationController extends Controller
{
    /** Roles that may run an organisation's membership. */
    private const ADMIN_ROLES = ['administrator', 'super admin', 'Farm Owner'];

    /** Roles never handed out from here. */
    private const RESERVED = ['reseller', 'super admin'];

    public function __construct(private readonly Memberships $memberships, private readonly TenantContext $tenants) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_if(! $user->company_id, 403, 'Platform administrators use "view as company" instead.');
        $canManage = $this->canManage($user);
        $company = Company::query()->find($user->company_id);

        $members = $canManage ? $this->tenants->run(null, fn () => CompanyMembership::query()->with(['user', 'user.person'])
            ->where('company_id', $user->company_id)->orderBy('status')->get()
            ->map(fn (CompanyMembership $m) => [
                'id' => $m->id,
                'name' => $m->user?->name ?? 'Former user',
                'email' => $m->user?->email,
                'roles' => $m->roles ?? [],
                'status' => $m->status,
                'home' => (int) $m->user?->company_id === (int) $m->company_id,
                'working_in' => $m->user?->company_id ? Company::query()->whereKey($m->user->company_id)->value('name') : null,
                'is_me' => (int) $m->user_id === (int) $user->id,
            ])->values()) : [];

        return Inertia::render('Tenancy/Organisations', [
            'current' => ['id' => $company?->id, 'name' => $company?->name],
            'mine' => $this->memberships->for($user)->map(fn ($m) => [
                'id' => $m->company_id, 'name' => $m->company->name, 'roles' => (int) $m->company_id === (int) $user->company_id
                    ? $user->getRoleNames()->values() : ($m->roles ?? []),
                'current' => (int) $m->company_id === (int) $user->company_id, 'last_used_at' => $m->last_used_at?->diffForHumans(),
            ])->values(),
            'members' => $members,
            'roles' => $canManage ? $this->grantableRoles($user) : [],
            'can' => ['manage' => $canManage],
        ]);
    }

    public function switch(Request $request, int $company)
    {
        $this->memberships->switchTo($request->user(), $company);
        $name = Company::query()->whereKey($company)->value('name');
        $request->session()->forget(['finance_entity_id', 'impersonate_company_id']);
        $request->session()->flash('success', "You are now working in {$name}.");

        // Where this organisation's roles let them in — not a dashboard their roles here may not open.
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        return Inertia::location(\App\Core\Auth\Support\LandingPage::for($request->user()->fresh()));
    }

    public function invite(Request $request): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($this->canManage($actor), 403, 'Only an administrator of this organisation can add members.');
        $data = $request->validate([
            'email' => ['required', 'email'],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['string', Rule::in($this->grantableRoles($actor))],
        ]);
        $user = User::withoutGlobalScopes()->whereRaw('lower(email) = ?', [mb_strtolower(trim($data['email']))])->whereNull('deleted_at')->first();
        if (! $user) {
            throw ValidationException::withMessages(['email' => 'Nobody has an account with that email yet. Create them as a user of this organisation instead.']);
        }
        $company = Company::query()->findOrFail($actor->company_id);
        $this->memberships->add($user, $company, $data['roles'], $actor);

        try {
            $user->notify(new AddedToOrganisation($company->name, $actor->name, $data['roles']));
        } catch (\Throwable $e) {
            // The membership stands; the email is a courtesy and the mail server may be down.
            Log::warning('Could not email organisation membership', ['user' => $user->id, 'error' => $e->getMessage()]);
        }

        return back()->with('success', "{$user->email} can now switch into {$company->name}.");
    }

    public function update(Request $request, CompanyMembership $membership): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($this->canManage($actor) && (int) $membership->company_id === (int) $actor->company_id, 403);
        $data = $request->validate([
            'roles' => ['sometimes', 'array', 'min:1'],
            'roles.*' => ['string', Rule::in($this->grantableRoles($actor))],
            'status' => ['sometimes', Rule::in([CompanyMembership::ACTIVE, CompanyMembership::SUSPENDED])],
        ]);
        if ((int) $membership->user_id === (int) $actor->id) {
            throw ValidationException::withMessages(['membership' => 'You cannot change your own membership here.']);
        }

        $this->tenants->run(null, function () use ($membership, $data) {
            if (isset($data['roles'])) {
                $this->memberships->setRoles($membership, $data['roles']);
            }
            if (($data['status'] ?? null) === CompanyMembership::SUSPENDED) {
                $this->memberships->suspend($membership);
            } elseif (($data['status'] ?? null) === CompanyMembership::ACTIVE) {
                $membership->update(['status' => CompanyMembership::ACTIVE]);
            }
        });

        return back()->with('success', 'Membership updated.');
    }

    private function canManage(User $user): bool
    {
        return $user->company_id && ($user->hasAnyRole(self::ADMIN_ROLES) || $user->can('agri.access.manage'));
    }

    /**
     * An administrator may hand out any role. Anybody else only a role that gives nothing they do
     * not hold themselves — a farm owner can bring in an agronomist as a supervisor or viewer, but
     * cannot make anyone more powerful than they are.
     */
    private function grantableRoles(User $actor): array
    {
        $roles = Role::query()->with('permissions:id,name')->where('guard_name', 'web')->whereNotIn('name', self::RESERVED)->orderBy('name')->get();
        if ($actor->hasAnyRole(['administrator', 'super admin'])) {
            return $roles->pluck('name')->values()->all();
        }
        $mine = $actor->getAllPermissions()->pluck('name');

        return $roles->filter(fn ($r) => $r->permissions->isNotEmpty() && $r->permissions->pluck('name')->diff($mine)->isEmpty())
            ->pluck('name')->values()->all();
    }
}
