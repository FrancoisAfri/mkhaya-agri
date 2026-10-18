<?php

namespace App\Modules\SupplierManagement\Controllers;

use App\Http\Controllers\Controller;
use App\Core\User\Models\User;
use App\Modules\SupplierManagement\Models\Supplier;
use App\Modules\SupplierManagement\Models\SupplierDocumentType;
use App\Modules\SupplierManagement\Models\SupplierOnboarding;
use App\Modules\SupplierManagement\Notifications\SupplierRegistrationSubmitted;
use App\Modules\SupplierManagement\Services\SupplierOnboardingService;
use App\Modules\SupplierManagement\Services\SupplierService;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The door the onboarding invitation is a key to.
 *
 * The invitation, its 48-character token and its expiry window all existed and there was nowhere for
 * a supplier to take them: the only public surface in the module was the portal, which needs a
 * `Supplier` record the invited company does not have yet. So the token was a credential for a lock
 * with no door, and "onboarding" ended at the buyer's own screen.
 *
 * Unauthenticated by necessity — an invited company has no account, which is exactly what registering
 * is for. Everything the authenticated side gets from `auth` therefore has to be re-established from
 * the token: who this is, which tenant they belong to, and whether the invitation is still live.
 */
class SupplierRegistrationController extends Controller
{
    public function __construct(
        private readonly SupplierOnboardingService $onboarding,
        private readonly SupplierService $suppliers,
    ) {}

    public function show(string $token): Response
    {
        $onboarding = $this->resolve($token);

        return Inertia::render('Modules/SupplierManagement/Register/Index', [
            'token'      => $token,
            'onboarding' => [
                'company_name'                => $onboarding->company_name,
                'company_registration_number' => $onboarding->company_registration_number,
                'contact_person'              => $onboarding->contact_person,
                'contact_person_email'        => $onboarding->contact_person_email,
                'contact_person_phone'        => $onboarding->contact_person_phone,
                'expires_at'                  => $onboarding->expires_at?->toDateTimeString(),
            ],
            'documentTypes' => SupplierDocumentType::where('active', true)
                ->orderBy('document_type_name')
                ->get(['id', 'document_type_name', 'compulsory'])
                ->map(fn ($t) => [
                    'id'         => $t->id,
                    'name'       => $t->document_type_name,
                    'compulsory' => (bool) $t->compulsory,
                ])->values(),
        ]);
    }

    /**
     * Turn the invitation into a supplier record.
     *
     * The supplier is created pending rather than active — this is a company describing itself, and
     * nothing here has been checked by anybody. Activation stays where it was: behind the buyer's
     * own review, and behind the compulsory-document rule.
     */
    public function store(Request $request, string $token): RedirectResponse
    {
        $onboarding = $this->resolve($token);

        $data = $request->validate([
            'company_name'                => 'required|string|max:255',
            'trading_name'                => 'nullable|string|max:255',
            'company_registration_number' => 'nullable|string|max:255',
            'vat_registration_number'     => 'nullable|string|max:255',
            'tax_reference_number'        => 'nullable|string|max:255',
            'business_entity_type'        => 'nullable|string|max:255',
            'email'                       => 'required|email|max:255',
            'phone_number'                => 'nullable|string|max:40',
            'website_url'                 => 'nullable|string|max:255',
            'physical_address'            => 'nullable|string|max:1000',
            'postal_address'              => 'nullable|string|max:1000',
            'bank_name'                   => 'nullable|string|max:255',
            'account_holder_name'         => 'nullable|string|max:255',
            'account_type'                => 'nullable|string|max:64',
            'bank_account_number'         => 'nullable|string|max:64',
            'branch_code'                 => 'nullable|string|max:32',
            'contacts'                    => 'array',
            'contacts.*.first_name'       => 'required|string|max:255',
            'contacts.*.surname'          => 'nullable|string|max:255',
            'contacts.*.email'            => 'nullable|email|max:255',
            'contacts.*.tel'              => 'nullable|string|max:40',
            'directors'                   => 'array',
            'directors.*.first_name'      => 'required|string|max:255',
            'directors.*.surname'         => 'nullable|string|max:255',
            'directors.*.email'           => 'nullable|email|max:255',
            'directors.*.tel'             => 'nullable|string|max:40',
        ]);

        // `assertNotDuplicate` throws a ValidationException, which is the right answer here: a company
        // already on the list should be told so rather than quietly given a second record with a
        // second set of banking details.
        $this->suppliers->assertNotDuplicate($data);

        $supplier = DB::transaction(function () use ($data, $onboarding) {
            // Owned by whoever issued the invitation, not by nobody.
            //
            // `created_by` is how the rest of the platform decides who to tell about this supplier —
            // `procurement:expiry-alerts` returns early on a null user id, so a self-registered
            // supplier with no owner would never raise a compliance alert to anyone. Silence there
            // is worse than a wrong recipient: nothing looks broken, and the lapse is simply never
            // mentioned.
            $supplier = $this->suppliers->createSupplier(
                $data + ['is_active' => 0], // pending — nobody has checked this yet
                userId: $onboarding->employee_id,
            );

            $onboarding->update([
                'supplier_id'    => $supplier->id,
                'status'         => 'submitted',
                'current_status' => 1,
                // The invitation is spent. Leaving it live would let the same key make a second
                // company, and the registration form is the one place that cannot ask "are you sure".
                'expires_at'     => now(),
            ]);

            return $supplier;
        });

        $this->tellTheBuyer($onboarding, $supplier);


        return redirect()
            ->route('supplier.portal.show', $supplier->portal_token)
            ->with('success', __('supplier_management.registration.submitted'));
    }

    /**
     * Let the person who sent the invitation know it was answered.
     *
     * Addressed to the buyer staffer who raised it rather than broadcast: they are the one waiting,
     * and a pending supplier nobody is told about sits in a list until somebody happens to look.
     */
    private function tellTheBuyer(SupplierOnboarding $onboarding, Supplier $supplier): void
    {
        $owner = $onboarding->employee_id ? User::find($onboarding->employee_id) : null;

        $owner?->notify(SupplierRegistrationSubmitted::for($supplier));
    }

    /**
     * Resolve a live invitation, and bind the request to the tenant that issued it.
     *
     * Without the tenant binding the supplier this creates would be stamped with no company and
     * become invisible under row-level security to the very buyer who invited them — the same trap
     * the portal had to be fixed for.
     *
     * An expired invitation is refused with 410 rather than 404, because the two mean different
     * things to whoever is holding the link: one sends them hunting for a typo, the other sends them
     * back to their contact for a fresh invitation.
     */
    private function resolve(string $token): SupplierOnboarding
    {
        $onboarding = SupplierOnboarding::where('token', $token)->firstOrFail();

        abort_if(
            $this->onboarding->hasExpired($onboarding),
            410,
            __('supplier_management.registration.link_expired'),
        );

        abort_if(
            $onboarding->supplier_id !== null,
            410,
            __('supplier_management.registration.already_registered'),
        );

        if ($onboarding->company_id) {
            app(TenantContext::class)->apply((int) $onboarding->company_id);
        }

        return $onboarding;
    }
}
