<?php

namespace App\Modules\SupplierManagement\Controllers;

use App\Core\User\Trait\FileStorage;
use App\Http\Controllers\Controller;
use App\Modules\SupplierManagement\Models\Supplier;
use App\Modules\SupplierManagement\Models\SupplierDocument;
use App\Modules\SupplierManagement\Models\SupplierDocumentType;
use App\Modules\SupplierManagement\Models\SupplierRiskFlag;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Public (unauthenticated) Supplier self-service portal.
 *
 * The portal_token IS the credential — there is no login and no $this->authorize().
 * Every action resolves the supplier from the token, so a supplier can only ever
 * see or touch their own record.
 */
class SupplierPortalController extends Controller
{
    use FileStorage;

    /** Resolve the supplier from its portal token or 404, and bind tenant context. */
    private function resolveSupplier(string $token): Supplier
    {
        // The portal is unauthenticated, so no company is set — the (inactive) company
        // scope doesn't block this token lookup. We then bind the request to the
        // supplier's OWN company so every subsequent read/write is correctly
        // tenant-scoped and stamped. Without this, portal-created documents land with
        // company_id = NULL and become invisible to the tenant under RLS, and the
        // document-type list would leak every tenant's types.
        $supplier = Supplier::where('portal_token', $token)->firstOrFail();

        // An expired link is refused rather than 404'd, because the two mean different things to the
        // person holding it: "this address is wrong" sends them looking for a typo, "this link has
        // expired" sends them to ask for a new one. Tokens issued before expiry existed have a null
        // date and keep working — a deploy must not lock out every supplier at once.
        abort_if(
            $supplier->portal_token_expires_at && $supplier->portal_token_expires_at->isPast(),
            410,
            __('supplier_management.portal.link_expired'),
        );

        if ($supplier->company_id) {
            app(\App\Modules\Tenancy\Services\TenantContext::class)->apply((int) $supplier->company_id);
        }

        return $supplier;
    }

    public function show(string $token): Response
    {
        $supplier = $this->resolveSupplier($token);
        $supplier->load([
            'contacts',
            'directors',
            'documents.documentType',
        ]);

        return Inertia::render('Modules/SupplierManagement/Portal/Index', [
            'token'    => $token,
            'supplier' => [
                'company_name'                 => $supplier->company_name,
                'trading_name'                 => $supplier->trading_name,
                'supplier_number'              => $supplier->supplier_number,
                'company_registration_number'  => $supplier->company_registration_number,
                'vat_registration_number'      => $supplier->vat_registration_number,
                'business_entity_type'         => $supplier->business_entity_type,
                'phone_number'                 => $supplier->phone_number,
                'email'                        => $supplier->email,
                'website_url'                  => $supplier->website_url,
                'physical_address'             => $supplier->physical_address,
                'postal_address'               => $supplier->postal_address,
                'bank_name'                    => $supplier->bank_name,
                'account_holder_name'          => $supplier->account_holder_name,
                'account_type'                 => $supplier->account_type,
                'bank_account_number'          => $supplier->bank_account_number,
                'branch_code'                  => $supplier->branch_code,
            ],
            'contacts' => $supplier->contacts->map(fn ($c) => [
                'name'  => trim("{$c->first_name} {$c->surname}"),
                'email' => $c->email,
                'phone' => $c->tel,
            ])->values(),
            'directors' => $supplier->directors->map(fn ($d) => [
                'name'  => trim("{$d->first_name} {$d->surname}"),
                'email' => $d->email,
                'phone' => $d->tel,
            ])->values(),
            'documents' => $supplier->documents->map(fn ($doc) => [
                'id'        => $doc->id,
                'type'      => $doc->documentType?->document_type_name,
                'file_name' => $doc->file_name,
                'expiry'    => $doc->expiry_date?->toDateString(),
            ])->values(),
            /*
             * Whether this supplier also works on site, and so has a SHEQ compliance page
             * (CON-18).
             *
             * Asked here rather than linked unconditionally: plenty of suppliers deliver
             * stationery and never set foot on a site, and a safety link they cannot use is an
             * invitation to telephone somebody about what they have done wrong.
             *
             * A raw query rather than the SHEQ service, because this module must not fail to
             * render when SHEQ is not installed — `hasTable` is the whole dependency.
             */
            'safetyPortal' => \Illuminate\Support\Facades\Schema::hasTable('sheq_contractors')
                && \Illuminate\Support\Facades\DB::table('sheq_contractors')
                    ->where('company_id', $supplier->company_id)
                    ->where('supplier_id', $supplier->id)
                    ->whereNull('deleted_at')->exists(),
            'documentTypes' => SupplierDocumentType::where('active', true)
                ->orderBy('document_type_name')
                ->get(['id', 'document_type_name'])
                ->map(fn ($t) => ['id' => $t->id, 'name' => $t->document_type_name])
                ->values(),
        ]);
    }

