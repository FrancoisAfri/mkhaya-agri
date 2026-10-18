<?php

namespace App\Modules\Tenancy\Http\Controllers;

use App\Core\ManagerUser\Mail\UserWelcomeMail;
use App\Core\User\Models\User;
use App\Http\Controllers\Controller;
use App\Modules\Tenancy\Models\BillingAddon;
use App\Modules\Tenancy\Models\BillingModule;
use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\Models\Plan;
use App\Modules\Tenancy\Models\Reseller;
use App\Modules\Tenancy\Models\ResellerCommission;
use App\Modules\Tenancy\Models\ResellerUser;
use App\Modules\Tenancy\Services\CompanyProvisioner;
use App\Modules\Tenancy\Services\CompanySubscription;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Spatie\Permission\Models\Role;
use Inertia\Response;

/**
 * The reseller self-service portal (Phase 2). A reseller signs in and manages the clients
 * they bring: they can provision a new client (tenant), see all their clients, and track
 * the commission they have earned. Everything is scoped to the reseller resolved by the
 * EnsureReseller middleware (request attribute 'reseller') — a reseller can never see or
 * touch another reseller's clients.
 */
class ResellerPortalController extends Controller
{
    private function reseller(Request $request): Reseller
    {
        return $request->attributes->get('reseller');
    }

    private function isOwner(Request $request): bool
    {
        return $request->attributes->get('reseller_role') === 'owner';
    }

    /** Owners run the account: commission, the team and the profile. Staff look after clients. */
    private function ownerOnly(Request $request): void
    {
        abort_unless($this->isOwner($request), 403, 'Only an owner of this reseller account can do this.');
    }

    public function dashboard(Request $request): Response
    {
        $reseller = $this->reseller($request);

        $owner = $this->isOwner($request);

        return Inertia::render('Reseller/Dashboard', [
            'portalRole' => $owner ? 'owner' : 'staff',
            'reseller' => ['name' => $reseller->name, 'commission_type' => $reseller->commission_type, 'commission_value' => (float) $reseller->commission_value],
            'stats' => [
                'clients' => $reseller->clients()->count(),
                'active_clients' => $reseller->clients()->whereIn('status', ['trial', 'active'])->count(),
                'pending' => $owner ? (float) $reseller->commissions()->where('status', 'pending')->sum('commission_amount') : null,
                'paid' => $owner ? (float) $reseller->commissions()->where('status', 'paid')->sum('commission_amount') : null,
            ],
            'recent' => ! $owner ? [] : $reseller->commissions()->with('company:id,name')->orderByDesc('period')->limit(8)->get()
                ->map(fn (ResellerCommission $c) => [
                    'period' => $c->period, 'client' => $c->company?->name,
                    'amount' => (float) $c->commission_amount, 'status' => $c->status,
                ]),
        ]);
    }

    public function clients(Request $request): Response
    {
        $reseller = $this->reseller($request);

        $clients = $reseller->clients()->orderByDesc('id')->get()->map(fn (Company $c) => [
            'id' => $c->id,
            'name' => $c->name,
            'status' => $c->status,
            'plan' => $c->plan,
            'contact_email' => $c->contact_email,
            'monthly_total' => (float) $c->monthly_total,
            'created_at' => $c->created_at?->toDateString(),
        ]);

        return Inertia::render('Reseller/Clients', [
            'portalRole' => $this->isOwner($request) ? 'owner' : 'staff',
            'clients' => $clients,
            // Same subscription catalog the platform-admin "New company" form uses, so the
            // reseller gets the identical base-plan cards + à-la-carte modules/add-ons.
            'plans' => Plan::where('is_active', true)->orderBy('sort')->get()->map(fn ($p) => [
                'code' => $p->code, 'name' => $p->name, 'price_monthly' => (float) $p->price_monthly,
                'regular_price_monthly' => $p->regular_price_monthly !== null ? (float) $p->regular_price_monthly : null,
                'max_users' => $p->max_users, 'ai_token_quota' => $p->ai_token_quota, 'sms_quota' => $p->sms_quota,
                'storage_gb' => $p->storage_gb, 'all_modules' => $p->includesAllModules(),
            ]),
            'moduleCatalog' => BillingModule::where('is_active', true)->where('is_sellable', true)
                ->orderBy('category')->orderBy('sort')->get()
                ->map(fn ($m) => ['code' => $m->code, 'name' => $m->name, 'category' => $m->category, 'price' => (float) $m->price_monthly]),
            'addonCatalog' => BillingAddon::active()->orderBy('type')->orderBy('sort')->get()
                ->map(fn ($a) => ['type' => $a->type, 'code' => $a->code, 'name' => $a->name, 'unit_label' => $a->unit_label,
                    'units' => $a->units, 'price' => $a->price_monthly !== null ? (float) $a->price_monthly : null]),
        ]);
    }

