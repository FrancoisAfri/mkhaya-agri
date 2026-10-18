<?php

namespace Tests\Feature\Tenancy;

use App\Core\User\Models\User;
use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\Models\Reseller;
use App\Modules\Tenancy\Models\ResellerUser;
use App\Modules\Tenancy\Models\SubscriptionInvoice;
use App\Modules\Tenancy\Services\ArchivedCompanyPurge;
use App\Modules\Tenancy\Services\CompanyProvisioner;
use App\Modules\Tenancy\Services\CurrentCompany;
use App\Modules\Tenancy\Services\SubscriptionBilling;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A tenant becomes a reseller: it stops being a tenant, its profile and chosen people move to the
 * reseller portal, billing stops, and its business data is deleted once the retention period ends.
 */
class ConvertCompanyToResellerTest extends TestCase
{
    use DatabaseTransactions;

    private Company $company;

    private User $owner;      // the company's admin

    private User $clerk;      // an ordinary user

    private User $leaver;     // left behind

    private User $platform;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Mail::fake();

        $result = app(CompanyProvisioner::class)->provision(['company_name' => 'Acme Traders ' . Str::random(4), 'registration_number' => '2020/123456/07',
            'admin_name' => 'Thandi Mokoena', 'email' => 'thandi-' . Str::random(6) . '@example.test', 'phone' => '0821234567',
            'password' => 'Secret-123', 'plan' => 'starter', 'trial_days' => 0]);
        $this->company = $result['company'];
        $this->company->forceFill(['vat_number' => '4123456789'])->save();
        \App\Modules\Tenancy\Models\Subscription::updateOrCreate(['company_id' => $this->company->id], ['plan_code' => 'starter', 'status' => 'active']);
        $this->owner = $result['user'];
        $this->clerk = $this->tenantUser('Sipho', 'Dlamini');
        $this->leaver = $this->tenantUser('Lerato', 'Nkosi');
        app(TenantContext::class)->run($this->company->id, fn () => DB::table('company_identities')->insert([
            'company_id' => $this->company->id, 'company_name' => $this->company->name, 'mailing_address' => '12 Main Rd, Durban',
            'company_website' => 'https://acme.example', 'support_email' => 'help@acme.example',
            'company_logo' => json_encode(['mime' => 'image/png', 'data' => base64_encode('PNGDATA')]), 'created_at' => now(), 'updated_at' => now()]));

        $id = DB::table('users')->insertGetId(['uuid' => (string) Str::uuid(), 'email' => 'platform-' . Str::random(6) . '@example.test',
            'password' => bcrypt('secret'), 'company_id' => null, 'is_platform_admin' => true, 'status' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $this->platform = User::withoutGlobalScopes()->findOrFail($id);
    }

    protected function tearDown(): void
    {
        app(CurrentCompany::class)->clear();
        parent::tearDown();
    }

    private function tenantUser(string $first, string $last): User
    {
        $user = User::create(['uuid' => (string) Str::uuid(), 'email' => strtolower($first) . '-' . Str::random(6) . '@example.test',
            'password' => Hash::make('Secret-123'), 'status' => 1, 'company_id' => $this->company->id]);
        app(TenantContext::class)->run($this->company->id, fn () => DB::table('hr_people')->insert(['uuid' => (string) Str::uuid(),
            'company_id' => $this->company->id, 'user_id' => $user->id, 'first_name' => $first, 'surname' => $last, 'cell_number' => '0830000000',
            'email' => $user->email, 'status' => 1, 'created_at' => now(), 'updated_at' => now()]));

        return $user;
    }

    private function convert(array $overrides = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->platform)->post(route('tenancy.companies.convert', $this->company->id), array_merge([
            'commission_type' => 'percent', 'commission_value' => 15, 'retention_years' => 5,
            'users' => [$this->owner->id => 'owner', $this->clerk->id => 'staff', $this->leaver->id => 'exclude'],
            'confirm_name' => $this->company->name,
        ], $overrides));
    }

    public function test_the_dialog_offers_the_admin_as_owner_and_everyone_else_as_staff(): void
    {
        $data = $this->actingAs($this->platform)->getJson(route('tenancy.companies.convert.candidates', $this->company->id))->assertOk()->json();
        $roles = collect($data['users'])->pluck('suggested', 'id');
        $this->assertSame('owner', $roles[$this->owner->id]);
        $this->assertSame('staff', $roles[$this->clerk->id]);
        $this->assertSame('Sipho Dlamini', collect($data['users'])->firstWhere('id', $this->clerk->id)['name']);
        $this->assertSame(5, $data['retention_years']);
    }

