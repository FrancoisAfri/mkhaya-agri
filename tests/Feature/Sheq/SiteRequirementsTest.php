<?php

namespace Tests\Feature\Sheq;

use App\Core\User\Models\User;
use App\Modules\Operations\Sheq\Services\ContractorService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * What a contractor must produce, decided at the site where the work is.
 *
 * One list for a whole company is the wrong shape. A refinery demanding a permit-to-work
 * procedure and a medical certificate has no business demanding the same of a contractor painting
 * an office, and a safety manager who raises the bar for the refinery raises it everywhere.
 *
 * The distinction the whole thing turns on is that **NULL is not an empty list**: NULL means the
 * site has never been asked and follows the company, `[]` means it has been asked and demands
 * nothing. Collapse them and every new site silently starts demanding nothing, which is the
 * opposite of the deny-by-default this register is built on.
 */
class SiteRequirementsTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $companyId = DB::table('companies')->min('id');

        if ($companyId === null) {
            $this->markTestSkipped('No company in the test database.');
        }

        $this->companyId = (int) $companyId;
        $this->user = $this->userWith(['sheq.configure', 'sheq.contractor.manage']);

        DB::table('sheq_settings')->updateOrInsert(
            ['company_id' => $this->companyId],
            ['contractor_required_documents' => json_encode(['COIDA_LETTER']), 'updated_at' => now()],
        );
    }

    private function userWith(array $permissions): User
    {
        $userId = DB::table('users')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'email' => Str::random(12).'@example.test',
            'password' => bcrypt('secret'),
            'status' => 1,
            'company_id' => $this->companyId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $user = User::findOrFail($userId);

        foreach ($permissions as $permission) {
            $user->givePermissionTo(
                \Spatie\Permission\Models\Permission::findOrCreate($permission, 'web'),
            );
        }

        return $user->fresh();
    }

    private function site(?array $required): int
    {
        return (int) DB::table('sheq_sites')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'code' => 'S'.Str::random(5), 'name' => 'Site '.Str::random(4),
            'site_type' => 'PLANT', 'timezone' => 'Africa/Johannesburg', 'is_active' => true,
            'contractor_required_documents' => $required === null ? null : json_encode($required),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function service(): ContractorService
    {
        return app(ContractorService::class);
    }

    public function test_a_site_with_no_list_of_its_own_follows_the_company(): void
    {
        $siteId = $this->site(null);

        $this->assertSame(
            ['COIDA_LETTER'],
            $this->service()->configuredRequiredTypes($this->companyId, $siteId),
        );
    }

    public function test_a_site_may_demand_more_than_the_company_does(): void
    {
        $siteId = $this->site(['COIDA_LETTER', 'MEDICAL_CERTIFICATE', 'SAFE_WORK_PROCEDURE']);

        $this->assertSame(
            ['COIDA_LETTER', 'MEDICAL_CERTIFICATE', 'SAFE_WORK_PROCEDURE'],
            $this->service()->configuredRequiredTypes($this->companyId, $siteId),
        );
    }

    public function test_a_site_that_demands_nothing_is_not_a_site_that_was_never_asked(): void
    {
        /*
         * The one that matters. An empty list is a decision — an office park that wants
         * contractors on the register without paperwork — and it has to survive as a decision
         * rather than falling back to the company's list, which would make it impossible to
         * express.
         */
        $asked = $this->site([]);
        $neverAsked = $this->site(null);

        $this->assertSame([], $this->service()->configuredRequiredTypes($this->companyId, $asked));
        $this->assertSame(['COIDA_LETTER'],
            $this->service()->configuredRequiredTypes($this->companyId, $neverAsked));
    }

    public function test_the_compliance_file_reads_the_bar_of_the_contractor_own_site(): void
    {
        /*
         * The gate, not just the lookup. A contractor on a site that demands a medical is blocked
         * by the missing medical even though the company list never mentions one — and the same
         * contractor moved to a site that follows the company is not.
         */
        $strictSite = $this->site(['COIDA_LETTER', 'MEDICAL_CERTIFICATE']);

        $contractor = $this->service()->register($this->companyId, [
            'name' => 'Acme Scaffolding', 'trade' => 'SCAFFOLDING', 'site_id' => $strictSite,
        ], $this->user->id);

        $file = $this->service()->complianceFile($this->companyId, $contractor['uuid']);
        $blocking = collect($file['blocking'])->pluck('type')->all();

        $this->assertContains('MEDICAL_CERTIFICATE', $blocking,
            "The site demands a medical certificate, so a missing one has to block. Blocking was: "
            .json_encode($blocking));
        $this->assertContains('COIDA_LETTER', $blocking);
    }

    public function test_an_explicit_site_overrides_the_contractor_own_site(): void
    {
        // A contractor registered against one site, working today at another: the site where the
        // work is decides, which is what the $siteId argument exists for.
        $home = $this->site(['COIDA_LETTER']);
        $strict = $this->site(['COIDA_LETTER', 'MEDICAL_CERTIFICATE']);

        $contractor = $this->service()->register($this->companyId, [
            'name' => 'Bravo Rigging', 'trade' => 'RIGGING', 'site_id' => $home,
        ], $this->user->id);

        $atHome = collect($this->service()->complianceFile($this->companyId, $contractor['uuid'])['blocking'])
            ->pluck('type')->all();
        $atStrict = collect($this->service()->complianceFile($this->companyId, $contractor['uuid'], [], $strict)['blocking'])
            ->pluck('type')->all();

        $this->assertNotContains('MEDICAL_CERTIFICATE', $atHome);
        $this->assertContains('MEDICAL_CERTIFICATE', $atStrict);
    }

    public function test_the_screen_can_set_what_a_site_demands_and_set_it_back_to_following(): void
    {
        $siteId = $this->site(null);
        $uuid = DB::table('sheq_sites')->where('id', $siteId)->value('uuid');

        $this->actingAs($this->user)
            ->post("/sheq/sites/{$uuid}/requirements", [
                'required_documents' => ['COIDA_LETTER', 'MEDICAL_CERTIFICATE'],
            ])->assertRedirect();

        $this->assertSame(['COIDA_LETTER', 'MEDICAL_CERTIFICATE'],
            json_decode((string) DB::table('sheq_sites')->where('id', $siteId)
                ->value('contractor_required_documents'), true));

        // Back to following the company — stored as NULL, not as a copy of today's company list,
        // or the site would stop tracking future changes without anybody noticing.
        $this->actingAs($this->user)
            ->post("/sheq/sites/{$uuid}/requirements", ['required_documents' => null])
            ->assertRedirect();

        $this->assertNull(DB::table('sheq_sites')->where('id', $siteId)
            ->value('contractor_required_documents'));
    }

    public function test_changing_what_a_site_demands_is_audited(): void
    {
        // It moves a gate: raising the bar blocks contractors who worked yesterday, and somebody
        // will ask who did it.
        $siteId = $this->site(null);
        $uuid = DB::table('sheq_sites')->where('id', $siteId)->value('uuid');

        $this->actingAs($this->user)
            ->post("/sheq/sites/{$uuid}/requirements", ['required_documents' => ['SAFETY_FILE']])
            ->assertRedirect();

        $entry = DB::table('sheq_audit_log')
            ->where('company_id', $this->companyId)
            ->where('action', 'sheq_site.requirements_changed')
            ->where('subject_id', $siteId)
            ->orderByDesc('id')->first();

        $this->assertNotNull($entry, 'Moving a site gate left no audit entry.');
        $this->assertSame($this->user->id, $entry->actor_id);
        $this->assertStringContainsString('SAFETY_FILE', (string) $entry->after);
    }

    public function test_an_unknown_document_type_cannot_be_demanded(): void
    {
        // The dropdown is filled from ContractorService::documentTypes(); a type outside it could
        // never be satisfied, so a contractor would be blocked by a requirement nobody can meet.
        $siteId = $this->site(null);
        $uuid = DB::table('sheq_sites')->where('id', $siteId)->value('uuid');

        $this->actingAs($this->user)
            ->post("/sheq/sites/{$uuid}/requirements", [
                'required_documents' => ['LETTER_OF_GOOD_STANDING'],
            ])->assertSessionHasErrors('required_documents.0');

        $this->assertNull(DB::table('sheq_sites')->where('id', $siteId)
            ->value('contractor_required_documents'));
    }
}
