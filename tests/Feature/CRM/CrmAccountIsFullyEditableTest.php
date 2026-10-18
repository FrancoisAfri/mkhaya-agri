<?php

namespace Tests\Feature\CRM;

use App\Core\User\Models\User;
use App\Modules\CRM\Models\CrmAccount;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Everything the account record holds can be edited from the account screen.
 *
 * The dialog offered eleven of the fields on CrmAccount. Address, owner and notes were validated by
 * the controller and stored on the model, but had nowhere to be typed — so the only way to change
 * any of them was the database.
 *
 * Address is the one that hurt. CRM is the source of truth for a synced customer's address:
 * Accounting shows it read-only precisely because a sync would overwrite an edit made there. With
 * no field on this side either, an address was effectively frozen at whatever it was imported as —
 * which is how "P.O. Box 6377, Secunda, 2302, VAT NO: 4370228530" stayed on invoices.
 */
class CrmAccountIsFullyEditableTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        if (! DB::table('security_modules')->where('code_name', 'crm')->where('active', 1)->exists()) {
            $categoryId = DB::table('security_categories')->value('id')
                ?? DB::table('security_categories')->insertGetId(['name' => 'Test', 'created_at' => now(), 'updated_at' => now()]);

            DB::table('security_modules')->updateOrInsert(
                ['code_name' => 'crm'],
                ['uuid' => (string) Str::uuid(), 'name' => 'CRM', 'path' => 'crm', 'category_id' => $categoryId,
                    'active' => 1, 'created_at' => now(), 'updated_at' => now()],
            );
        }
        Cache::flush();
    }

    private function user(): User
    {
        return User::forceCreate([
            'uuid'              => (string) Str::uuid(),
            'email'             => 'acctedit+' . Str::random(8) . '@example.test',
            'password'          => bcrypt('secret'),
            'status'            => 1,
            'is_platform_admin' => true,
        ]);
    }

    private function account(array $attributes = []): CrmAccount
    {
        return CrmAccount::create(array_merge([
            'uuid' => (string) Str::uuid(),
            'name' => 'Osizweni ' . Str::random(6),
        ], $attributes));
    }

    public function test_the_address_can_be_corrected(): void
    {
        $account = $this->account(['address' => 'P.O. Box 6377, Secunda, 2302, VAT NO: 4370228530']);

        $this->actingAs($this->user())
            ->patch(route('crm.accounts.update', $account->uuid), [
                'name'    => $account->name,
                'address' => 'P.O. Box 6377, Secunda, 2302',
            ])
            ->assertRedirect();

        $this->assertSame('P.O. Box 6377, Secunda, 2302', $account->fresh()->address,
            'The address could not be changed from CRM, which owns it.');
    }

    public function test_the_owner_and_notes_can_be_set(): void
    {
        $account = $this->account();
        $owner = $this->user();

        $this->actingAs($this->user())
            ->patch(route('crm.accounts.update', $account->uuid), [
                'name'     => $account->name,
                'owner_id' => $owner->id,
                'notes'    => 'Prefers a call before month end.',
            ])
            ->assertRedirect();

        $account->refresh();
        $this->assertSame($owner->id, $account->owner_id);
        $this->assertSame('Prefers a call before month end.', $account->notes);
    }

    /**
     * The dialog cannot load a field the page was never sent, so the payload is asserted directly
     * — this is the half that was missing while the controller already accepted the writes.
     */
    public function test_the_page_sends_the_fields_the_dialog_needs_to_load(): void
    {
        $account = $this->account([
            'address' => '12 Leslie Rd, Fourways',
            'notes'   => 'Renewal due in March.',
        ]);

        $response = $this->actingAs($this->user())->get(route('crm.accounts'));
        $response->assertOk();

        $accounts = collect($response->viewData('page')['props']['accounts']);
        $row = $accounts->firstWhere('id', $account->id);

        $this->assertNotNull($row, 'The account was missing from the page payload.');
        foreach (['address', 'notes', 'owner_id'] as $field) {
            $this->assertArrayHasKey($field, $row, "The page never sends {$field}, so the dialog cannot load it.");
        }
        $this->assertSame('12 Leslie Rd, Fourways', $row['address']);
        $this->assertSame('Renewal due in March.', $row['notes']);
    }

    // ── The owner dropdown, on both editions ─────────────────────────────────

    /**
     * The account form now offers an owner, so the list behind it has to be this tenant's people.
     *
     * CrmVisibilityService::visibleOwnerIds() returns null for an administrator and for anybody
     * with no sales role — meaning "do not restrict by owner". The query read that as "do not
     * restrict at all" and, going through the builder against a `users` table with no row-level
     * security, listed every account on the installation.
     */
    public function test_the_owner_list_holds_only_this_tenants_people(): void
    {
        config(['edition.edition' => 'saas']);

        $companyId = DB::table('companies')->orderBy('id')->value('id');
        $otherCompany = DB::table('companies')->where('id', '!=', $companyId)->value('id')
            ?: DB::table('companies')->insertGetId([
                'uuid' => (string) Str::uuid(), 'slug' => 'other-' . Str::random(6),
                'name' => 'Another Tenant', 'status' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);

        $mine = $this->staff('Thandi', 'Mokoena', $companyId);
        $theirs = $this->staff('Someone', 'Else', $otherCompany);

        app(\App\Modules\Tenancy\Services\CurrentCompany::class)->set($companyId);

        try {
            $owners = app(\App\Modules\CRM\Services\CrmVisibilityService::class)
                ->assignableOwners($mine);
            $names = array_column($owners, 'name');
        } finally {
            app(\App\Modules\Tenancy\Services\CurrentCompany::class)->set(null);
        }

        $this->assertContains('Thandi Mokoena', $names);
        $this->assertNotContains('Someone Else', $names,
            "Another tenant's staff were offered as this account's owner.");
        unset($theirs);
    }

    public function test_the_owner_list_still_holds_people_on_a_standalone_install(): void
    {
        config(['edition.edition' => 'self_hosted']);

        if (DB::table('companies')->count() !== 1) {
            $this->markTestSkipped('Sole-company resolution needs exactly one company; the test database has more.');
        }

        $companyId = DB::table('companies')->value('id');
        // The first administrator's actual state on a fresh standalone install: no company stamped.
        $admin = $this->staff('Sipho', 'Dlamini', null);

        $names = array_column(
            app(\App\Modules\CRM\Services\CrmVisibilityService::class)->assignableOwners($admin),
            'name'
        );

        $this->assertContains('Sipho Dlamini', $names,
            'A standalone install offered nobody at all as an account owner.');
        unset($companyId);
    }

    private function staff(string $first, string $surname, ?int $companyId): User
    {
        $user = User::forceCreate([
            'uuid' => (string) Str::uuid(),
            'email' => 'owner+' . Str::random(8) . '@example.test',
            'password' => bcrypt('secret'),
            'status' => 1,
            'company_id' => $companyId,
            'is_platform_admin' => false,
        ]);

        DB::table('hr_people')->insert([
            'user_id' => $user->id, 'first_name' => $first, 'surname' => $surname,
            'status' => 1, 'company_id' => $companyId, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $user;
    }
}
