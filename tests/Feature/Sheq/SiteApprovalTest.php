<?php

namespace Tests\Feature\Sheq;

use App\Core\User\Models\User;
use App\Modules\Operations\Sheq\Services\ContractorComplianceStatus;
use App\Modules\Operations\Sheq\Services\ContractorService;
use App\Modules\Operations\Sheq\Services\SiteApprovalService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Contractor approval, decided per site (CON-14).
 *
 * The requirement in one line: approval at one site must never automatically approve another.
 * ABC Construction cleared at Johannesburg and Durban and blocked at Cape Town is three
 * decisions, not one, and the Cape Town block has to survive the Johannesburg approval.
 *
 * What the module had instead was one `access_status` for the whole company, computed without a
 * site, plus a document of type SITE_SPECIFIC whose `site_id` could be null — so a single upload
 * cleared the entire estate. These tests are mostly about the three states staying distinct:
 * approved, blocked, and nobody has decided, which is neither.
 */
class SiteApprovalTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;

    private User $user;

    private array $contractor;

    private int $joburg;

    private int $durban;

    private int $capeTown;

    protected function setUp(): void
    {
        parent::setUp();

        // These tests read what a page hands its component, not the compiled front end. Without
        // this the suite depends on `public/build/manifest.json`, so an unrelated `vite build`
        // running in another checkout turns every page assertion below into a 500.
        $this->withoutVite();

        $companyId = DB::table('companies')->min('id');

        if ($companyId === null) {
            $this->markTestSkipped('No company in the test database.');
        }

        $this->companyId = (int) $companyId;
        $this->user = $this->userWith(['sheq.contractor.manage', 'sheq.configure']);

        $this->joburg = $this->site('Johannesburg');
        $this->durban = $this->site('Durban');
        $this->capeTown = $this->site('Cape Town');

        // Nothing required, so a refusal in these tests can only have come from the site
        // decision. A missing COIDA letter would block everywhere and prove nothing.
        DB::table('sheq_settings')->updateOrInsert(
            ['company_id' => $this->companyId],
            ['contractor_required_documents' => json_encode([]), 'updated_at' => now()],
        );

        $contractors = app(ContractorService::class);

        $this->contractor = $contractors->register($this->companyId, [
            'name' => 'ABC Construction', 'trade' => 'CIVILS',
        ], $this->user->id);

        /*
         * `register()` seeds the cached `access_status` as BLOCKED on purpose — a gate's safe
         * default before anybody has looked at the file. The turnstile reads that column, so
         * without a refresh here every test below would be refused for a reason that has nothing
         * to do with the site decision under test.
         */
        $contractors->refreshAccessStatus($this->companyId, $this->contractor['uuid'], [],
            $this->user->id);
    }

    private function userWith(array $permissions): User
    {
        $userId = DB::table('users')->insertGetId([
            'uuid' => (string) Str::uuid(), 'email' => 'csa-'.Str::random(8).'@example.test',
            'password' => bcrypt('secret'), 'company_id' => $this->companyId, 'status' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $moduleId = DB::table('security_modules')->where('code_name', 'sheq')->value('id');

        if ($moduleId === null) {
            $this->markTestSkipped('SHEQ module is not registered.');
        }

        DB::table('security_module_access')->insert([
            'module_id' => $moduleId, 'user_id' => $userId, 'access_level' => 4,
            'active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('security_modules')->where('id', $moduleId)->update(['active' => 1]);
        Cache::forget('module_active_sheq');

        $user = User::withoutGlobalScopes()->findOrFail($userId);

        foreach ($permissions as $permission) {
            $user->givePermissionTo($permission);
        }

        $user->forgetCachedPermissions();

        return $user;
    }

    private function site(string $name): int
    {
        return (int) DB::table('sheq_sites')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'code' => 'S'.Str::upper(Str::random(5)), 'name' => $name, 'site_type' => 'SITE',
            'timezone' => 'Africa/Johannesburg', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function approvals(): SiteApprovalService
    {
        return app(SiteApprovalService::class);
    }

    private function statusAt(int $siteId): string
    {
        return $this->approvals()
            ->statusFor($this->companyId, (int) $this->contractor['id'], $siteId)['status'];
    }

    /** Turn the "nobody has decided" rule into a refusal, which is what a strict host does. */
    private function requireSiteApproval(): void
    {
        app(ContractorComplianceStatus::class)->saveRules($this->companyId,
            ['MISSING_SITE_APPROVAL' => true], $this->user->id);
    }

    // ── The requirement itself ────────────────────────────────────────────────────────────────

    public function test_approval_at_one_site_is_not_approval_at_another(): void
    {
        /*
         * The example from the requirement, verbatim. Three sites, three answers, and the one
         * that matters is Cape Town: nothing about approving Johannesburg may touch it.
         */
        $this->approvals()->decide($this->companyId, $this->contractor['uuid'], $this->joburg,
            ['status' => 'APPROVED'], $this->user->id);

        $this->approvals()->decide($this->companyId, $this->contractor['uuid'], $this->durban,
            ['status' => 'APPROVED'], $this->user->id);

        $this->approvals()->decide($this->companyId, $this->contractor['uuid'], $this->capeTown,
            ['status' => 'BLOCKED', 'reason' => 'Refused after the scaffold collapse in March.'],
            $this->user->id);

        $this->assertSame('APPROVED', $this->statusAt($this->joburg));
        $this->assertSame('APPROVED', $this->statusAt($this->durban));
        $this->assertSame('BLOCKED', $this->statusAt($this->capeTown));
    }

    public function test_a_site_nobody_has_decided_is_neither_approved_nor_blocked(): void
    {
        /*
         * The distinction the whole thing turns on. Treating absence as approval lets anybody
         * onto a new site; treating it as a block refuses every contractor at every site the
         * moment this ships. It is a third state, and the host's rule decides its weight.
         */
        $this->approvals()->decide($this->companyId, $this->contractor['uuid'], $this->joburg,
            ['status' => 'APPROVED'], $this->user->id);

        $this->assertSame('UNDECIDED', $this->statusAt($this->durban));

        $contractors = app(ContractorService::class);

        // Lenient by default: an undecided site reports the gap without refusing.
        $this->assertTrue(
            $contractors->mayWork($this->companyId, $this->contractor['uuid'], [],
                $this->durban)['allowed'],
            'An undecided site refused entry while MISSING_SITE_APPROVAL was off.');

        $this->requireSiteApproval();

        $this->assertFalse(
            $contractors->mayWork($this->companyId, $this->contractor['uuid'], [],
                $this->durban)['allowed'],
            'An undecided site admitted entry after MISSING_SITE_APPROVAL was switched on.');

        // And the decided one is still fine, which is the point of deciding per site.
        $this->assertTrue(
            $contractors->mayWork($this->companyId, $this->contractor['uuid'], [],
                $this->joburg)['allowed']);
    }

    public function test_a_blocked_site_refuses_whatever_the_company_wide_file_says(): void
    {
        /*
         * A site bans a contractor for a reason the company-wide file knows nothing about: an
         * incident there, a hazard only there, an argument with that site's manager. The ban has
         * to survive a perfectly clean file, and it does not go through the blocking-rules
         * switch — a head-office switch must not walk them back through that gate.
         */
        $this->approvals()->decide($this->companyId, $this->contractor['uuid'], $this->capeTown,
            ['status' => 'BLOCKED', 'reason' => 'Refused after the scaffold collapse in March.'],
            $this->user->id);

        // Everything switched to advisory — the most lenient configuration there is.
        app(ContractorComplianceStatus::class)->saveRules($this->companyId, [
            'EXPIRED_MANDATORY_DOCUMENT' => false, 'MISSING_MANDATORY_DOCUMENT' => false,
            'EXPIRED_COID' => false, 'EXPIRED_INSURANCE' => false,
            'REJECTED_SAFETY_FILE' => false, 'SUSPENDED_SAFETY_FILE' => false,
            'EXPIRED_SAFETY_FILE' => false, 'MISSING_SITE_APPROVAL' => false,
            'SITE_BLOCKED' => false,    // ignored: not a host's to change
        ], $this->user->id);

        $verdict = app(ContractorService::class)
            ->mayWork($this->companyId, $this->contractor['uuid'], [], $this->capeTown);

        $this->assertFalse($verdict['allowed'],
            'A site block was overridden by the company-wide blocking rules.');
        $this->assertStringContainsString('scaffold collapse',
            implode(' ', $verdict['reasons']),
            'The refusal did not carry the reason the site gave.');
    }

    public function test_the_site_blocked_rule_cannot_be_switched_off(): void
    {
        // The switch exists in the shipped set so `rulesFor()` answers for it, but a stored
        // `false` — hand-edited, or posted by a form somebody changed — must not take effect.
        app(ContractorComplianceStatus::class)->saveRules($this->companyId,
            ['SITE_BLOCKED' => false], $this->user->id);

        $this->assertTrue(
            app(ContractorComplianceStatus::class)->rulesFor($this->companyId)['SITE_BLOCKED']);

        $stored = json_decode((string) DB::table('sheq_settings')
            ->where('company_id', $this->companyId)->value('contractor_blocking_rules'), true);

        $this->assertArrayNotHasKey('SITE_BLOCKED', $stored ?? [],
            'An unswitchable rule was written into the tenant configuration, where it reads as '
            .'a switch that does nothing.');
    }

    public function test_a_company_wide_refusal_still_refuses_at_an_approved_site(): void
    {
        // The other direction. A site's approval is permission to be there, not a waiver of the
        // safety file, so a blocked contractor stays blocked at a site that approved them.
        DB::table('sheq_settings')->where('company_id', $this->companyId)
            ->update(['contractor_required_documents' => json_encode(['COIDA_LETTER'])]);

        $this->approvals()->decide($this->companyId, $this->contractor['uuid'], $this->joburg,
            ['status' => 'APPROVED'], $this->user->id);

        $verdict = app(ContractorService::class)
            ->mayWork($this->companyId, $this->contractor['uuid'], [], $this->joburg);

        $this->assertFalse($verdict['allowed']);
        $this->assertStringContainsString('COIDA', implode(' ', $verdict['reasons']));
    }

    // ── The older document path, which still counts ──────────────────────────────────────────

    public function test_a_document_naming_this_site_still_satisfies_the_requirement(): void
    {
        /*
         * Per-site decisions arrived after a document of type SITE_SPECIFIC, and tenants have
         * those on file. A document that NAMES a site is evidence about that site and still
         * clears it, so switching the rule on does not refuse every contractor who was approved
         * the old way.
         */
        $this->requireSiteApproval();

        app(ContractorService::class)->attachDocument($this->companyId, $this->contractor['uuid'], [
            'document_type' => 'SITE_SPECIFIC', 'site_id' => $this->joburg,
            'expires_on' => Carbon::today()->addYear()->toDateString(),
        ], $this->user->id);

        $contractors = app(ContractorService::class);

        $this->assertTrue($contractors
            ->mayWork($this->companyId, $this->contractor['uuid'], [], $this->joburg)['allowed']);
        $this->assertFalse($contractors
            ->mayWork($this->companyId, $this->contractor['uuid'], [], $this->durban)['allowed'],
            'A document naming Johannesburg cleared Durban.');
    }

    public function test_a_document_naming_no_site_does_not_clear_every_site(): void
    {
        /*
         * The hole per-site approval exists to close. A SITE_SPECIFIC document with no `site_id`
         * applies everywhere, so one upload used to read as approved at every site on the
         * estate — which is the opposite of "approval at one site is never approval at another".
         */
        $this->requireSiteApproval();

        app(ContractorService::class)->attachDocument($this->companyId, $this->contractor['uuid'], [
            'document_type' => 'SITE_SPECIFIC', 'site_id' => null,
            'expires_on' => Carbon::today()->addYear()->toDateString(),
        ], $this->user->id);

        $contractors = app(ContractorService::class);

        foreach (['Johannesburg' => $this->joburg, 'Durban' => $this->durban] as $name => $siteId) {
            $this->assertFalse(
                $contractors->mayWork($this->companyId, $this->contractor['uuid'], [],
                    $siteId)['allowed'],
                "One site-less document cleared {$name}.");
        }
    }

    public function test_an_explicit_approval_satisfies_a_host_that_also_demands_the_document(): void
    {
        /*
         * Belt and braces, the wrong way round. A host that lists SITE_SPECIFIC among its
         * required documents AND has the site decide would otherwise refuse a contractor the
         * site explicitly approved, for want of the piece of paper the approval was presumably
         * granted on the strength of.
         */
        DB::table('sheq_settings')->where('company_id', $this->companyId)
            ->update(['contractor_required_documents' => json_encode(['SITE_SPECIFIC'])]);

        $this->requireSiteApproval();

        $this->approvals()->decide($this->companyId, $this->contractor['uuid'], $this->joburg,
            ['status' => 'APPROVED'], $this->user->id);

        $verdict = app(ContractorService::class)
            ->mayWork($this->companyId, $this->contractor['uuid'], [], $this->joburg);

        $this->assertTrue($verdict['allowed'], implode(' | ', $verdict['reasons']));
    }

    public function test_an_undecided_site_is_reported_once_not_twice(): void
    {
        // Two paths can raise the same finding. A guard reading "no site approval" twice on one
        // verdict reads it as two different problems.
        DB::table('sheq_settings')->where('company_id', $this->companyId)
            ->update(['contractor_required_documents' => json_encode(['SITE_SPECIFIC'])]);

        $file = app(ContractorService::class)
            ->complianceFile($this->companyId, $this->contractor['uuid'], [], $this->durban);

        $siteFindings = collect($file['blocking'])->where('type', 'SITE_SPECIFIC');

        $this->assertCount(1, $siteFindings,
            'The same missing site approval was reported '.$siteFindings->count().' times.');
    }

    // ── Lapsing ───────────────────────────────────────────────────────────────────────────────

    public function test_a_lapsed_approval_is_not_a_block(): void
    {
        /*
         * Somebody forgot to renew a review. That is a reason to stop and ask, which is what
         * UNDECIDED already means — not a reason to treat the contractor as barred from the site.
         * Reading it as a block would let a diary date impose a ban nobody decided on.
         */
        $this->approvals()->decide($this->companyId, $this->contractor['uuid'], $this->joburg, [
            'status' => 'APPROVED',
            'valid_until' => Carbon::today()->addDay()->toDateString(),
        ], $this->user->id);

        $this->assertSame('APPROVED', $this->statusAt($this->joburg));

        $this->travelTo(Carbon::now()->addDays(3));

        $this->assertSame('EXPIRED', $this->statusAt($this->joburg));

        // Lenient by default, like any undecided site.
        $this->assertTrue(app(ContractorService::class)
            ->mayWork($this->companyId, $this->contractor['uuid'], [], $this->joburg)['allowed']);
    }

    public function test_a_block_does_not_lapse(): void
    {
        // An expiry on a ban would quietly unban. The column exists for approvals only.
        $this->approvals()->decide($this->companyId, $this->contractor['uuid'], $this->capeTown, [
            'status' => 'BLOCKED', 'reason' => 'Refused after the scaffold collapse in March.',
            'valid_until' => Carbon::today()->addDay()->toDateString(),
        ], $this->user->id);

        $this->travelTo(Carbon::now()->addYear());

        $this->assertSame('BLOCKED', $this->statusAt($this->capeTown));
    }

    // ── Keeping the decision honest ───────────────────────────────────────────────────────────

    public function test_a_block_needs_a_reason(): void
    {
        // A block with no reason is a block nobody can lift: the next person to look cannot tell
        // whether the cause has been dealt with, so it stays in place for ever by nobody's
        // decision in particular.
        $this->expectExceptionMessageMatches('/reason/i');

        $this->approvals()->decide($this->companyId, $this->contractor['uuid'], $this->capeTown,
            ['status' => 'BLOCKED'], $this->user->id);
    }

    public function test_one_live_decision_per_contractor_per_site(): void
    {
        // Deciding twice is changing your mind, not adding a second answer. Two live rows would
        // mean "may they work here" had two answers depending on which one was read.
        $this->approvals()->decide($this->companyId, $this->contractor['uuid'], $this->joburg,
            ['status' => 'APPROVED'], $this->user->id);

        $this->approvals()->decide($this->companyId, $this->contractor['uuid'], $this->joburg,
            ['status' => 'BLOCKED', 'reason' => 'Changed our minds after the audit.'],
            $this->user->id);

        $this->assertSame(1, DB::table('sheq_contractor_site_approvals')
            ->where('company_id', $this->companyId)
            ->where('contractor_id', $this->contractor['id'])
            ->where('site_id', $this->joburg)->whereNull('deleted_at')->count());

        $this->assertSame('BLOCKED', $this->statusAt($this->joburg));
    }

    public function test_withdrawing_returns_a_site_to_undecided_and_frees_the_slot(): void
    {
        /*
         * Soft-deleted, so the partial unique index releases the slot. A plain unique index over
         * a nullable `deleted_at` would not do it — Postgres never collides NULLs — and a
         * withdrawn decision would block every later one for ever.
         */
        $this->approvals()->decide($this->companyId, $this->contractor['uuid'], $this->joburg,
            ['status' => 'BLOCKED', 'reason' => 'Pending the incident investigation.'],
            $this->user->id);

        $this->approvals()->withdraw($this->companyId, $this->contractor['uuid'], $this->joburg,
            $this->user->id);

        $this->assertSame('UNDECIDED', $this->statusAt($this->joburg));

        $this->approvals()->decide($this->companyId, $this->contractor['uuid'], $this->joburg,
            ['status' => 'APPROVED'], $this->user->id);

        $this->assertSame('APPROVED', $this->statusAt($this->joburg));
    }

    public function test_every_decision_is_audited(): void
    {
        // It moves a gate. Somebody will ask who closed it and why.
        $this->approvals()->decide($this->companyId, $this->contractor['uuid'], $this->capeTown,
            ['status' => 'BLOCKED', 'reason' => 'Refused after the scaffold collapse in March.'],
            $this->user->id);

        $entry = DB::table('sheq_audit_log')
            ->where('company_id', $this->companyId)
            ->where('action', 'contractor.site_decision')
            ->where('subject_id', $this->contractor['id'])
            ->orderByDesc('id')->first();

        $this->assertNotNull($entry, 'Blocking a contractor at a site left no audit entry.');
        $this->assertSame($this->user->id, $entry->actor_id);
        $this->assertStringContainsString('scaffold collapse', (string) $entry->reason);
    }

    // ── Tenancy ───────────────────────────────────────────────────────────────────────────────

    public function test_a_site_from_another_company_cannot_be_decided_about(): void
    {
        // The site id arrives from a request. Without the company clause a decision could be
        // written against another tenant's site, and that site's gate would then read it.
        $otherCompany = (int) DB::table('companies')->insertGetId([
            'uuid' => (string) Str::uuid(), 'name' => 'Other Employer',
            'slug' => 'sheq-csa-other-'.Str::lower(Str::random(8)), 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $foreignSite = (int) DB::table('sheq_sites')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $otherCompany,
            'code' => 'X'.Str::random(5), 'name' => 'Not Our Site', 'site_type' => 'SITE',
            'timezone' => 'Africa/Johannesburg', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->expectExceptionMessageMatches('/site/i');

        $this->approvals()->decide($this->companyId, $this->contractor['uuid'], $foreignSite,
            ['status' => 'APPROVED'], $this->user->id);
    }

    // ── The screen ────────────────────────────────────────────────────────────────────────────

    public function test_the_contractor_screen_lists_every_site_including_the_undecided_ones(): void
    {
        /*
         * An engine with no door otherwise. And the undecided sites are the point of the list:
         * showing only the rows that exist answers "where are they approved" and silently drops
         * "and where has nobody looked", which is the question asked before work starts
         * somewhere new.
         */
        $this->approvals()->decide($this->companyId, $this->contractor['uuid'], $this->joburg,
            ['status' => 'APPROVED', 'conditions' => 'Escorted at all times.'], $this->user->id);

        $rows = collect($this->actingAs($this->user)
            ->get("/sheq/contractors/{$this->contractor['uuid']}")
            ->assertOk()
            ->viewData('page')['props']['siteApprovals']);

        $joburg = $rows->firstWhere('site_id', $this->joburg);
        $durban = $rows->firstWhere('site_id', $this->durban);

        $this->assertNotNull($joburg);
        $this->assertSame('APPROVED', $joburg['status']);
        $this->assertSame('Escorted at all times.', $joburg['conditions']);

        $this->assertNotNull($durban, 'A site with no decision was left off the list.');
        $this->assertSame('UNDECIDED', $durban['status']);
    }

    public function test_the_screen_can_decide_and_withdraw(): void
    {
        $this->actingAs($this->user)
            ->post("/sheq/contractors/{$this->contractor['uuid']}/sites", [
                'site_id' => $this->capeTown, 'status' => 'BLOCKED',
                'reason' => 'Refused after the scaffold collapse in March.',
            ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('BLOCKED', $this->statusAt($this->capeTown));

        $this->actingAs($this->user)
            ->delete("/sheq/contractors/{$this->contractor['uuid']}/sites",
                ['site_id' => $this->capeTown])
            ->assertRedirect();

        $this->assertSame('UNDECIDED', $this->statusAt($this->capeTown));
    }

    public function test_the_screen_refuses_a_block_with_no_reason(): void
    {
        $this->actingAs($this->user)
            ->post("/sheq/contractors/{$this->contractor['uuid']}/sites", [
                'site_id' => $this->capeTown, 'status' => 'BLOCKED',
            ])->assertSessionHasErrors('reason');

        $this->assertSame('UNDECIDED', $this->statusAt($this->capeTown));
    }

    public function test_deciding_needs_the_contractor_permission(): void
    {
        $this->actingAs($this->userWith([]))
            ->post("/sheq/contractors/{$this->contractor['uuid']}/sites", [
                'site_id' => $this->joburg, 'status' => 'APPROVED',
            ])->assertForbidden();
    }

    // ── The gate ──────────────────────────────────────────────────────────────────────────────

    public function test_a_scanned_pass_at_a_blocked_site_refuses_and_says_why(): void
    {
        /*
         * End to end, through the surface a guard actually uses. The whole feature is worth
         * nothing if the decision is only visible on the office screen.
         */
        $this->approvals()->decide($this->companyId, $this->contractor['uuid'], $this->capeTown,
            ['status' => 'BLOCKED', 'reason' => 'Refused after the scaffold collapse in March.'],
            $this->user->id);

        $token = app(\App\Modules\Operations\Sheq\Services\AccessPassService::class)
            ->contractorToken($this->companyId, $this->contractor['uuid'], $this->user->id);

        $response = $this->get("/sheq/pass/{$token}?site={$this->capeTown}")->assertOk();

        $response->assertSee('ACCESS DENIED');
        $response->assertSee('scaffold collapse', false);
    }

    public function test_the_same_pass_is_allowed_at_a_site_that_approved_them(): void
    {
        $this->approvals()->decide($this->companyId, $this->contractor['uuid'], $this->joburg,
            ['status' => 'APPROVED', 'conditions' => 'Escorted at all times.'], $this->user->id);

        $this->approvals()->decide($this->companyId, $this->contractor['uuid'], $this->capeTown,
            ['status' => 'BLOCKED', 'reason' => 'Refused after the scaffold collapse in March.'],
            $this->user->id);

        $token = app(\App\Modules\Operations\Sheq\Services\AccessPassService::class)
            ->contractorToken($this->companyId, $this->contractor['uuid'], $this->user->id);

        $response = $this->get("/sheq/pass/{$token}?site={$this->joburg}")->assertOk();

        $response->assertSee('ACCESS APPROVED');
        // The conditions reach the guard. Written where only the approver sees them, "escorted
        // at all times" is a condition nobody enforces.
        $response->assertSee('Escorted at all times.', false);
    }

    public function test_the_turnstile_refuses_a_worker_at_a_site_their_employer_is_blocked_at(): void
    {
        /*
         * The other gate. `AccessDecisionService` reads the cached company-wide `access_status`,
         * which is computed without a site and therefore cannot answer "may they work HERE" — a
         * contractor approved at Johannesburg and blocked at Cape Town reads COMPLIANT in that
         * column, and a turnstile that stopped there would wave them through the Cape Town boom.
         */
        $this->approvals()->decide($this->companyId, $this->contractor['uuid'], $this->capeTown,
            ['status' => 'BLOCKED', 'reason' => 'Refused after the scaffold collapse in March.'],
            $this->user->id);

        $identifier = 'CARD-'.Str::upper(Str::random(8));

        DB::table('sheq_contractor_workers')->insert([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'contractor_id' => $this->contractor['id'], 'name' => 'Thabo Mokoena',
            'id_number_hash' => \App\Modules\Operations\Sheq\Access\AccessDecisionService
                ::hashIdentifier($identifier),
            'inducted_on' => Carbon::today()->subMonth()->toDateString(),
            'induction_expires' => Carbon::today()->addYear()->toDateString(),
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $gate = app(\App\Modules\Operations\Sheq\Access\AccessDecisionService::class);

        $atCapeTown = $gate->decide($this->companyId, $identifier, $this->capeTown);
        $atJoburg = $gate->decide($this->companyId, $identifier, $this->joburg);

        $this->assertFalse($atCapeTown['allowed'],
            'The turnstile admitted a worker whose employer is blocked at that site.');
        $this->assertStringContainsString('scaffold collapse',
            implode(' ', $atCapeTown['reasons']));

        // Johannesburg has not decided, which is not a refusal — it is a warning.
        $this->assertTrue($atJoburg['allowed'], implode(' | ', $atJoburg['reasons']));
        $this->assertStringContainsString('has not approved',
            implode(' ', $atJoburg['warnings']));
    }
}
