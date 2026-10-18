<?php

namespace Tests\Feature\Sheq;

use App\Core\User\Models\User;
use App\Modules\Operations\Sheq\Services\ContractorDocumentEngine;
use App\Modules\Operations\Sheq\Services\ContractorPortalService;
use App\Modules\Operations\Sheq\Services\ContractorService;
use App\Modules\Operations\Sheq\Services\SafetyFileWorkflow;
use App\Modules\Operations\Sheq\Services\SiteApprovalService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * What a contractor can see and do about their own compliance (CON-18).
 *
 * The one test in this file that matters more than the rest is
 * `test_an_uploaded_document_never_arrives_accepted`. `ContractorService::attachDocument()`
 * defaults the status to ACCEPTED, because the internal path is a safety officer filing a
 * certificate they have just looked at. The same default reached from the portal would let a
 * contractor clear their own gate by uploading a blank page, which is the one thing CON-18
 * says they must not be able to do.
 *
 * The rest is about the page being useful rather than merely present: a contractor turned away
 * at a boom and told "your file is not in order" needs to see what is outstanding, in words
 * they can act on, with the reviewer's own reason against anything that was refused.
 */
class ContractorPortalTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;

    private User $user;

    private int $supplierId;

    private string $token;

    private array $contractor;

    private int $siteId;

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

        if (! Schema::hasTable('suppliers')) {
            $this->markTestSkipped('Supplier Management is not installed on this instance.');
        }

        $this->companyId = (int) $companyId;
        $this->user = $this->userWith(['sheq.contractor.manage', 'sheq.configure']);

        $this->siteId = (int) DB::table('sheq_sites')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'code' => 'P'.Str::upper(Str::random(5)), 'name' => 'Refinery', 'site_type' => 'PLANT',
            'timezone' => 'Africa/Johannesburg', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('sheq_settings')->updateOrInsert(
            ['company_id' => $this->companyId],
            ['contractor_required_documents' => json_encode(['COIDA_LETTER', 'PUBLIC_LIABILITY']),
                'updated_at' => now()],
        );

        $this->token = Str::random(48);

        $this->supplierId = (int) DB::table('suppliers')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'company_name' => 'Acme Scaffolding (Pty) Ltd',
            'email' => Str::random(8).'@supplier.test',
            'portal_token' => $this->token,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $contractors = app(ContractorService::class);

        $this->contractor = $contractors->register($this->companyId, [
            'name' => 'Acme Scaffolding', 'trade' => 'SCAFFOLDING',
            'supplier_id' => $this->supplierId,
        ], $this->user->id);

        $contractors->refreshAccessStatus($this->companyId, $this->contractor['uuid'], [],
            $this->user->id);
    }

    private function userWith(array $permissions): User
    {
        $userId = DB::table('users')->insertGetId([
            'uuid' => (string) Str::uuid(), 'email' => 'prt-'.Str::random(8).'@example.test',
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

    private function portal(): array
    {
        return app(ContractorPortalService::class)
            ->forSupplier($this->companyId, $this->supplierId) ?? [];
    }

    private function page()
    {
        return $this->get("/supplier-portal/{$this->token}/safety");
    }

    // ── The rule the whole portal turns on ────────────────────────────────────────────────────

    public function test_an_uploaded_document_never_arrives_accepted(): void
    {
        /*
         * `ContractorService::attachDocument()` defaults to ACCEPTED, because internally a
         * safety officer is filing a certificate they have just read. Reaching that default
         * from the portal would let a contractor clear their own gate with a blank page.
         *
         * This is the single assertion CON-18 stands or falls on.
         */
        $this->post("/supplier-portal/{$this->token}/safety/documents", [
            'document_type' => 'COIDA_LETTER',
            'expires_on' => Carbon::today()->addYear()->toDateString(),
            'file' => UploadedFile::fake()->create('coida.pdf', 120, 'application/pdf'),
        ])->assertRedirect()->assertSessionHasNoErrors();

        $document = DB::table('sheq_contractor_documents')
            ->where('company_id', $this->companyId)
            ->where('contractor_id', $this->contractor['id'])
            ->where('document_type', 'COIDA_LETTER')
            ->first(['status']);

        $this->assertNotNull($document, 'The upload did not reach the contractor file.');
        $this->assertSame(ContractorDocumentEngine::SUPPLIED, $document->status,
            'A contractor approved their own document by uploading it.');
    }

    public function test_an_uploaded_document_does_not_open_the_gate_until_somebody_accepts_it(): void
    {
        // The consequence of the rule above, stated as behaviour: a gate is not opened by an
        // upload, which is what a contractor standing at a boom would otherwise assume.
        $this->post("/supplier-portal/{$this->token}/safety/documents", [
            'document_type' => 'COIDA_LETTER',
            'expires_on' => Carbon::today()->addYear()->toDateString(),
            'file' => UploadedFile::fake()->create('coida.pdf', 120, 'application/pdf'),
        ])->assertRedirect();

        $this->assertFalse(app(ContractorService::class)
            ->mayWork($this->companyId, $this->contractor['uuid'])['allowed']);

        // And it shows as waiting rather than as missing, so they do not send it twice.
        $portal = $this->portal();

        $this->assertCount(1, $portal['awaiting_review']);
        $this->assertSame('AWAITING_REVIEW',
            collect($portal['missing'])->firstWhere('type', 'COIDA_LETTER')['state']);
    }

    // ── What they can see ─────────────────────────────────────────────────────────────────────

    public function test_the_three_kinds_of_gap_are_kept_apart(): void
    {
        /*
         * Never sent, sent and awaiting review, and sent-and-lapsed are three different things
         * to the person who has to act. Calling all of them "missing" sends a contractor to
         * re-upload a document that is sitting in somebody's review queue.
         */
        app(ContractorService::class)->attachDocument($this->companyId,
            $this->contractor['uuid'], [
                'document_type' => 'PUBLIC_LIABILITY',
                'expires_on' => Carbon::today()->subDay()->toDateString(),
            ], $this->user->id);

        $missing = collect($this->portal()['missing'])->keyBy('type');

        $this->assertSame('NEVER_SUPPLIED', $missing['COIDA_LETTER']['state']);
        $this->assertSame('EXPIRED', $missing['PUBLIC_LIABILITY']['state']);
        $this->assertSame(Carbon::today()->subDay()->toDateString(),
            $missing['PUBLIC_LIABILITY']['expired_on']);
    }

    public function test_a_rejection_carries_the_reviewers_own_words(): void
    {
        // "Rejected" with nothing else sends the same wrong document back a second time.
        $id = app(ContractorService::class)->attachDocument($this->companyId,
            $this->contractor['uuid'], [
                'document_type' => 'COIDA_LETTER', 'status' => ContractorDocumentEngine::SUPPLIED,
            ], $this->user->id);

        $uuid = DB::table('sheq_contractor_documents')->where('id', $id)->value('uuid');

        app(ContractorService::class)->decideDocument($this->companyId, $uuid,
            ContractorDocumentEngine::REJECTED,
            'The letter is for the wrong registration number.', $this->user->id);

        $rejected = $this->portal()['rejected'];

        $this->assertCount(1, $rejected);
        $this->assertSame('The letter is for the wrong registration number.',
            $rejected[0]['reason']);
    }

    public function test_a_document_about_to_lapse_is_shown_before_it_does(): void
    {
        // The whole point of showing it: a contractor who finds out at a boom has already lost
        // the day.
        app(ContractorService::class)->attachDocument($this->companyId,
            $this->contractor['uuid'], [
                'document_type' => 'COIDA_LETTER',
                'expires_on' => Carbon::today()->addDays(9)->toDateString(),
            ], $this->user->id);

        $expiring = $this->portal()['expiring'];

        $this->assertCount(1, $expiring);
        $this->assertSame(9, $expiring[0]['days_left']);
    }

    public function test_the_compliance_figure_shows_its_arithmetic(): void
    {
        /*
         * A percentage nobody can explain invites "why is it 73" and there is no answer. The
         * parts are returned so the page can show what it is counting.
         */
        app(ContractorService::class)->attachDocument($this->companyId,
            $this->contractor['uuid'], [
                'document_type' => 'COIDA_LETTER',
                'expires_on' => Carbon::today()->addYear()->toDateString(),
            ], $this->user->id);

        $score = $this->portal()['score'];

        $this->assertSame(2, $score['total']);
        $this->assertSame(1, $score['met']);
        $this->assertSame(50, $score['percent']);
        $this->assertCount(2, $score['parts']);
    }

    public function test_a_contractor_asked_for_nothing_reads_as_complete(): void
    {
        // Honest rather than flattering: it means this host has not asked them for anything,
        // which the page says in words beside the figure.
        DB::table('sheq_settings')->where('company_id', $this->companyId)
            ->update(['contractor_required_documents' => json_encode([])]);

        $this->assertSame(100, $this->portal()['score']['percent']);
    }

    public function test_the_safety_file_verdict_reaches_them_with_the_reason(): void
    {
        /*
         * The submission is written directly rather than driven through the workflow. Reaching
         * CHANGES_REQUIRED legitimately means satisfying six safety-file sections first, which
         * is a test of the workflow's own guard — and it has one, in its own suite. What is
         * under test here is that the verdict and the reviewer's words reach the contractor.
         */
        DB::table('sheq_safety_file_submissions')->insert([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'contractor_id' => $this->contractor['id'], 'version' => 1,
            'state' => SafetyFileWorkflow::CHANGES_REQUIRED,
            'submitted_by' => $this->user->id, 'submitted_at' => now(),
            'reviewer_id' => $this->user->id, 'reviewed_at' => now(),
            'decision_reason' => 'Section 3 is missing the appointment letters.',
            'requested_changes' => json_encode(['Appointment letters for the site supervisor.']),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $file = $this->portal()['safety_file'];

        $this->assertSame(SafetyFileWorkflow::CHANGES_REQUIRED, $file['state']);
        $this->assertStringContainsString('appointment letters',
            (string) $file['decision_reason']);

        // And the itemised changes, which are what they actually act on.
        $this->assertSame(['Appointment letters for the site supervisor.'],
            $file['requested_changes']);
    }

    public function test_their_people_are_listed_with_whether_they_are_inducted(): void
    {
        // The same columns in both rows: Postgres refuses a multi-row insert whose value
        // lists differ in length, so the uninducted worker carries explicit nulls.
        DB::table('sheq_contractor_workers')->insert([
            ['uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
                'contractor_id' => $this->contractor['id'], 'name' => 'Thabo Mokoena',
                'inducted_on' => Carbon::today()->subMonth()->toDateString(),
                'induction_expires' => Carbon::today()->addYear()->toDateString(),
                'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
                'contractor_id' => $this->contractor['id'], 'name' => 'Nomsa Dlamini',
                'inducted_on' => null, 'induction_expires' => null,
                'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $workers = collect($this->portal()['workers'])->keyBy('name');

        $this->assertTrue($workers['Thabo Mokoena']['inducted']);
        $this->assertFalse($workers['Nomsa Dlamini']['inducted'],
            'A worker with no induction at all read as inducted.');
    }

    public function test_site_decisions_reach_them_with_the_reason_they_were_refused(): void
    {
        /*
         * A contractor who cannot see why a site refused them can neither fix it nor dispute
         * it, and the usual outcome is a telephone call to somebody who also cannot see it.
         */
        app(SiteApprovalService::class)->decide($this->companyId, $this->contractor['uuid'],
            $this->siteId, [
                'status' => 'BLOCKED',
                'reason' => 'Refused after the scaffold collapse in March.',
            ], $this->user->id);

        $sites = $this->portal()['sites'];

        $this->assertCount(1, $sites);
        $this->assertSame('BLOCKED', $sites[0]['status']);
        $this->assertStringContainsString('scaffold collapse', $sites[0]['reason']);
    }

    public function test_only_sites_with_a_decision_are_listed(): void
    {
        // Listing every site a host owns and marking most "not decided" reads as a list of
        // places the contractor is being refused, when nobody has been asked about them.
        $this->assertSame([], $this->portal()['sites']);
    }

    public function test_actions_owed_by_this_contractor_are_shown(): void
    {
        // `sheq_actions` had no way to say the party responsible is a contractor, so the portal
        // could only ever show an empty list — which a contractor reads as confirmation that
        // nothing is outstanding.
        DB::table('sheq_actions')->insert([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'reference' => 'ACT-'.Str::upper(Str::random(6)),
            'title' => 'Replace the damaged scaffold boards on level 3.',
            'contractor_id' => $this->contractor['id'],
            'due_date' => Carbon::today()->subDays(2)->toDateString(),
            'original_due_date' => Carbon::today()->subDays(2)->toDateString(),
            'status' => 'OPEN', 'priority' => 'HIGH',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $actions = $this->portal()['actions'];

        $this->assertCount(1, $actions);
        $this->assertTrue($actions[0]['overdue']);
    }

    public function test_a_completed_action_is_not_shown_as_outstanding(): void
    {
        DB::table('sheq_actions')->insert([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'reference' => 'ACT-'.Str::upper(Str::random(6)),
            'title' => 'Already done.', 'contractor_id' => $this->contractor['id'],
            'due_date' => Carbon::today()->toDateString(),
            'original_due_date' => Carbon::today()->toDateString(),
            'status' => 'COMPLETED', 'priority' => 'LOW',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertSame([], $this->portal()['actions']);
    }

    public function test_only_the_types_this_host_wants_are_offered(): void
    {
        // Offering all twenty-eight invites uploads nobody asked for, which then sit in a
        // review queue making it look longer than it is.
        $offered = collect($this->portal()['documentTypes'])->pluck('value');

        $this->assertEqualsCanonicalizing(['COIDA_LETTER', 'PUBLIC_LIABILITY'], $offered->all());
    }

    // ── The page ──────────────────────────────────────────────────────────────────────────────

    public function test_the_page_opens_on_the_token_with_no_login(): void
    {
        /*
         * Public by necessity, like the supplier portal it lives in: the holder has no account
         * here. There is deliberately no second SHEQ login — one contractor with two
         * credentials for the same customer is one credential they will lose.
         */
        $props = $this->page()->assertOk()->viewData('page')['props'];

        $this->assertSame('Acme Scaffolding', $props['compliance']['contractor']['name']);
        $this->assertNotNull($props['hostName']);
    }

    public function test_a_supplier_who_is_not_a_contractor_is_told_so_plainly(): void
    {
        // An empty safety page reads as something having gone wrong, and invites a telephone
        // call asking what they have done.
        $token = Str::random(48);

        DB::table('suppliers')->insert([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'company_name' => 'Stationery Supplies', 'portal_token' => $token,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $props = $this->get("/supplier-portal/{$token}/safety")->assertOk()
            ->viewData('page')['props'];

        $this->assertNull($props['compliance']);
    }

    public function test_an_unknown_token_is_not_found(): void
    {
        $this->get('/supplier-portal/'.Str::random(48).'/safety')->assertNotFound();
    }

    public function test_an_expired_link_says_so_rather_than_404(): void
    {
        /*
         * They mean different things to the person holding the link: "this address is wrong"
         * sends them looking for a typo, "this link has expired" sends them to ask for a new
         * one.
         */
        $token = Str::random(48);

        DB::table('suppliers')->insert([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'company_name' => 'Lapsed Link Ltd', 'portal_token' => $token,
            'portal_token_expires_at' => Carbon::now()->subDay(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->get("/supplier-portal/{$token}/safety")->assertStatus(410);
    }

    public function test_the_supplier_portal_links_to_the_safety_page_only_for_contractors(): void
    {
        // An engine with no door otherwise, and a link a stationery supplier cannot use is an
        // invitation to telephone somebody.
        $props = $this->get("/supplier-portal/{$this->token}")->assertOk()
            ->viewData('page')['props'];

        $this->assertTrue($props['safetyPortal']);

        $token = Str::random(48);

        DB::table('suppliers')->insert([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'company_name' => 'Stationery Supplies', 'portal_token' => $token,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertFalse($this->get("/supplier-portal/{$token}")->assertOk()
            ->viewData('page')['props']['safetyPortal']);
    }

    // ── What they may send ────────────────────────────────────────────────────────────────────

    public function test_an_already_expired_certificate_is_refused_on_the_way_in(): void
    {
        // It is not a replacement. Accepting it puts a document in the review queue that will
        // be rejected on sight, which wastes the reviewer's time and the contractor's.
        $this->post("/supplier-portal/{$this->token}/safety/documents", [
            'document_type' => 'COIDA_LETTER',
            'expires_on' => Carbon::today()->subDay()->toDateString(),
            'file' => UploadedFile::fake()->create('old.pdf', 50, 'application/pdf'),
        ])->assertSessionHasErrors('expires_on');
    }

    public function test_an_unrecognised_document_type_is_refused(): void
    {
        // A free-text code would let a contractor file against a type nobody reviews, which
        // then sits looking like progress.
        $this->post("/supplier-portal/{$this->token}/safety/documents", [
            'document_type' => 'SOMETHING_NOBODY_ASKED_FOR',
            'file' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf'),
        ])->assertSessionHasErrors('document_type');
    }

    public function test_an_executable_is_refused(): void
    {
        $this->post("/supplier-portal/{$this->token}/safety/documents", [
            'document_type' => 'COIDA_LETTER',
            'file' => UploadedFile::fake()->create('payload.exe', 10, 'application/x-msdownload'),
        ])->assertSessionHasErrors('file');
    }

    public function test_an_upload_lands_in_the_right_tenant(): void
    {
        /*
         * Nothing is signed in, so no company is set and RLS has only the token to go on. A
         * document that landed with a null company would be invisible to the host under RLS —
         * the contractor would see it sent and the reviewer would never see it arrive.
         */
        $this->post("/supplier-portal/{$this->token}/safety/documents", [
            'document_type' => 'PUBLIC_LIABILITY',
            'expires_on' => Carbon::today()->addYear()->toDateString(),
            'file' => UploadedFile::fake()->create('liability.pdf', 90, 'application/pdf'),
        ])->assertRedirect();

        $this->assertSame(1, DB::table('sheq_contractor_documents')
            ->where('company_id', $this->companyId)
            ->where('contractor_id', $this->contractor['id'])
            ->where('document_type', 'PUBLIC_LIABILITY')->count());
    }

    public function test_one_contractor_cannot_reach_another_through_the_portal(): void
    {
        /*
         * The token identifies a supplier, and the supplier identifies the contractor. A second
         * supplier's token must not reach the first one's file — the whole tenancy of this page
         * rests on that lookup being scoped.
         */
        $otherToken = Str::random(48);

        $otherSupplier = (int) DB::table('suppliers')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'company_name' => 'Bravo Rigging', 'portal_token' => $otherToken,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        app(ContractorService::class)->register($this->companyId, [
            'name' => 'Bravo Rigging', 'supplier_id' => $otherSupplier,
        ], $this->user->id);

        $props = $this->get("/supplier-portal/{$otherToken}/safety")->assertOk()
            ->viewData('page')['props'];

        $this->assertSame('Bravo Rigging', $props['compliance']['contractor']['name']);
    }
}