    /** Provision a brand-new client (tenant), tagged with this reseller so commission accrues. */
    public function storeClient(Request $request, CompanyProvisioner $provisioner, CompanySubscription $subs): RedirectResponse
    {
        $reseller = $this->reseller($request);

        $data = $request->validate([
            'company_name' => 'required|string|max:150',
            'registration_number' => 'nullable|string|max:60',
            'admin_name' => 'required|string|max:150',
            'email' => 'required|email|unique:users,email',
            'phone' => 'nullable|string|max:30',
            'password' => 'required|string|min:8',
            'plan' => 'required|string|exists:plans,code',
            'trial_days' => 'nullable|integer|in:0,7,14,30',
            'modules' => 'nullable|array',
            'modules.*' => 'string|exists:billing_modules,code',
            'addons' => 'nullable|array',
            'addons.*.code' => 'required|string|exists:billing_addons,code',
            'addons.*.quantity' => 'required|integer|min:0',
        ]);

        $result = $provisioner->provision($data);
        $company = $result['company'];
        $company->update(['reseller_id' => $reseller->id]);

        // Apply the Subscription Builder composition on top of the base plan (same as the
        // platform-admin flow) so the client's modules/add-ons and monthly total match.
        $subs->setModuleAddons($company, $data['modules'] ?? []);
        $subs->setAddons($company, $data['addons'] ?? []);
        $subs->recomputeTotal($company);

        // Email the new client's admin their login details.
        $emailed = false;
        try {
            Mail::to($data['email'])->send(
                new UserWelcomeMail(name: $data['admin_name'], email: $data['email'], password: $data['password'])
            );
            $emailed = true;
        } catch (\Throwable $e) {
            Log::warning('Reseller client credential email failed', ['email' => $data['email'], 'error' => $e->getMessage()]);
        }

        return back()->with('success', "Client \"{$data['company_name']}\" created. They can sign in at ".rtrim(config('app.url'), '/').'/login')
            ->with('credentials', [
                'title' => 'Client admin login',
                'name' => $data['admin_name'],
                'email' => $data['email'],
                'password' => $data['password'],
                'login_url' => rtrim(config('app.url'), '/').'/login',
                'emailed' => $emailed,
            ]);
    }

    public function commissions(Request $request): Response
    {
        $this->ownerOnly($request);
        $reseller = $this->reseller($request);

        return Inertia::render('Reseller/Commissions', [
            'portalRole' => 'owner',
            'commissions' => $reseller->commissions()->with('company:id,name')->orderByDesc('period')->orderBy('company_id')->limit(300)->get()
                ->map(fn (ResellerCommission $c) => [
                    'period' => $c->period,
                    'client' => $c->company?->name,
                    'base_amount' => (float) $c->base_amount,
                    'commission_amount' => (float) $c->commission_amount,
                    'status' => $c->status,
                    'paid_at' => $c->paid_at?->toDateString(),
                ]),
            'totals' => [
                'pending' => (float) $reseller->commissions()->where('status', 'pending')->sum('commission_amount'),
                'paid' => (float) $reseller->commissions()->where('status', 'paid')->sum('commission_amount'),
            ],
        ]);
    }

    // ── Team ─────────────────────────────────────────────────────────────────────

    public function team(Request $request): Response
    {
        $this->ownerOnly($request);
        $reseller = $this->reseller($request);

        return Inertia::render('Reseller/Team', [
            'portalRole' => 'owner',
            'me' => (int) $request->user()->id,
            'members' => $reseller->team()->with('user:id,email,status,sso_last_login_at')->orderByDesc('role')->orderBy('first_name')->get()
                ->map(fn (ResellerUser $m) => ['id' => $m->id, 'user_id' => $m->user_id, 'name' => $m->fullName() ?: $m->user?->email,
                    'first_name' => $m->first_name, 'surname' => $m->surname, 'phone' => $m->phone,
                    'email' => $m->user?->email, 'role' => $m->role, 'active' => (int) ($m->user?->status ?? 0) === 1]),
        ]);
    }

