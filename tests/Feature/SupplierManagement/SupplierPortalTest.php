<?php

namespace Tests\Feature\SupplierManagement;

use App\Modules\SupplierManagement\Models\Supplier;
use App\Modules\SupplierManagement\Models\SupplierDocument;
use App\Modules\SupplierManagement\Models\SupplierDocumentType;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

/**
 * The supplier portal: the only unauthenticated surface in the module.
 *
 * There is no login here. The URL token *is* the credential, which means every guarantee the rest of
 * the module gets from `auth` has to be re-established from that one string — who the caller is,
 * which tenant's data they may touch, and which files they may read. Anything the token does not
 * pin down is reachable by anyone who can type a URL.
 */
class SupplierPortalTest extends SupplierTestCase
{
    public function test_the_token_is_generated_automatically_and_is_long_enough_to_resist_guessing(): void
    {
        $supplier = $this->makeSupplier();

        $this->assertNotNull($supplier->portal_token);
        $this->assertSame(48, strlen($supplier->portal_token));
    }

    public function test_a_supplier_can_open_its_own_portal_without_logging_in(): void
    {
        $supplier = $this->makeSupplier(['company_name' => 'Portal Co']);

        $this->flushAuthAndTenant();

        $this->get(route('supplier.portal.show', $supplier->portal_token))
            ->assertSuccessful()
            ->assertSee('Portal Co', escape: false);
    }

    public function test_an_unknown_or_tampered_token_is_rejected(): void
    {
        $supplier = $this->makeSupplier();

        $this->flushAuthAndTenant();

        $this->get(route('supplier.portal.show', str_repeat('a', 48)))->assertNotFound();
        $this->get(route('supplier.portal.show', substr($supplier->portal_token, 0, -1).'X'))->assertNotFound();
    }

    /**
     * Opening the portal binds the request to the supplier's own company.
     *
     * Without this the request has no tenant at all, so anything the portal writes lands with a null
     * company_id — invisible to the tenant it belongs to — and any list it reads is unfiltered.
     */
    public function test_the_portal_binds_the_request_to_the_suppliers_tenant(): void
    {
        $supplier = $this->makeSupplier();

        $this->flushAuthAndTenant();
        $this->assertNull(app(CurrentCompany::class)->id());

        $this->get(route('supplier.portal.show', $supplier->portal_token))->assertSuccessful();

        $this->assertSame($this->companyId, app(CurrentCompany::class)->id());
    }

    public function test_a_document_uploaded_through_the_portal_is_stamped_with_the_suppliers_tenant(): void
    {
        $supplier = $this->makeSupplier();
        $type = SupplierDocumentType::create(['document_type_name' => 'Tax clearance', 'active' => true]);

        $this->flushAuthAndTenant();

        $this->post(route('supplier.portal.documents.store', $supplier->portal_token), [
            'document_type_id' => $type->id,
            'file'             => UploadedFile::fake()->create('tax-clearance.pdf', 64, 'application/pdf'),
        ])->assertRedirect();

        $document = SupplierDocument::withoutGlobalScopes()->where('supplier_id', $supplier->id)->first();

        $this->assertNotNull($document, 'The upload did not produce a document row.');
        $this->assertSame($this->companyId, (int) $document->company_id, 'A portal upload landed outside the tenant and would be invisible to them.');
    }

    /**
     * A token holder must not be able to read another supplier's documents.
     *
     * The document id is in the URL and the token is not derived from it, so nothing but an explicit
     * ownership check stands between one supplier and every other supplier's uploads.
     */
    public function test_one_supplier_cannot_download_another_suppliers_document(): void
    {
        $mine = $this->makeSupplier(['company_name' => 'Mine']);
        $theirs = $this->makeSupplier(['company_name' => 'Theirs']);
        $type = SupplierDocumentType::create(['document_type_name' => 'Bank letter', 'active' => true]);

        $this->flushAuthAndTenant();

        $this->post(route('supplier.portal.documents.store', $theirs->portal_token), [
            'document_type_id' => $type->id,
            'file'             => UploadedFile::fake()->create('their-bank-letter.pdf', 32, 'application/pdf'),
        ])->assertRedirect();

        $theirDocument = SupplierDocument::withoutGlobalScopes()->where('supplier_id', $theirs->id)->firstOrFail();

        $this->get(route('supplier.portal.documents.download', [$mine->portal_token, $theirDocument->id]))
            ->assertForbidden();
    }

    public function test_the_portal_lets_a_supplier_update_only_its_own_record(): void
    {
        $supplier = $this->makeSupplier(['company_name' => 'Editable Co']);

        $this->flushAuthAndTenant();

        $this->patch(route('supplier.portal.update', $supplier->portal_token), [
            'phone_number' => '+27 11 555 0000',
        ])->assertRedirect();

        $this->assertSame('+27 11 555 0000', $supplier->fresh()->phone_number);
    }

    /**
     * The portal credential can be revoked, and the old link dies immediately.
     *
     * It used to be minted once and live as long as the supplier row, with no expiry and no way to
     * replace it — so a contact who left the supplier kept a working link to that company's banking
     * details for as long as the record existed.
     */
    public function test_the_portal_link_can_be_rotated_and_the_old_one_stops_working(): void
    {
        Notification::fake();

        $supplier = $this->makeSupplier();
        $original = $supplier->portal_token;

        $this->post(route('suppliers.portal-token.rotate', $supplier))->assertRedirect();

        $supplier->refresh();

        $this->assertNotSame($original, $supplier->portal_token);
        $this->assertSame(48, strlen($supplier->portal_token));
        $this->assertNotNull($supplier->portal_token_expires_at, 'A reissued link must carry an expiry.');
        $this->assertNotNull($supplier->portal_token_rotated_at);

        $this->flushAuthAndTenant();

        $this->get(route('supplier.portal.show', $original))->assertNotFound();
        $this->get(route('supplier.portal.show', $supplier->portal_token))->assertSuccessful();
    }

    /**
     * An expired link is refused, and says so rather than 404ing.
     *
     * The two mean different things to whoever is holding it: "this address is wrong" sends them
     * looking for a typo, "this link has expired" sends them to ask for a new one.
     */
    public function test_an_expired_portal_link_is_refused_with_gone_rather_than_not_found(): void
    {
        $supplier = $this->makeSupplier();

        $supplier->forceFill(['portal_token_expires_at' => Carbon::now()->subDay()])->save();

        $this->flushAuthAndTenant();

        $this->get(route('supplier.portal.show', $supplier->portal_token))->assertStatus(410);
    }

    /**
     * A token issued before expiry existed keeps working.
     *
     * A deploy that locked out every supplier at once would be a worse failure than the one being
     * fixed, so a null expiry means "no horizon set" rather than "expired".
     */
    public function test_a_link_with_no_expiry_still_works(): void
    {
        $supplier = $this->makeSupplier();

        $this->assertNull($supplier->portal_token_expires_at);

        $this->flushAuthAndTenant();

        $this->get(route('supplier.portal.show', $supplier->portal_token))->assertSuccessful();
    }

    /** Drop the authenticated session and tenant so the portal is exercised as a stranger would. */
    private function flushAuthAndTenant(): void
    {
        auth()->logout();
        app(CurrentCompany::class)->set(null);
    }
}
