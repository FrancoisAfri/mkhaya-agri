<?php

namespace App\Modules\Operations\Sheq\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\Sheq\Services\ContractorPortalService;
use App\Modules\Operations\Sheq\Services\ContractorService;
use App\Modules\Operations\Sheq\Services\FileService;
use App\Modules\SupplierManagement\Models\Supplier;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The contractor's own view of their compliance (CON-18).
 *
 * Public and unauthenticated, like the supplier portal it lives inside: the `portal_token` is
 * the credential. There is deliberately no separate SHEQ login — Supplier Management already
 * onboards these companies and already issued them a token, and a second portal would mean one
 * contractor holding two credentials for the same customer.
 *
 * Which is why this controller resolves its tenant exactly the way `SupplierPortalController`
 * does, from the token outward: nothing is signed in, so no company is set, and every read and
 * write afterwards has to be bound to the supplier's own company or RLS will either hide the
 * rows or let the wrong ones through.
 */
class ContractorPortalController extends Controller
{
    public function __construct(private readonly ContractorPortalService $portal) {}

    public function show(string $token): Response
    {
        $supplier = $this->resolveSupplier($token);

        $compliance = $this->portal
            ->forSupplier((int) $supplier->company_id, (int) $supplier->id);

        return Inertia::render('Modules/Sheq/Portal/Safety', [
            'token' => $token,
            'supplierName' => $supplier->company_name,
            'hostName' => \Illuminate\Support\Facades\DB::table('companies')
                ->where('id', $supplier->company_id)->value('name'),
            /*
             * Null where this supplier is not on the contractor register. Plenty of suppliers
             * deliver stationery and never set foot on a site; showing them an empty safety
             * page invites a telephone call asking what they have done wrong.
             */
            'compliance' => $compliance,
        ]);
    }

    /**
     * Take a document from the contractor.
     *
     * It arrives as SUPPLIED. The service enforces that and this does not override it: CON-18
     * says a contractor cannot approve their own documents, and the internal filing path
     * defaults to ACCEPTED because a safety officer has just looked at the thing. Reaching that
     * default from here would let somebody clear their own gate by uploading a blank page.
     */
    public function upload(Request $request, string $token): RedirectResponse
    {
        $supplier = $this->resolveSupplier($token);
        $companyId = (int) $supplier->company_id;

        $data = $request->validate([
            /*
             * Only the types this host has asked for or already holds. A free-text code would
             * let a contractor file against a type nobody reviews, which then sits looking
             * like progress.
             */
            'document_type' => ['required', 'string', 'max:40',
                Rule::in(array_keys(ContractorService::documentTypes())),
            ],
            'title' => ['nullable', 'string', 'max:255'],
            'reference' => ['nullable', 'string', 'max:120'],
            'issuing_body' => ['nullable', 'string', 'max:255'],
            'issued_on' => ['nullable', 'date'],
            // Not in the past: a certificate that has already expired is not a replacement, and
            // accepting one puts a document in the queue that will be rejected on sight.
            'expires_on' => ['nullable', 'date', 'after:today'],
            'file' => ['required', 'file', 'max:20480',
                'mimes:pdf,jpg,jpeg,png,doc,docx',
            ],
        ]);

        $stored = app(FileService::class)->store($companyId, $request->file('file'), [
            'subject_type' => 'CONTRACTOR_DOCUMENT',
            'subject_id' => 0,
            'category' => 'PORTAL_UPLOAD',
            'caption' => $data['title'] ?? $data['document_type'],
        ], null);

        $this->portal->supplyDocument($companyId, (int) $supplier->id, array_merge($data, [
            'file_path' => $stored['uuid'] ?? null,
        ]));

        return back()->with('success', __('sheq.portal.upload_done'));
    }

    /**
     * The supplier behind a portal token, with the tenant bound.
     *
     * Copied from `SupplierPortalController` rather than shared, deliberately: that controller
     * belongs to another module and this one must keep working if its internals change. The two
     * rules that matter are the same either way — an expired link is refused with 410 rather
     * than 404, because "this address is wrong" and "this link has expired" send the holder to
     * two different places, and the tenant is applied before anything else is read.
     */
    private function resolveSupplier(string $token): Supplier
    {
        $supplier = Supplier::where('portal_token', $token)->firstOrFail();

        abort_if(
            $supplier->portal_token_expires_at && $supplier->portal_token_expires_at->isPast(),
            410,
            __('supplier_management.portal.link_expired'),
        );

        if ($supplier->company_id) {
            app(TenantContext::class)->apply((int) $supplier->company_id);
        }

        return $supplier;
    }
}