    public function storeMember(Request $request): RedirectResponse
    {
        $this->ownerOnly($request);
        $reseller = $this->reseller($request);
        $data = $request->validate([
            'first_name' => 'required|string|max:100', 'surname' => 'required|string|max:100',
            'email' => 'required|email|unique:users,email', 'phone' => 'nullable|string|max:40',
            'role' => 'required|in:owner,staff', 'password' => 'required|string|min:8',
        ]);

        DB::transaction(function () use ($reseller, $data) {
            Role::findOrCreate('reseller', 'web');
            $user = User::create(['uuid' => (string) Str::uuid(), 'email' => $data['email'], 'password' => Hash::make($data['password']),
                'status' => 1, 'is_verified' => true, 'company_id' => null, 'password_changed_at' => now()]);
            $user->assignRole('reseller');
            ResellerUser::create(['reseller_id' => $reseller->id, 'user_id' => $user->id, 'role' => $data['role'],
                'first_name' => $data['first_name'], 'surname' => $data['surname'], 'phone' => $data['phone'] ?? null]);
        });

        $emailed = false;
        try {
            Mail::to($data['email'])->send(new UserWelcomeMail(name: "{$data['first_name']} {$data['surname']}", email: $data['email'], password: $data['password']));
            $emailed = true;
        } catch (\Throwable $e) {
            Log::warning('Reseller team login email failed', ['email' => $data['email'], 'error' => $e->getMessage()]);
        }

        return back()->with('success', "{$data['first_name']} has been added to your team.")->with('credentials', [
            'title' => 'Team member login', 'name' => "{$data['first_name']} {$data['surname']}", 'email' => $data['email'],
            'password' => $data['password'], 'login_url' => rtrim(config('app.url'), '/') . '/login', 'emailed' => $emailed,
        ]);
    }

    public function updateMember(Request $request, ResellerUser $member): RedirectResponse
    {
        $this->ownerOnly($request);
        $member = $this->member($request, $member);
        $data = $request->validate(['role' => 'required|in:owner,staff', 'first_name' => 'required|string|max:100',
            'surname' => 'required|string|max:100', 'phone' => 'nullable|string|max:40']);

        if ($member->user_id === $request->user()->id && $data['role'] !== $member->role) {
            return back()->withErrors(['role' => 'You cannot change your own role; ask another owner.']);
        }
        if ($member->isOwner() && $data['role'] === 'staff' && $this->otherOwners($member) === 0) {
            return back()->withErrors(['role' => 'The account needs at least one owner.']);
        }
        $member->update($data);

        return back()->with('success', 'Team member updated.');
    }

    /** Remove someone from the team: their login is switched off, never deleted, so history keeps its author. */
    public function removeMember(Request $request, ResellerUser $member): RedirectResponse
    {
        $this->ownerOnly($request);
        $member = $this->member($request, $member);
        if ($member->user_id === $request->user()->id) {
            return back()->withErrors(['member' => 'You cannot remove yourself.']);
        }
        if ($member->isOwner() && $this->otherOwners($member) === 0) {
            return back()->withErrors(['member' => 'The account needs at least one owner.']);
        }

        DB::transaction(function () use ($member) {
            User::query()->whereKey($member->user_id)->update(['status' => 0]);
            DB::table('sessions')->where('user_id', $member->user_id)->delete();
            $member->delete();
        });

        return back()->with('success', 'Removed from the team; their login no longer works.');
    }

    private function member(Request $request, ResellerUser $member): ResellerUser
    {
        abort_unless((int) $member->reseller_id === (int) $this->reseller($request)->id, 404);

        return $member;
    }

    private function otherOwners(ResellerUser $member): int
    {
        return ResellerUser::query()->where('reseller_id', $member->reseller_id)->where('role', 'owner')->whereKeyNot($member->id)->count();
    }

    // ── Profile ──────────────────────────────────────────────────────────────────

    public function profile(Request $request): Response
    {
        $reseller = $this->reseller($request);

        return Inertia::render('Reseller/Profile', [
            'portalRole' => $this->isOwner($request) ? 'owner' : 'staff',
            'profile' => $reseller->only(['name', 'type', 'registration_number', 'vat_number', 'contact_person', 'contact_email', 'contact_phone',
                'address', 'website', 'support_email']) + [
                'has_logo' => filled($reseller->logo),
                'converted_from' => $reseller->convertedFrom?->name,
                'converted_at' => $reseller->converted_at?->toDateString(),
                'commission' => $reseller->commission_type === 'percent' ? (float) $reseller->commission_value . '%' : 'R ' . number_format((float) $reseller->commission_value, 2),
            ],
        ]);
    }

    public function updateProfile(Request $request): RedirectResponse
    {
        $this->ownerOnly($request);
        $data = $request->validate([
            'name' => 'required|string|max:255', 'registration_number' => 'nullable|string|max:60', 'vat_number' => 'nullable|string|max:60',
            'contact_person' => 'nullable|string|max:255', 'contact_email' => 'nullable|email|max:255', 'contact_phone' => 'nullable|string|max:40',
            'address' => 'nullable|string|max:1000', 'website' => 'nullable|url|max:255', 'support_email' => 'nullable|email|max:255',
        ]);
        $this->reseller($request)->update($data);

        return back()->with('success', 'Profile saved.');
    }

    /** The logo carried over from the company (stored in the database, like company logos). */
    public function logo(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        $blob = json_decode((string) $this->reseller($request)->logo, true);
        abort_unless(is_array($blob) && ! empty($blob['data']), 404);

        return response(base64_decode($blob['data']), 200, ['Content-Type' => $blob['mime'] ?? 'image/png', 'Cache-Control' => 'private, max-age=3600']);
    }
}