    public function test_converting_moves_the_profile_and_people_and_closes_the_tenant(): void
    {
        $this->convert()->assertRedirect()->assertSessionHasNoErrors();

        $reseller = Reseller::query()->where('converted_from_company_id', $this->company->id)->firstOrFail();
        $this->assertSame([$this->company->name, 'company', '2020/123456/07', '4123456789', '12 Main Rd, Durban', 'https://acme.example', 'help@acme.example'],
            [$reseller->name, $reseller->type, $reseller->registration_number, $reseller->vat_number, $reseller->address, $reseller->website, $reseller->support_email]);
        $this->assertNotNull($reseller->logo);
        $this->assertSame(['percent', 15.0], [$reseller->commission_type, (float) $reseller->commission_value]);

        // People: kept ones leave the company with their login intact; the leaver is switched off.
        foreach ([[$this->owner, 'owner'], [$this->clerk, 'staff']] as [$user, $role]) {
            $user->refresh();
            $this->assertNull($user->company_id);
            $this->assertSame(1, (int) $user->status);
            $this->assertTrue(Hash::check('Secret-123', $user->password));
            $this->assertSame(['reseller'], $user->getRoleNames()->all());
            $this->assertSame(0, DB::table('security_module_access')->where('user_id', $user->id)->count());
            $this->assertSame($role, ResellerUser::query()->where('user_id', $user->id)->value('role'));
        }
        $this->assertSame('Sipho Dlamini', $this->clerk->fresh()->name);
        $this->assertSame(0, (int) $this->leaver->fresh()->status);
        $this->assertSame($this->company->id, (int) $this->leaver->fresh()->company_id);
        $this->assertFalse(ResellerUser::query()->where('user_id', $this->leaver->id)->exists());

        // The tenant is closed: archived, subscription cancelled, purge in five years.
        $company = $this->company->fresh();
        $this->assertSame('archived', $company->status);
        $this->assertSame($reseller->id, (int) $company->converted_to_reseller_id);
        $this->assertSame(now()->addYears(5)->toDateString(), $company->purge_after->toDateString());
        $this->assertSame('cancelled', DB::table('subscriptions')->where('company_id', $company->id)->value('status'));

        // Its data is untouched until then.
        $this->assertTrue(app(TenantContext::class)->run($company->id, fn () => DB::table('hr_people')->where('user_id', $this->clerk->id)->exists()));
    }

    public function test_moved_people_land_in_the_portal_and_owners_alone_run_the_account(): void
    {
        $this->convert();

        $this->actingAs($this->owner->fresh())->get(route('reseller.dashboard'))->assertOk();
        $this->actingAs($this->owner->fresh())->get('/dashboard')->assertRedirect(route('reseller.dashboard'));
        $this->actingAs($this->owner->fresh())->get(route('reseller.team'))->assertOk();
        $this->actingAs($this->owner->fresh())->get(route('reseller.commissions'))->assertOk();
        $this->actingAs($this->owner->fresh())->get(route('reseller.profile.logo'))->assertOk()->assertHeader('Content-Type', 'image/png');

        $this->actingAs($this->clerk->fresh())->get(route('reseller.dashboard'))->assertOk()
            ->assertInertia(fn ($page) => $page->where('stats.pending', null)->where('portalRole', 'staff'));
        $this->actingAs($this->clerk->fresh())->get(route('reseller.clients'))->assertOk();
        $this->actingAs($this->clerk->fresh())->get(route('reseller.commissions'))->assertForbidden();
        $this->actingAs($this->clerk->fresh())->get(route('reseller.team'))->assertForbidden();
        $this->actingAs($this->clerk->fresh())->put(route('reseller.profile.update'), ['name' => 'Hijack'])->assertForbidden();

        // The leaver cannot use the closed company.
        $this->actingAs($this->leaver->fresh())->get('/dashboard')->assertForbidden();
    }

    public function test_an_owner_manages_the_team_but_the_account_always_keeps_an_owner(): void
    {
        $this->convert();
        $owner = $this->owner->fresh();

        $this->actingAs($owner)->post(route('reseller.team.store'), ['first_name' => 'Ayo', 'surname' => 'Bello', 'email' => 'ayo-' . Str::random(5) . '@example.test',
            'role' => 'staff', 'password' => 'Another-123'])->assertSessionHasNoErrors();
        $ayo = ResellerUser::query()->where('first_name', 'Ayo')->firstOrFail();
        $this->assertTrue($ayo->user->hasRole('reseller'));
        $this->assertNull($ayo->user->company_id);

        $mine = ResellerUser::query()->where('user_id', $owner->id)->firstOrFail();
        $this->actingAs($owner)->delete(route('reseller.team.remove', $mine->id))->assertSessionHasErrors('member');
        $this->actingAs($owner)->put(route('reseller.team.update', $mine->id), ['role' => 'staff', 'first_name' => 'T', 'surname' => 'M'])->assertSessionHasErrors('role');

        $this->actingAs($owner)->delete(route('reseller.team.remove', $ayo->id))->assertSessionHasNoErrors();
        $this->assertSame(0, (int) User::find($ayo->user_id)->status);
        $this->assertFalse(ResellerUser::query()->whereKey($ayo->id)->exists());
    }