    /** Update editable contact / banking profile fields only. */
    public function update(Request $request, string $token): RedirectResponse
    {
        $supplier = $this->resolveSupplier($token);

        $data = $request->validate([
            'phone_number'        => 'nullable|string|max:40',
            'email'               => 'nullable|email|max:255',
            'website_url'         => 'nullable|string|max:255',
            'physical_address'    => 'nullable|string|max:1000',
            'postal_address'      => 'nullable|string|max:1000',
            'bank_name'           => 'nullable|string|max:150',
            'account_holder_name' => 'nullable|string|max:150',
            'account_type'        => 'nullable|string|max:100',
            'bank_account_number' => 'nullable|string|max:50',
            'branch_code'         => 'nullable|string|max:20',
        ]);

        // Banking changes via the public portal are a classic vendor-fraud vector —
        // detect them, hold the supplier for re-verification, and raise a risk flag
        // rather than silently trusting the new account.
        $bankFields = ['bank_name', 'account_holder_name', 'account_type', 'bank_account_number', 'branch_code'];
        $bankChanged = [];
        foreach ($bankFields as $f) {
            if (array_key_exists($f, $data) && (string) ($data[$f] ?? '') !== (string) ($supplier->{$f} ?? '')) {
                $bankChanged[] = $f;
            }
        }

        $supplier->update($data);

        if ($bankChanged) {
            SupplierRiskFlag::create([
                'supplier_id' => $supplier->id,
                'type'        => 'bank_details_changed',
                'severity'    => 'high',
                'status'      => 'open',
                'message'     => 'Banking details changed via the supplier self-service portal — verify before any payment.',
                'context'     => ['fields' => $bankChanged], // field names only — never store the account numbers here
            ]);
            $supplier->update(['is_active' => 0]); // 0 = Pending (hold) — blocks new POs until re-verified

            return back()->with('success', 'Your details were submitted. Banking changes require verification by our team before they take effect.');
        }

        return back()->with('success', 'Your details have been updated.');
    }

    /** Upload a document — stored IN the database via the FileStorage trait. */
    public function uploadDocument(Request $request, string $token): RedirectResponse
    {
        $supplier = $this->resolveSupplier($token);

        $data = $request->validate([
            'document_type_id' => 'nullable|integer|exists:supplier_document_types,id',
            'expiry_date'      => 'nullable|date',
            'file'             => 'required|file|max:10240',
        ]);

        $blob = $this->saveFileToDatabase($request->file('file'));

        $document = $supplier->documents()->make([
            'document_type_id' => $data['document_type_id'] ?? null,
            'expiry_date'      => $data['expiry_date'] ?? null,
            'file_name'        => $blob['original_name'],
            'file_mime'        => $blob['mime'],
            'file_size'        => $blob['size'],
        ]);

        // Bytes go to the shared disk; the row keeps the metadata and a path.
        $document->writeBytes(base64_decode($blob['data']), pathinfo((string) $blob['original_name'], PATHINFO_EXTENSION));
        $document->save();

        return back()->with('success', 'Document uploaded successfully.');
    }

    /** Stream a document — only if it belongs to the token's supplier. */
    public function downloadDocument(string $token, SupplierDocument $document): \Illuminate\Http\Response
    {
        $supplier = $this->resolveSupplier($token);

        abort_unless($document->supplier_id === $supplier->id, 403);
        abort_unless($document->hasDocument(), 404, 'No file stored for this document.');

        return $document->documentResponse();
    }
}
