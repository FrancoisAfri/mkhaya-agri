<?php

namespace Tests\Feature\Finance;

use App\Modules\Finance\Accounting\Models\FinParty;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Two fixes to the customer record, both of which existed because a field had nowhere to go.
 *
 * The sales rep was free text, so the same person appeared as "Francois Charles Keou ngamen" in one
 * record and "F. Keou" in the next, and no report could group a book of customers by who looks
 * after them. And the VAT number lived inside the address, because until the invoice grew a VAT
 * line that was the only way to get it printed.
 */
class SalesRepAndAddressCleanupTest extends TestCase
{
    use DatabaseTransactions;

    private function party(array $attributes = []): FinParty
    {
        /*
         * Stamped explicitly. BelongsToCompany fills company_id from the signed-in user, and there
         * is none here — so an unstamped fixture belongs to no tenant, which is exactly the row a
         * per-tenant sweep is supposed to skip. Testing against one would prove nothing.
         */
        return FinParty::create(array_merge([
            'kind'       => 'customer',
            'code'       => 'C' . Str::random(6),
            'name'       => 'Osizweni ' . Str::random(6),
            'company_id' => DB::table('companies')->orderBy('id')->value('id'),
        ], $attributes));
    }

    /**
     * A user with an employment record, which is what the picker requires: `users` also holds
     * service accounts and portal logins for clients, and offering those as a sales rep would put
     * a customer's own login in the field naming who looks after them.
     */
    private function employedUser(string $first, string $surname, bool $admin = false): \App\Core\User\Models\User
    {
        $companyId = DB::table('companies')->orderBy('id')->value('id');

        $user = \App\Core\User\Models\User::forceCreate([
            'uuid'       => (string) Str::uuid(),
            'email'      => 'rep+' . Str::random(8) . '@example.test',
            'password'   => bcrypt('secret'),
            'status'     => 1,
            'company_id' => $companyId,
            'is_platform_admin' => $admin,
        ]);

        DB::table('hr_people')->insert([
            'user_id'    => $user->id,
            'first_name' => $first,
            'surname'    => $surname,
            'status'     => 1,
            'company_id' => $companyId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }

    // ── The sales rep is chosen from staff ───────────────────────────────────

    public function test_the_customers_page_offers_the_staff_a_rep_could_be(): void
    {
        $rep = $this->employedUser('Thandi', 'Mokoena');

        // Viewed as a platform admin: an ordinary staff account has no module access rows in the
        // test database and is turned away at the gate before the payload is ever built.
        $response = $this->actingAs($this->employedUser('Sipho', 'Dlamini', admin: true))
            ->get(route('finance.accounting.customers'));
        $response->assertOk();

        $props = $response->viewData('page')['props'];

        $this->assertArrayHasKey('people', $props,
            'The page never sends the staff list, so the rep field has nothing to offer.');
        $names = array_column($props['people'], 'name');
        $this->assertContains('Thandi Mokoena', $names,
            'An employed, active member of staff was not offered as a possible sales rep.');
    }

    /**
     * Still a combobox, not a select. A rep who has left the company, or an outside agent who was
     * never on staff, must not be wiped off the record by a list that cannot contain them.
     */
    public function test_a_rep_who_is_not_on_the_staff_list_is_still_accepted(): void
    {
        $party = $this->party(['sales_rep' => 'An Outside Agent']);

        $this->assertSame('An Outside Agent', $party->fresh()->sales_rep);
    }

    // ── VAT lifted out of the stored address ─────────────────────────────────

    public function test_the_sweep_reports_without_changing_anything_by_default(): void
    {
        $party = $this->party([
            'postal_address' => 'P.O. Box 6377, Secunda, 2302, VAT NO: 4370228530',
            'vat_number'     => null,
        ]);

        $this->artisan('finance:lift-vat-from-addresses')->assertSuccessful();

        $party->refresh();
        $this->assertStringContainsString('VAT NO', (string) $party->postal_address,
            'A dry run rewrote the address.');
        $this->assertNull($party->vat_number);
    }

    public function test_the_sweep_moves_the_number_into_its_own_field(): void
    {
        $party = $this->party([
            'postal_address' => 'P.O. Box 6377, Secunda, 2302, VAT NO: 4370228530',
            'vat_number'     => null,
        ]);

        $this->artisan('finance:lift-vat-from-addresses --write')->assertSuccessful();

        $party->refresh();
        $this->assertSame('P.O. Box 6377, Secunda, 2302', $party->postal_address);
        $this->assertSame('4370228530', $party->vat_number,
            'The number was stripped from the address without being kept anywhere.');
    }

    public function test_the_sweep_never_overwrites_a_vat_number_somebody_set(): void
    {
        $party = $this->party([
            'postal_address' => 'P.O. Box 6377, VAT NO: 1111111111',
            'vat_number'     => '4370228530',
        ]);

        $this->artisan('finance:lift-vat-from-addresses --write')->assertSuccessful();

        $party->refresh();
        $this->assertSame('4370228530', $party->vat_number,
            'A stale number from the address replaced the one actually set on the record.');
        $this->assertSame('P.O. Box 6377', $party->postal_address);
    }

    public function test_an_ordinary_address_is_left_alone(): void
    {
        $party = $this->party(['address' => 'Design Quarter, Leslie Rd, Fourways, 2191']);

        $this->artisan('finance:lift-vat-from-addresses --write')->assertSuccessful();

        $this->assertSame('Design Quarter, Leslie Rd, Fourways, 2191', $party->fresh()->address);
    }

    /**
     * The first run of this command reported the same party once per tenant, because these queries
     * go through the query builder — where CompanyScope is inert in console and the RLS session
     * variable was not filtering either. A sweep that rewrites customer records is the last place
     * to read one company's rows while acting as another.
     */
    public function test_the_sweep_only_touches_the_tenant_it_is_acting_for(): void
    {
        $party = $this->party([
            'postal_address' => 'P.O. Box 6377, VAT NO: 4370228530',
            'vat_number'     => null,
        ]);

        $otherCompany = DB::table('companies')->where('id', '!=', $party->company_id)->value('id')
            ?: DB::table('companies')->insertGetId([
                'uuid'       => (string) Str::uuid(),
                'slug'       => 'other-' . Str::random(6),
                'name'       => 'Another Tenant',
                'status'     => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        $this->artisan("finance:lift-vat-from-addresses --write --company={$otherCompany}")
            ->assertSuccessful();

        $this->assertStringContainsString('VAT NO', (string) $party->fresh()->postal_address,
            "A sweep scoped to company {$otherCompany} rewrote another company's record.");
    }
}