    public function test_an_archived_company_is_never_billed_or_reopened(): void
    {
        $invoice = SubscriptionInvoice::create(['company_id' => $this->company->id, 'period' => now()->format('Y-m'), 'amount' => 499, 'status' => 'open',
            'due_date' => now()->addDays(7)->toDateString(), 'number' => 'INV-T-' . Str::random(5)]);
        $this->convert();

        app(SubscriptionBilling::class)->markPaid($invoice->fresh(), 'EFT-1');
        $this->assertSame('archived', $this->company->fresh()->status, 'Paying an old invoice must not reopen the tenant.');
        $this->assertSame('paid', $invoice->fresh()->status);

        $this->actingAs($this->platform)->post(route('tenancy.companies.activate', $this->company->id))->assertSessionHas('error');
        $this->assertSame('archived', $this->company->fresh()->status);
        $this->convert()->assertSessionHas('error');
        $this->assertSame(1, Reseller::query()->where('converted_from_company_id', $this->company->id)->count());
    }

    public function test_viewing_archived_data_is_read_only(): void
    {
        $this->convert();
        $this->actingAs($this->platform)->withSession(['impersonate_company_id' => $this->company->id])
            ->post(route('workflow.forms.store'), ['name' => 'Sneaky'])->assertForbidden();
        $this->actingAs($this->platform)->withSession(['impersonate_company_id' => $this->company->id])
            ->post(route('tenancy.stop-impersonating'))->assertRedirect();
    }

    public function test_refusals(): void
    {
        $this->convert(['confirm_name' => 'Wrong name'])->assertSessionHasErrors('confirm_name');
        $this->convert(['users' => [$this->owner->id => 'staff', $this->clerk->id => 'staff']])->assertSessionHasErrors('convert');
        $stranger = User::create(['uuid' => (string) Str::uuid(), 'email' => 'x-' . Str::random(6) . '@example.test', 'password' => 'x', 'status' => 1, 'company_id' => null]);
        $this->convert(['users' => [$this->owner->id => 'owner', $stranger->id => 'staff']])->assertSessionHasErrors('convert');
        $this->assertSame('active', $this->company->fresh()->status);

        $this->actingAs($this->owner)->post(route('tenancy.companies.convert', $this->company->id), [])->assertForbidden();
    }

    public function test_after_the_retention_period_the_data_is_deleted_and_the_reseller_kept(): void
    {
        $this->convert();
        $company = $this->company->fresh();
        app(TenantContext::class)->run($company->id, fn () => DB::table('workflow_audit_events')->insert([
            'company_id' => $company->id, 'actor_type' => 'user', 'action' => 'test.event', 'subject_type' => 'test', 'hash' => str_repeat('a', 64), 'created_at' => now()]));

        $purge = app(ArchivedCompanyPurge::class);
        $this->assertSame([], array_filter($purge->due(), fn ($c) => $c->id === $company->id), 'Not due before the retention period ends.');

        $company->forceFill(['purge_after' => today()->subDay()])->save();
        $this->assertNotEmpty(array_filter($purge->due(), fn ($c) => $c->id === $company->id));
        $deleted = $purge->purge($company->fresh());

        $this->assertArrayHasKey('workflow_audit_events', $deleted);
        $this->assertSame([], $purge->inventory($company->fresh()), 'Nothing of the company is left.');
        $this->assertNull(User::find($this->leaver->id), 'The people left behind go with it.');
        $this->assertNotNull(User::find($this->clerk->id), 'The people who moved stay.');
        $this->assertNotNull($company->fresh()->purged_at);
        $this->assertNull($company->fresh()->contact_email);
        $this->assertTrue(Reseller::query()->whereKey($company->converted_to_reseller_id)->exists());

        // The append-only guard is back on for everyone else.
        $this->assertSame('O', DB::selectOne("SELECT tgenabled FROM pg_trigger WHERE tgname = 'workflow_audit_events_no_change'")->tgenabled);
    }
}
