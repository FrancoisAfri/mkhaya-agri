<?php

namespace Tests\Feature\Finance;

use App\Modules\Finance\Accounting\Models\FinParty;
use App\Modules\Finance\Accounting\Services\PartySyncService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A customer that came from CRM can still be completed here.
 *
 * Editing was refused outright for any synced record: "this record is managed in crm and can't be
 * edited here." Every customer on the page comes from CRM, so in practice none of them could be
 * edited at all — and the fields that matter to Accounting are exactly the ones CRM has no idea
 * about. A VAT number, credit terms, a credit limit, a sales rep, a postal address: none exist in
 * CRM to sync down, so refusing them meant they could never be set on any customer.
 *
 * The invoice prints all of them, which is how it surfaced.
 *
 * The rule now is narrower and matches what the sync actually does: the columns a sync rewrites are
 * dropped, and everything else saves. Dropped rather than refused, because they are overwritten on
 * the next run either way — and a change that saves and then quietly reverts hours later is worse
 * than one that was never offered.
 */
class CustomerDetailsAreEditableTest extends TestCase
{
    use DatabaseTransactions;

    private function party(array $attributes = []): FinParty
    {
        return FinParty::create(array_merge([
            'kind'              => 'customer',
            'code'              => 'C' . Str::random(6),
            'name'              => 'Osizweni Trust',
            'email'             => 'ops@osizweni.test',
            'credit_terms_days' => 30,
            'source'            => 'crm',
            'crm_account_id'    => 4242,
        ], $attributes));
    }

    /** The payload the edit dialog submits. */
    private function payload(FinParty $party, array $overrides = []): array
    {
        return array_merge([
            'kind'              => 'customer',
            'name'              => $party->name,
            'credit_terms_days' => $party->credit_terms_days,
        ], $overrides);
    }

    // ── What Accounting owns ────────────────────────────────────────────────

    public function test_a_crm_customer_can_be_given_the_details_an_invoice_needs(): void
    {
        $party = $this->party();

        $this->actingAsFinanceUser()
            ->patch(route('finance.accounting.parties.update', $party->id), $this->payload($party, [
                'vat_number'          => '4370228530',
                'registration_number' => '2004/012345/08',
                'postal_address'      => 'P.O. Box 6377',
                'sales_rep'           => 'Francois Charles Keou',
                'credit_terms_days'   => 45,
                'credit_limit'        => 250000,
            ]))
            ->assertSessionHasNoErrors();

        $party->refresh();

        $this->assertSame('4370228530', $party->vat_number);
        $this->assertSame('2004/012345/08', $party->registration_number);
        $this->assertSame('P.O. Box 6377', $party->postal_address);
        $this->assertSame('Francois Charles Keou', $party->sales_rep);
        $this->assertSame(45, $party->credit_terms_days);
    }

    // ── What the source owns ────────────────────────────────────────────────

    public function test_a_field_the_source_rewrites_is_not_saved_here(): void
    {
        /*
         * Silently, and on purpose. CRM's next sync puts the old value back, so accepting the edit
         * would show a change that disappears with nothing to explain it.
         */
        $party = $this->party();

        $this->actingAsFinanceUser()
            ->patch(route('finance.accounting.parties.update', $party->id), $this->payload($party, [
                'name'  => 'Renamed In Accounting',
                'email' => 'somewhere-else@example.test',
            ]))
            ->assertSessionHasNoErrors();

        $party->refresh();

        $this->assertSame('Osizweni Trust', $party->name, 'CRM owns the name.');
        $this->assertSame('ops@osizweni.test', $party->email, 'CRM owns the email.');
    }

    public function test_the_two_syncs_own_different_columns(): void
    {
        /*
         * A supplier from Supplier Management brings its own VAT number and banking down with it,
         * so those are locked there and editable on a CRM customer. Getting this backwards would
         * either lose a supplier's banking on every sync or leave a customer unable to be invoiced.
         */
        $crm = PartySyncService::sourceOwnedColumns('crm');
        $supplier = PartySyncService::sourceOwnedColumns('supplier_mgmt');

        $this->assertNotContains('vat_number', $crm);
        $this->assertContains('vat_number', $supplier);

        $this->assertNotContains('credit_terms_days', $crm);
        $this->assertContains('credit_terms_days', $supplier);
    }

    public function test_a_manual_customer_is_editable_throughout(): void
    {
        // Nothing syncs it, so nothing can overwrite it.
        $party = $this->party(['source' => 'manual', 'crm_account_id' => null]);

        $this->actingAsFinanceUser()
            ->patch(route('finance.accounting.parties.update', $party->id), $this->payload($party, [
                'name' => 'Renamed By Hand',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame('Renamed By Hand', $party->fresh()->name);
    }

    public function test_the_page_says_which_fields_the_source_owns(): void
    {
        // The form greys those boxes out; it needs to be told which, per record, because the answer
        // differs between a CRM customer and a Supplier Management supplier.
        $party = $this->party();

        $props = $this->actingAsFinanceUser()
            ->get(route('finance.accounting.customers'))
            ->assertOk()
            ->viewData('page')['props'];

        $row = collect($props['parties'])->firstWhere('id', $party->id);

        $this->assertContains('name', $row['locked']);
        $this->assertNotContains('vat_number', $row['locked']);
    }

    private function actingAsFinanceUser(): self
    {
        $user = \App\Core\User\Models\User::first() ?? \App\Core\User\Models\User::factory()->create();

        // The routes sit behind the CRUD permission middleware; this test is about the ownership
        // rule inside the controller, not about who may reach it.
        return $this->actingAs($user)->withoutMiddleware([
            \App\Http\Middleware\EnforceCrudPermissions::class,
            \App\Http\Middleware\EnsureModuleIsActive::class,
            \App\Http\Middleware\EnsureModuleTier::class,
        ]);
    }
}
