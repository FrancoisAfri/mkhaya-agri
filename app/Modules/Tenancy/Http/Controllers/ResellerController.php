<?php

namespace App\Modules\Tenancy\Http\Controllers;

use App\Core\ManagerUser\Mail\UserWelcomeMail;
use App\Core\User\Models\User;
use App\Http\Controllers\Controller;
use App\Modules\CRM\Models\CrmProduct;
use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\Models\Reseller;
use App\Modules\Tenancy\Models\ResellerCommission;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

/**
 * Platform-admin management of the reseller program (Phase 1). Add/edit/suspend resellers,
 * link the service offerings they may sell, assign clients they brought, and manage the
 * monthly commission ledger. Gated by the platform.admin middleware on the route group.
 */
class ResellerController extends Controller
{
    public function index(): Response
    {
        // Per-reseller client counts and commission roll-ups in two grouped queries.
        $clientCounts = Company::whereNotNull('reseller_id')
            ->select('reseller_id', DB::raw('count(*) as c'))
            ->groupBy('reseller_id')->pluck('c', 'reseller_id');

        $pending = ResellerCommission::where('status', 'pending')
            ->select('reseller_id', DB::raw('sum(commission_amount) as s'))
            ->groupBy('reseller_id')->pluck('s', 'reseller_id');

        $paid = ResellerCommission::where('status', 'paid')
            ->select('reseller_id', DB::raw('sum(commission_amount) as s'))
            ->groupBy('reseller_id')->pluck('s', 'reseller_id');

        $resellers = Reseller::orderBy('name')->get()->map(fn (Reseller $r) => [
            'id' => $r->id,
            'uuid' => $r->uuid,
            'name' => $r->name,
            'type' => $r->type,
            'contact_person' => $r->contact_person,
            'contact_email' => $r->contact_email,
            'contact_phone' => $r->contact_phone,
            'commission_type' => $r->commission_type,
            'commission_value' => (float) $r->commission_value,
            'status' => $r->status,
            'clients' => (int) ($clientCounts[$r->id] ?? 0),
            'pending_total' => (float) ($pending[$r->id] ?? 0),
            'paid_total' => (float) ($paid[$r->id] ?? 0),
        ]);

        return Inertia::render('Tenancy/Resellers/Index', [
            'resellers' => $resellers,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateReseller($request);
        $data['created_by'] = $request->user()?->id;
        Reseller::create($data);

        return back()->with('success', 'Reseller added.');
    }

    public function update(Request $request, Reseller $reseller): RedirectResponse
    {
        $reseller->update($this->validateReseller($request));

        return back()->with('success', 'Reseller updated.');
    }

    public function suspend(Request $request, Reseller $reseller): RedirectResponse
    {
        $reseller->update([
            'status' => 'suspended',
            'suspended_at' => now(),
            'suspended_reason' => $request->input('reason'),
        ]);

        return back()->with('success', 'Reseller suspended — no further commission will accrue.');
    }

    public function activate(Reseller $reseller): RedirectResponse
    {
        $reseller->update(['status' => 'active', 'suspended_at' => null, 'suspended_reason' => null]);

        return back()->with('success', 'Reseller reactivated.');
    }

    public function destroy(Reseller $reseller): RedirectResponse
    {
        // Detach clients (keep the tenants; they simply become direct) and drop the reseller.
        Company::where('reseller_id', $reseller->id)->update(['reseller_id' => null]);
        $reseller->products()->detach();
        $reseller->commissions()->delete();
        // The team's logins stop working (the portal needs a reseller); they are not deleted.
        User::query()->whereIn('id', $reseller->team()->pluck('user_id'))->update(['status' => 0]);
        $reseller->team()->delete();
        $reseller->delete();

        return redirect()->route('tenancy.resellers.index')->with('success', 'Reseller removed.');
    }

    public function show(Reseller $reseller): Response
    {
        $reseller->load('products:id,name,type,price,currency');

        $clients = $reseller->clients()->orderByDesc('id')->get()->map(fn (Company $c) => [
            'id' => $c->id,
            'name' => $c->name,
            'status' => $c->status,
            'plan' => $c->plan,
            'monthly_total' => (float) $c->monthly_total,
            'created_at' => $c->created_at?->toDateString(),
        ]);

        $commissions = $reseller->commissions()
            ->with('company:id,name')->orderByDesc('period')->orderBy('company_id')->limit(300)->get()
            ->map(fn (ResellerCommission $x) => [
                'id' => $x->id,
                'period' => $x->period,
                'client' => $x->company?->name,
                'base_amount' => (float) $x->base_amount,
                'commission_amount' => (float) $x->commission_amount,
                'status' => $x->status,
                'paid_at' => $x->paid_at?->toDateString(),
            ]);

        return Inertia::render('Tenancy/Resellers/Show', [
            'reseller' => [
                'id' => $reseller->id,
                'uuid' => $reseller->uuid,
                'name' => $reseller->name,
                'type' => $reseller->type,
                'registration_number' => $reseller->registration_number,
                'contact_person' => $reseller->contact_person,
                'contact_email' => $reseller->contact_email,
                'contact_phone' => $reseller->contact_phone,
                'commission_type' => $reseller->commission_type,
                'commission_value' => (float) $reseller->commission_value,
                'status' => $reseller->status,
                'notes' => $reseller->notes,
                'product_ids' => $reseller->products->pluck('id'),
                'login_email' => $reseller->user_id ? User::find($reseller->user_id)?->email : null,
                'vat_number' => $reseller->vat_number,
                'converted_from' => $reseller->converted_from_company_id ? [
                    'name' => $reseller->convertedFrom?->name, 'at' => $reseller->converted_at?->toDateString(),
                    'purge_after' => $reseller->convertedFrom?->purge_after?->toDateString(), 'purged' => (bool) $reseller->convertedFrom?->purged_at,
                ] : null,
                'team' => $reseller->team()->with('user:id,email,status')->orderByDesc('role')->get()->map(fn ($m) => [
                    'name' => $m->fullName() ?: $m->user?->email, 'email' => $m->user?->email, 'role' => $m->role, 'active' => (int) ($m->user?->status ?? 0) === 1]),
            ],
            'products' => $reseller->products->map(fn ($p) => [
                'id' => $p->id, 'name' => $p->name, 'type' => $p->type,
                'price' => (float) $p->price, 'currency' => $p->currency,
            ]),
            'clients' => $clients,
            'commissions' => $commissions,
            'totals' => [
                'pending' => (float) $reseller->commissions()->where('status', 'pending')->sum('commission_amount'),
                'paid' => (float) $reseller->commissions()->where('status', 'paid')->sum('commission_amount'),
            ],
            // Catalog = the platform's own CRM products (company_id null), managed at
            // /crm/products; plus companies not yet tied to any reseller for the link control.
            'catalog' => CrmProduct::whereNull('company_id')->where('is_active', true)
                ->orderBy('name')->get(['id', 'name', 'type', 'price', 'currency']),
            'unassigned' => Company::whereNull('reseller_id')->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function syncProducts(Request $request, Reseller $reseller): RedirectResponse
    {
        $data = $request->validate([
            'product_ids' => 'array',
            'product_ids.*' => 'integer|exists:crm_products,id',
        ]);
        $reseller->products()->sync($data['product_ids'] ?? []);

        return back()->with('success', 'Products updated.');
    }

    public function assignClient(Request $request, Reseller $reseller): RedirectResponse
    {
        $data = $request->validate(['company_id' => 'required|integer|exists:companies,id']);
        Company::whereKey($data['company_id'])->update(['reseller_id' => $reseller->id]);

        return back()->with('success', 'Client linked to reseller.');
    }

    public function unassignClient(Request $request, Reseller $reseller): RedirectResponse
    {
        $data = $request->validate(['company_id' => 'required|integer']);
        Company::whereKey($data['company_id'])->where('reseller_id', $reseller->id)
            ->update(['reseller_id' => null]);

        return back()->with('success', 'Client unlinked.');
    }

    public function markPaid(ResellerCommission $commission): RedirectResponse
    {
        $commission->update(['status' => 'paid', 'paid_at' => now()]);

        return back()->with('success', 'Commission marked paid.');
    }

    /**
     * Create or update the reseller's self-service portal login. The login user is a
     * platform-level actor (company_id null, like a platform admin) with the 'reseller'
     * role; EnsureReseller resolves the reseller from resellers.user_id.
     */
    public function setLogin(Request $request, Reseller $reseller): RedirectResponse
    {
        $existingId = $reseller->user_id;
        $data = $request->validate([
            'email' => 'required|email|unique:users,email,'.($existingId ?: 'NULL').',id',
            'password' => ($existingId ? 'nullable' : 'required').'|string|min:8',
        ]);

        Role::firstOrCreate(['name' => 'reseller', 'guard_name' => 'web']);

        if ($existingId && ($user = User::find($existingId))) {
            $user->email = $data['email'];
            if (! empty($data['password'])) {
                $user->password = Hash::make($data['password']);
                $user->password_changed_at = now();
            }
            $user->save();
        } else {
            $user = User::create([
                'uuid' => (string) Str::uuid(),
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
                'status' => 1,
                'is_verified' => true,
                'company_id' => null, // platform-level actor
                'password_changed_at' => now(),
            ]);
            $reseller->update(['user_id' => $user->id]);
        }
        // The portal reads the team; the single login is its owner.
        \App\Modules\Tenancy\Models\ResellerUser::firstOrCreate(['user_id' => $user->id], ['reseller_id' => $reseller->id, 'role' => 'owner']);

        if (method_exists($user, 'assignRole') && ! $user->hasRole('reseller')) {
            $user->assignRole('reseller');
        }

        // Email the login to the reseller (company or individual) and surface the
        // credentials so the platform admin can copy them too — only when a password was
        // set this time (a plain email change has no password to send).
        if (! empty($data['password'])) {
            $emailed = $this->emailLogin($reseller->name, $data['email'], $data['password']);

            return back()->with('success', 'Reseller login saved.')->with('credentials', [
                'title' => 'Reseller portal login',
                'name' => $reseller->name,
                'email' => $data['email'],
                'password' => $data['password'],
                'login_url' => rtrim(config('app.url'), '/').'/login',
                'emailed' => $emailed,
            ]);
        }

        return back()->with('success', 'Reseller login updated.');
    }

    /** Email portal/login credentials; returns whether delivery succeeded. */
    private function emailLogin(string $name, string $email, string $password): bool
    {
        try {
            Mail::to($email)->send(
                new UserWelcomeMail(name: $name, email: $email, password: $password)
            );

            return true;
        } catch (\Throwable $e) {
            Log::warning('Reseller login email failed', ['email' => $email, 'error' => $e->getMessage()]);

            return false;
        }
    }

    private function validateReseller(Request $request): array
    {
        return $request->validate([
            'name' => 'required|string|max:255',
            'type' => 'required|in:individual,company',
            'registration_number' => 'nullable|string|max:60',
            'contact_person' => 'nullable|string|max:255',
            'contact_email' => 'nullable|email|max:255',
            'contact_phone' => 'nullable|string|max:40',
            'commission_type' => 'required|in:percent,fixed',
            'commission_value' => 'required|numeric|min:0',
            'notes' => 'nullable|string|max:2000',
        ]);
    }
}
