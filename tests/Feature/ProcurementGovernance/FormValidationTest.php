<?php

namespace Tests\Feature\ProcurementGovernance;

use App\Core\User\Models\User;
use App\Modules\Procurement\Governance\Exceptions\DomainRuleException;
use App\Modules\Procurement\Governance\Services\CommitteeService;
use App\Modules\Procurement\Governance\Support\Presenters\OptionLists;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Tests\Support\MakesCompanies;
use Tests\TestCase;

/**
 * Broken business rules must arrive as form validation, not as a stack trace.
 *
 * A 500 tells the user the system is broken. In every case here the system worked perfectly and the
 * answer was simply no — which is a message next to a field, not an error page. This suite covers
 * the specific route that produced one in the browser (a committee whose chairperson and secretary
 * were the same person) and the pick lists that used to be empty free-text boxes.
 */
class FormValidationTest extends TestCase
{
    use DatabaseTransactions, MakesCompanies;

    private int $companyId;

    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();

        $companyId = DB::table('companies')->min('id');

        if ($companyId === null) {
            $this->markTestSkipped('No company in the test database.');
        }

        $this->companyId = (int) $companyId;
        Cache::flush();
        app(CurrentCompany::class)->set($this->companyId);

        $this->userId = DB::table('users')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'email' => 'chair-'.Str::random(8).'@example.test',
            'password' => bcrypt('secret'),
            'company_id' => $this->companyId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs(User::find($this->userId));
    }

    protected function tearDown(): void
    {
        app(CurrentCompany::class)->clear();
        parent::tearDown();
    }

    // ── The 500 that started this ───────────────────────────────────────────────────────────────

    public function test_one_person_as_both_chairperson_and_secretary_does_not_error(): void
    {
        // The service adds the chairperson and the secretary as members, then used to throw its own
        // "already a member" guard against its own input — a 500 on an ordinary form submission.
        $committee = app(CommitteeService::class)->create($this->companyId, [
            'name' => 'Small unit committee',
            'chairperson_id' => $this->userId,
            'secretary_id' => $this->userId,
        ]);

        $this->assertNotNull($committee->reference);

        $members = DB::table('procgov_committee_members')
            ->where('committee_id', $committee->id)
            ->get();

        // Added once, in the voting seat — not twice, and not refused.
        $this->assertCount(1, $members);
        $this->assertSame('CHAIRPERSON', $members->first()->member_role);
    }

    public function test_adding_the_same_person_twice_by_hand_is_a_domain_rule_not_a_crash(): void
    {
        $committee = app(CommitteeService::class)->create($this->companyId, ['name' => 'Panel']);

        app(CommitteeService::class)->addMember($this->companyId, (int) $committee->id, [
            'user_id' => $this->userId,
            'member_role' => 'MEMBER',
        ]);

        try {
            app(CommitteeService::class)->addMember($this->companyId, (int) $committee->id, [
                'user_id' => $this->userId,
                'member_role' => 'MEMBER',
            ]);

            $this->fail('Adding the same person twice should have been refused.');
        } catch (DomainRuleException $e) {
            $this->assertStringContainsString('already a member', $e->getMessage());
        }
    }

    public function test_a_domain_rule_exception_renders_as_validation_rather_than_a_server_error(): void
    {
        // Laravel calls render() on an exception that defines one, so no controller can forget it.
        $exception = DomainRuleException::forField('secretary_id', 'That person is already a member.');

        $response = $exception->render(
            tap(request(), fn ($r) => $r->headers->set('Accept', 'application/json'))
        );

        $this->assertSame(422, $response->getStatusCode());
        $this->assertArrayHasKey('secretary_id', $response->getData(true)['errors']);
    }

    public function test_every_service_rule_is_a_domain_rule_exception(): void
    {
        // A plain RuntimeException escaping a service is a 500 waiting to happen.
        $offenders = [];

        foreach (glob(base_path('app/Modules/Procurement/Governance/Services/*.php')) as $file) {
            if (str_contains(file_get_contents($file), 'new \RuntimeException(')) {
                $offenders[] = basename($file);
            }
        }

        $this->assertSame([], $offenders);
    }

    public function test_a_validation_message_names_the_field_the_way_the_form_does(): void
    {
        // "The secretary id field and chairperson id must be different" is the column name shown to
        // whoever is filling in the form. The rule was right; the sentence was gibberish.
        $validator = Validator::make(
            ['chairperson_id' => 7, 'secretary_id' => 7],
            ['secretary_id' => ['different:chairperson_id']],
        );

        $message = $validator->errors()->first('secretary_id');

        $this->assertStringNotContainsString('_id', $message);
        $this->assertStringNotContainsString('secretary id', $message);
        $this->assertStringContainsString('secretary', $message);
        $this->assertStringContainsString('chairperson', $message);
    }

    public function test_a_platform_admin_can_appoint_themselves(): void
    {
        /*
         * A platform administrator has no company_id — that is what makes them platform-level — so a
         * person-picker filtered on company_id alone leaves them out of every list in the module.
         * The person setting a tenant up then cannot appoint themselves chairperson, secretary,
         * procurement officer or witness, and there is no error to explain it: their own name is
         * simply missing from a list they are looking straight at.
         */
        $adminId = DB::table('users')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'email' => 'platform-'.Str::random(8).'@example.test',
            'password' => bcrypt('secret'),
            'company_id' => null,
            'is_platform_admin' => true,
            'status' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs(User::withoutGlobalScopes()->find($adminId));

        $officers = app(OptionLists::class)->officers($this->companyId);

        $this->assertContains(
            $adminId,
            array_column($officers, 'id'),
            'A platform admin working inside a tenant cannot appoint themselves to anything.',
        );
    }

    public function test_other_platform_admins_are_not_listed_in_every_tenant(): void
    {
        // Solving the above by listing every platform admin would put a directory of the vendor's
        // staff into each customer's dropdowns to fix a problem nobody has.
        $otherAdminId = DB::table('users')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'email' => 'other-platform-'.Str::random(8).'@example.test',
            'password' => bcrypt('secret'),
            'company_id' => null,
            'is_platform_admin' => true,
            'status' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs(User::find($this->userId));

        $this->assertNotContains(
            $otherAdminId,
            array_column(app(OptionLists::class)->officers($this->companyId), 'id'),
        );
    }

    // ── The dropdowns ───────────────────────────────────────────────────────────────────────────

    public function test_procurement_categories_are_a_configured_list_not_an_empty_box(): void
    {
        $categories = app(OptionLists::class)->procurementCategories($this->companyId);

        $this->assertNotEmpty($categories, 'A new tenant would see an empty category picker.');
        $this->assertContains('Goods', $categories);
        $this->assertContains('Services', $categories);
    }

    public function test_committee_types_are_a_configured_list(): void
    {
        $types = app(OptionLists::class)->committeeTypes($this->companyId);

        $this->assertNotEmpty($types);
        $this->assertContains('Bid evaluation committee', array_column($types, 'value'));
    }

    public function test_a_category_already_in_use_stays_selectable(): void
    {
        // Tightening a free-text field into a list must not orphan what is already filed under the
        // old values.
        DB::table('procgov_records')->insert([
            'uuid' => (string) Str::uuid(),
            'company_id' => $this->companyId,
            'reference' => 'PROC-CAT-'.Str::random(5),
            'title' => 'Legacy record',
            'category' => 'Legacy Typed Category',
            'stage' => 'REQUEST',
            'status' => 'ACTIVE',
            'currency' => 'ZAR',
            'opened_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertContains(
            'Legacy Typed Category',
            app(OptionLists::class)->procurementCategories($this->companyId),
        );
    }

    public function test_the_configured_list_leads_so_new_work_uses_the_shared_vocabulary(): void
    {
        $categories = app(OptionLists::class)->procurementCategories($this->companyId);

        $this->assertSame('Goods', $categories[0]);
    }

    public function test_option_lists_are_per_tenant(): void
    {
        $other = $this->otherCompanyId($this->companyId);

        DB::table('procgov_option_lists')->insert([
            'company_id' => $this->companyId,
            'list_key' => 'procurement_category',
            'code' => 'TENANT_ONLY',
            'label' => 'Tenant only category',
            'sort_order' => 500,
            'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertContains(
            'Tenant only category',
            array_column(app(OptionLists::class)->optionList($this->companyId, 'procurement_category'), 'value'),
        );
        $this->assertNotContains(
            'Tenant only category',
            array_column(app(OptionLists::class)->optionList((int) $other, 'procurement_category'), 'value'),
        );
    }

    public function test_a_deactivated_option_disappears_from_the_picker(): void
    {
        DB::table('procgov_option_lists')
            ->where('company_id', $this->companyId)
            ->where('list_key', 'procurement_category')
            ->where('code', 'GOODS')
            ->update(['is_active' => false]);

        $this->assertNotContains(
            'Goods',
            array_column(app(OptionLists::class)->optionList($this->companyId, 'procurement_category'), 'value'),
        );
    }
}
