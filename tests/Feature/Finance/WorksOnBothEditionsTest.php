<?php

namespace Tests\Feature\Finance;

use App\Modules\Finance\Accounting\Models\FinParty;
use App\Modules\Finance\Accounting\Models\FinSetting;
use App\Modules\Finance\Accounting\Services\FinanceDocumentService;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The same behaviour on a multi-tenant install and on a single-customer one.
 *
 * Two of the three tables this work touches sit outside the row-level-security retrofit:
 * `fin_settings` and `fin_parties` are both nullable, unforced and carry no session-variable
 * default, so nothing at the database level catches a query that forgets to scope itself. That
 * makes the edition question a real one rather than a formality — on SaaS the risk is one tenant
 * reading another's rows, and on self-hosted it is a scoped query matching nothing at all and the
 * feature silently doing nothing.
 */
class WorksOnBothEditionsTest extends TestCase
{
    use DatabaseTransactions;

    private function company(string $name): int
    {
        return DB::table('companies')->insertGetId([
            'uuid' => (string) Str::uuid(), 'slug' => Str::slug($name) . '-' . Str::random(5),
            'name' => $name, 'status' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function asCompany(?int $companyId, callable $work): mixed
    {
        app(CurrentCompany::class)->set($companyId);

        try {
            return $work();
        } finally {
            app(CurrentCompany::class)->set(null);
        }
    }

    private function party(?int $companyId, array $attributes = []): FinParty
    {
        return FinParty::create(array_merge([
            'kind' => 'customer', 'code' => 'C' . Str::random(6), 'name' => 'Party ' . Str::random(5),
            'company_id' => $companyId,
        ], $attributes));
    }

    // ── Settings, and the vendor number that now lives in them ───────────────

    public function test_on_saas_each_tenant_keeps_its_own_vendor_number(): void
    {
        config(['edition.edition' => 'saas']);
        $a = $this->company('Tenant A');
        $b = $this->company('Tenant B');

        $this->asCompany($a, function () {
            $s = FinSetting::current();
            $s->vendor_number = 'VENDOR-A';
            $s->company_name = 'Tenant A Ltd';
            $s->save();
        });

        $this->asCompany($b, function () {
            $s = FinSetting::current();
            $s->vendor_number = 'VENDOR-B';
            $s->company_name = 'Tenant B Ltd';
            $s->save();
        });

        $this->assertSame('VENDOR-A', $this->asCompany($a, fn () => FinSetting::current()->vendor_number),
            "Tenant B's save overwrote tenant A's vendor number.");
        $this->assertSame('VENDOR-B', $this->asCompany($b, fn () => FinSetting::current()->vendor_number));
    }

    public function test_on_saas_the_invoice_letterhead_is_the_tenants_own(): void
    {
        config(['edition.edition' => 'saas']);
        $a = $this->company('Tenant A');
        $b = $this->company('Tenant B');

        foreach ([[$a, 'A'], [$b, 'B']] as [$companyId, $letter]) {
            $this->asCompany($companyId, function () use ($letter) {
                $s = FinSetting::current();
                $s->company_name = "Tenant {$letter} Ltd";
                // The VAT number stands in for "a field of this tenant's own". It used to be the
                // vendor number, which has since moved onto the customer where it belongs.
                $s->vat_number = "VAT-{$letter}";
                $s->save();
            });
        }

        $companyA = $this->asCompany($a, fn () => app(FinanceDocumentService::class)->company());

        $this->assertSame('Tenant A Ltd', $companyA['name']);
        $this->assertSame('VAT-A', $companyA['vat'],
            "Another tenant's VAT number was about to be printed on this tenant's invoice.");
    }

    /**
     * Every install that exists today has its settings in the shared row, so a scoped lookup with
     * no fallback would open the settings screen blank and print an unbranded invoice.
     */
    public function test_settings_saved_before_any_of_this_are_still_read(): void
    {
        config(['edition.edition' => 'saas']);
        $companyId = $this->company('Existing Install');

        DB::table('fin_settings')->insert([
            'company_id' => null, 'company_name' => 'Mkhaya MK Pty (Ltd)',
            'vendor_number' => 'LEGACY-1', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $settings = $this->asCompany($companyId, fn () => FinSetting::current());

        $this->assertSame('Mkhaya MK Pty (Ltd)', $settings->company_name,
            'An existing install would have opened its settings screen blank.');
        $this->assertSame('LEGACY-1', $settings->vendor_number);
    }

    public function test_the_first_save_copies_the_shared_row_rather_than_stealing_it(): void
    {
        config(['edition.edition' => 'saas']);
        $a = $this->company('Tenant A');
        $b = $this->company('Tenant B');

        DB::table('fin_settings')->insert([
            'company_id' => null, 'company_name' => 'Shared', 'vendor_number' => 'LEGACY-1',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->asCompany($a, function () {
            $s = FinSetting::current();
            $s->vendor_number = 'VENDOR-A';
            $s->save();
        });

        // A took a copy; B still reads what was there before.
        $this->assertSame('VENDOR-A', $this->asCompany($a, fn () => FinSetting::current()->vendor_number));
        $this->assertSame('LEGACY-1', $this->asCompany($b, fn () => FinSetting::current()->vendor_number),
            "One tenant's first save took the shared row away from everybody else.");
        $this->assertDatabaseHas('fin_settings', ['company_id' => null, 'vendor_number' => 'LEGACY-1']);
    }

    public function test_on_self_hosted_settings_resolve_without_anyone_entering_a_tenant(): void
    {
        config(['edition.edition' => 'self_hosted']);

        // A self-hosted administrator has no CurrentCompany and often no users.company_id either.
        if (DB::table('companies')->count() !== 1) {
            $this->markTestSkipped('Sole-company resolution needs exactly one company; the test database has more.');
        }

        $settings = FinSetting::current();
        $settings->vendor_number = 'STANDALONE-1';
        $settings->save();

        $this->assertSame('STANDALONE-1', FinSetting::current()->vendor_number,
            'A standalone install could not save and read back its own vendor number.');
    }

    // ── The address sweep ────────────────────────────────────────────────────

    /**
     * The case that matters on a standalone install. `fin_parties.company_id` is nullable with no
     * default and no row-level security, and BelongsToCompany stamps from the signed-in user — so
     * anything created by an administrator, a console pass or an importer belongs to no company. A
     * strictly per-tenant sweep would have found nothing to do there and reported success.
     */
    public function test_the_sweep_reaches_records_that_belong_to_no_company(): void
    {
        $party = $this->party(null, [
            'postal_address' => 'P.O. Box 6377, Secunda, 2302, VAT NO: 4370228530',
            'vat_number'     => null,
        ]);
        $this->assertNull($party->company_id, 'The fixture was stamped after all; the test proves nothing.');

        $this->artisan('finance:lift-vat-from-addresses --write')->assertSuccessful();

        $party->refresh();
        $this->assertSame('P.O. Box 6377, Secunda, 2302', $party->postal_address,
            'A record belonging to no company was skipped, which on a standalone install is most of them.');
        $this->assertSame('4370228530', $party->vat_number);
    }

    public function test_a_tenants_records_and_the_tenantless_ones_are_both_swept(): void
    {
        $companyId = DB::table('companies')->orderBy('id')->value('id');

        $owned = $this->party($companyId, ['postal_address' => 'Owned, VAT NO: 4370228530', 'vat_number' => null]);
        $orphan = $this->party(null, ['postal_address' => 'Orphan, VAT NO: 4370228531', 'vat_number' => null]);

        $this->artisan('finance:lift-vat-from-addresses --write')->assertSuccessful();

        $this->assertSame('4370228530', $owned->fresh()->vat_number);
        $this->assertSame('4370228531', $orphan->fresh()->vat_number);
    }

    /**
     * Scoping a sweep to one company must still mean one company, even now that a null scope has a
     * meaning of its own.
     */
    public function test_a_company_scoped_sweep_leaves_the_tenantless_records_alone(): void
    {
        $companyId = DB::table('companies')->orderBy('id')->value('id');
        $orphan = $this->party(null, ['postal_address' => 'Orphan, VAT NO: 4370228530', 'vat_number' => null]);

        $this->artisan("finance:lift-vat-from-addresses --write --company={$companyId}")->assertSuccessful();

        $this->assertStringContainsString('VAT NO', (string) $orphan->fresh()->postal_address,
            'A sweep scoped to one company rewrote a record outside it.');
    }

    // ── The sales rep picker ─────────────────────────────────────────────────

    /**
     * `PicksTenantSubjects` names the company explicitly rather than leaning on the User scope,
     * because that scope steps aside for a platform admin. The two editions reach the answer by
     * different routes — SaaS through CurrentCompany, self-hosted through the sole-company
     * fallback — and both have to arrive somewhere, or the rep field offers an empty list.
     */
    public function test_the_rep_picker_offers_this_tenants_staff_on_saas(): void
    {
        config(['edition.edition' => 'saas']);

        $companyId = DB::table('companies')->orderBy('id')->value('id');
        $mine = $this->employedUser('Thandi', 'Mokoena', $companyId);
        $theirs = $this->employedUser('Someone', 'Else', $this->company('Another Tenant'));

        $names = $this->asCompany($companyId, fn () => array_column($this->people($mine), 'name'));

        $this->assertContains('Thandi Mokoena', $names);
        $this->assertNotContains('Someone Else', $names,
            "Another tenant's staff were offered as this tenant's sales rep.");
        unset($theirs);
    }

    public function test_the_rep_picker_still_offers_staff_on_self_hosted(): void
    {
        config(['edition.edition' => 'self_hosted']);

        if (DB::table('companies')->count() !== 1) {
            $this->markTestSkipped('Sole-company resolution needs exactly one company; the test database has more.');
        }

        $companyId = DB::table('companies')->value('id');
        $admin = $this->employedUser('Sipho', 'Dlamini', $companyId, platformAdmin: true);

        // No CurrentCompany and no users.company_id: the self-hosted administrator's actual state,
        // and the one that used to leave every picker in the module empty.
        $admin->company_id = null;
        $admin->save();

        $names = array_column($this->people($admin), 'name');

        $this->assertContains('Sipho Dlamini', $names,
            'A standalone install offered no staff at all for the sales rep field.');
    }

    /** The staff list the customers page hands to the page, read back from the Inertia payload. */
    private function people(\App\Core\User\Models\User $actor): array
    {
        $response = $this->actingAs($actor)->get(route('finance.accounting.customers'));
        $response->assertOk();

        return $response->viewData('page')['props']['people'];
    }

    private function employedUser(string $first, string $surname, ?int $companyId, bool $platformAdmin = true): \App\Core\User\Models\User
    {
        $user = \App\Core\User\Models\User::forceCreate([
            'uuid' => (string) Str::uuid(),
            'email' => 'staff+' . Str::random(8) . '@example.test',
            'password' => bcrypt('secret'),
            'status' => 1,
            'company_id' => $companyId,
            // Platform admin so the module gate lets the request through to the payload; the
            // picker's own scoping is what is under test, not the gate.
            'is_platform_admin' => $platformAdmin,
        ]);

        DB::table('hr_people')->insert([
            'user_id' => $user->id, 'first_name' => $first, 'surname' => $surname,
            'status' => 1, 'company_id' => $companyId, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $user;
    }
}
