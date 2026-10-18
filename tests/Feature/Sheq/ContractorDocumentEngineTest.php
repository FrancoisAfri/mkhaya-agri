<?php

namespace Tests\Feature\Sheq;

use App\Core\User\Models\User;
use App\Modules\Operations\Sheq\Services\ContractorDocumentEngine;
use App\Modules\Operations\Sheq\Services\ContractorService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The contractor document expiry engine (CON-8).
 *
 * Expiry on its own is a date comparison. What makes this an engine is the three questions around
 * it — when it should warn, where it applies, and what it is for — and the guarantee that the same
 * notification gate never fires twice.
 */
class ContractorDocumentEngineTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;

    private User $user;

    private array $contractor;

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
        $this->contractor = app(ContractorService::class)->register($this->companyId, [
            'name' => 'Acme Scaffolding', 'trade' => 'SCAFFOLDING',
            'contact_email' => 'site@acme.test',
        ], $this->user->id);
    }

    private function userWith(array $permissions): User
    {
        $userId = DB::table('users')->insertGetId([
            'uuid' => (string) Str::uuid(), 'email' => 'cde-'.Str::random(8).'@example.test',
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

    private function engine(): ContractorDocumentEngine
    {
        return app(ContractorDocumentEngine::class);
    }

    private function file(array $attributes = []): object
    {
        $this->actingAs($this->user)
            ->post("/sheq/contractors/{$this->contractor['uuid']}/documents",
                array_merge(['document_type' => 'COIDA_LETTER'], $attributes))
            ->assertRedirect()->assertSessionHasNoErrors();

        return DB::table('sheq_contractor_documents')
            ->where('company_id', $this->companyId)->orderByDesc('id')->first();
    }

    private function requireDocuments(array $types): void
    {
        DB::table('sheq_settings')->where('company_id', $this->companyId)->exists()
            ? DB::table('sheq_settings')->where('company_id', $this->companyId)
                ->update(['contractor_required_documents' => json_encode($types)])
            : DB::table('sheq_settings')->insert([
                'company_id' => $this->companyId,
                'contractor_required_documents' => json_encode($types),
                'created_at' => now(), 'updated_at' => now(),
            ]);
    }

    // ── Warning period ────────────────────────────────────────────────────────────────────────

    public function test_a_document_warns_on_its_own_window_not_a_global_one(): void
    {
        // Ninety days' notice, because this one is renewed through a broker.
        $document = $this->file([
            'warning_days' => 90,
            'expires_on' => Carbon::today()->addDays(75)->toDateString(),
        ]);

        $state = $this->engine()->stateOf($document, ContractorDocumentEngine::DEFAULT_WARNING_DAYS);

        /*
         * Seventy-five days out is well outside the thirty-day default and inside this document's
         * own window. A single global window either nags about everything or warns too late about
         * the thing that takes longest to replace.
         */
        $this->assertTrue($state['warning']);
        $this->assertSame(90, $state['warns_at']);
    }

    public function test_a_document_with_no_window_of_its_own_uses_the_tenants_default(): void
    {
        DB::table('sheq_settings')->updateOrInsert(
            ['company_id' => $this->companyId],
            ['contractor_document_warning_days' => 60, 'updated_at' => now()],
        );

        $document = $this->file(['expires_on' => Carbon::today()->addDays(45)->toDateString()]);

        $state = $this->engine()->stateOf($document, $this->engine()->defaultWarningDays($this->companyId));

        // Null means "the default applies", so moving the default moves every document that never
        // had an opinion of its own.
        $this->assertNull($document->warning_days);
        $this->assertSame(60, $state['warns_at']);
        $this->assertTrue($state['warning']);
    }

    // ── Site and activity applicability ───────────────────────────────────────────────────────

    private function site(string $name): int
    {
        return (int) DB::table('sheq_sites')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'code' => 'S'.Str::random(5), 'name' => $name, 'site_type' => 'SITE',
            'timezone' => 'Africa/Johannesburg', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_a_site_document_applies_at_its_site_and_not_the_others(): void
    {
        $refinery = $this->site('Refinery');
        $depot = $this->site('Depot');

        $document = $this->file(['site_id' => $refinery, 'document_type' => 'SITE_SPECIFIC']);

        /*
         * A contractor working at four sites holds one COID letter and four site approvals.
         * Demanding the site document at every gate blocks them at three sites for no reason, and
         * the practitioner's answer to that is to stop recording site documents at all.
         */
        $this->assertTrue($this->engine()->appliesTo($document, $refinery));
        $this->assertFalse($this->engine()->appliesTo($document, $depot));
    }

    public function test_a_document_with_no_site_applies_everywhere(): void
    {
        $document = $this->file();

        // Every document filed before these columns existed has a null site, and the safe reading
        // of silence for a compliance document is that it applies.
        $this->assertNull($document->site_id);
        $this->assertTrue($this->engine()->appliesTo($document, $this->site('Anywhere')));
    }

    public function test_an_activity_document_applies_to_its_activity_only(): void
    {
        $document = $this->file([
            'document_type' => 'EQUIPMENT_CERTIFICATE',
            'activities' => ['LIFTING', 'RIGGING'],
        ]);

        // A rigging ticket matters for lifting and is irrelevant to painting. Without this the
        // file cannot say "they may paint but not lift", which is the permit issuer's question.
        $this->assertTrue($this->engine()->appliesTo($document, null, 'LIFTING'));
        $this->assertTrue($this->engine()->appliesTo($document, null, 'rigging'));
        $this->assertFalse($this->engine()->appliesTo($document, null, 'PAINTING'));
    }

    public function test_asking_without_naming_an_activity_gets_every_document(): void
    {
        $document = $this->file(['activities' => ['LIFTING']]);

        // A gate asking "is this contractor compliant at all" must not be answered as though no
        // activity were ever required.
        $this->assertTrue($this->engine()->appliesTo($document, null, null));
    }

    public function test_a_site_document_does_not_satisfy_the_requirement_at_another_site(): void
    {
        $refinery = $this->site('Refinery');
        $depot = $this->site('Depot');

        $this->requireDocuments(['SITE_SPECIFIC']);

        // A host that demands a site approval blocks on it. The rule ships off, because on the day
        // it shipped nobody had filed one — so the test says which host this is.
        app(\App\Modules\Operations\Sheq\Services\ContractorComplianceStatus::class)
            ->saveRules($this->companyId, ['MISSING_SITE_APPROVAL' => true], $this->user->id);

        $this->file([
            'document_type' => 'SITE_SPECIFIC', 'site_id' => $refinery,
            'expires_on' => Carbon::today()->addYear()->toDateString(),
        ]);

        $service = app(ContractorService::class);

        /*
         * Recorded and never read is the failure here. The applicability columns existed and the
         * compliance check ignored them, so a contractor with a Refinery-only approval read as
         * approved at the Depot — which is precisely what the column exists to prevent.
         */
        $this->assertTrue($service->mayWork($this->companyId, $this->contractor['uuid'], [],
            $refinery)['allowed']);
        $this->assertFalse($service->mayWork($this->companyId, $this->contractor['uuid'], [],
            $depot)['allowed']);
    }

    public function test_asking_about_the_contractor_generally_counts_every_document(): void
    {
        $refinery = $this->site('Refinery');
        $this->requireDocuments(['SITE_SPECIFIC']);

        $this->file([
            'document_type' => 'SITE_SPECIFIC', 'site_id' => $refinery,
            'expires_on' => Carbon::today()->addYear()->toDateString(),
        ]);

        app(\App\Modules\Operations\Sheq\Services\ContractorComplianceStatus::class)
            ->saveRules($this->companyId, ['MISSING_SITE_APPROVAL' => true], $this->user->id);

        // "Is this contractor in order at all" is a different question from "may they work here",
        // and answering the first as though no site document counted would block everybody.
        $this->assertTrue(app(ContractorService::class)
            ->mayWork($this->companyId, $this->contractor['uuid'])['allowed']);
    }

    public function test_an_activity_document_does_not_satisfy_other_work(): void
    {
        $this->requireDocuments(['EQUIPMENT_CERTIFICATE']);

        $this->file([
            'document_type' => 'EQUIPMENT_CERTIFICATE', 'activities' => ['LIFTING'],
            'expires_on' => Carbon::today()->addYear()->toDateString(),
        ]);

        $service = app(ContractorService::class);

        // They may lift but not paint — the load-test certificate says nothing about painting.
        $this->assertTrue($service->mayWork($this->companyId, $this->contractor['uuid'], [],
            null, 'LIFTING')['allowed']);
        $this->assertFalse($service->mayWork($this->companyId, $this->contractor['uuid'], [],
            null, 'PAINTING')['allowed']);
    }

    // ── Status ────────────────────────────────────────────────────────────────────────────────

    public function test_a_supplied_document_does_not_satisfy_a_requirement_until_it_is_accepted(): void
    {
        $this->requireDocuments(['COIDA_LETTER']);

        $document = $this->file([
            'status' => ContractorDocumentEngine::SUPPLIED,
            'expires_on' => Carbon::today()->addYear()->toDateString(),
        ]);

        $file = app(ContractorService::class)
            ->complianceFile($this->companyId, $this->contractor['uuid']);

        /*
         * Counting an unchecked certificate as compliance is how an unverified document satisfies
         * a requirement. And the reason is reported as NOT_ACCEPTED rather than MISSING, because
         * sending somebody to chase a document that is sitting in the system is how a gate loses
         * credibility.
         */
        $this->assertSame('BLOCKED', $file['status']);
        $this->assertSame('NOT_ACCEPTED', $file['blocking'][0]['reason']);
        $this->assertStringContainsString('not yet accepted', $file['blocking'][0]['detail']);
    }

    public function test_accepting_it_makes_it_count_immediately(): void
    {
        $this->requireDocuments(['COIDA_LETTER']);

        $document = $this->file([
            'status' => ContractorDocumentEngine::SUPPLIED,
            'expires_on' => Carbon::today()->addYear()->toDateString(),
        ]);

        $this->actingAs($this->user)
            ->post("/sheq/contractors/{$this->contractor['uuid']}/documents/{$document->uuid}/decide",
                ['decision' => 'ACCEPTED'])
            ->assertRedirect()->assertSessionHasNoErrors();

        // Accepting the last outstanding certificate is exactly the moment somebody is standing at
        // the gate, so the status moves in the same breath.
        $this->assertSame('COMPLIANT', DB::table('sheq_contractors')
            ->where('uuid', $this->contractor['uuid'])->value('access_status'));
    }

    public function test_a_rejected_document_stops_counting(): void
    {
        $this->requireDocuments(['COIDA_LETTER']);

        $document = $this->file(['expires_on' => Carbon::today()->addYear()->toDateString()]);

        $this->assertSame('COMPLIANT', DB::table('sheq_contractors')
            ->where('uuid', $this->contractor['uuid'])->value('access_status'));

        $this->actingAs($this->user)
            ->post("/sheq/contractors/{$this->contractor['uuid']}/documents/{$document->uuid}/decide",
                ['decision' => 'REJECTED', 'reason' => 'Issued to a different legal entity.'])
            ->assertRedirect();

        // If a rejected document still counted, rejecting it would have no effect on the file it
        // was rejected from.
        $this->assertSame('BLOCKED', DB::table('sheq_contractors')
            ->where('uuid', $this->contractor['uuid'])->value('access_status'));
    }

    public function test_rejecting_needs_a_reason_the_contractor_can_act_on(): void
    {
        $document = $this->file();

        $this->actingAs($this->user)
            ->post("/sheq/contractors/{$this->contractor['uuid']}/documents/{$document->uuid}/decide",
                ['decision' => 'REJECTED'])
            ->assertSessionHasErrors('reason');

        $this->assertSame('ACCEPTED', DB::table('sheq_contractor_documents')
            ->where('uuid', $document->uuid)->value('status'));
    }

    public function test_a_decision_is_audited(): void
    {
        $document = $this->file();

        $this->actingAs($this->user)
            ->post("/sheq/contractors/{$this->contractor['uuid']}/documents/{$document->uuid}/decide",
                ['decision' => 'REJECTED', 'reason' => 'Expired before it was even supplied.']);

        $entry = DB::table('sheq_audit_log')
            ->where('company_id', $this->companyId)
            ->where('action', 'contractor.document_rejected')
            ->orderByDesc('id')->first();

        $this->assertNotNull($entry);
        $this->assertSame($this->user->id, (int) $entry->actor_id);
        $this->assertStringContainsString('Expired before', (string) $entry->reason);
    }

    public function test_deciding_needs_the_contractor_entitlement(): void
    {
        $document = $this->file();

        $this->actingAs($this->userWith([]))
            ->post("/sheq/contractors/{$this->contractor['uuid']}/documents/{$document->uuid}/decide",
                ['decision' => 'ACCEPTED'])
            ->assertForbidden();
    }

    // ── Version and replacement chain ─────────────────────────────────────────────────────────

    public function test_a_renewal_is_the_next_version_and_names_what_it_replaces(): void
    {
        $first = $this->file(['expires_on' => Carbon::today()->addMonth()->toDateString()]);
        $second = $this->file(['expires_on' => Carbon::today()->addYear()->toDateString()]);

        // A renewal is version 2 of the same obligation, not an unrelated second row. The chain is
        // what lets a screen say "renewed twice" instead of listing three letters in no order.
        $this->assertSame(1, (int) $first->version);
        $this->assertSame(2, (int) $second->version);
        $this->assertSame((int) $first->id, (int) $second->replaces_document_id);
    }

    public function test_a_different_document_type_starts_its_own_version_chain(): void
    {
        $this->file();
        $other = $this->file(['document_type' => 'PUBLIC_LIABILITY']);

        $this->assertSame(1, (int) $other->version);
        $this->assertNull($other->replaces_document_id);
    }

    // ── The notification gates ────────────────────────────────────────────────────────────────

    private function reminders(): \Illuminate\Support\Collection
    {
        return DB::table('sheq_notification_dispatches')
            ->where('company_id', $this->companyId)
            ->where('event_type', 'sheq.contractor.document_expiring')
            ->get();
    }

    private function sweep(): void
    {
        $this->artisan('sheq:reminders:send', ['--company' => $this->companyId])
            ->assertExitCode(0);
    }

    public function test_the_same_gate_never_alerts_twice(): void
    {
        $this->file([
            'warning_days' => 90,
            'expires_on' => Carbon::today()->addDays(30)->toDateString(),
        ]);

        $this->sweep();
        $this->sweep();
        $this->sweep();

        // CON-8 is explicit about this, and a contractor chased three times in an afternoon stops
        // reading the sender.
        $this->assertCount(1, $this->reminders());
    }

    public function test_each_gate_is_its_own_alert_as_the_expiry_approaches(): void
    {
        $document = $this->file([
            'warning_days' => 90,
            'expires_on' => Carbon::today()->addDays(60)->toDateString(),
        ]);

        $this->sweep();
        $this->assertCount(1, $this->reminders());

        // Time passes: the 30-day gate comes due. Moving the expiry nearer is the same thing as
        // the calendar advancing, from the ladder's point of view.
        DB::table('sheq_contractor_documents')->where('id', $document->id)
            ->update(['expires_on' => Carbon::today()->addDays(30)->toDateString()]);

        $this->sweep();
        $this->assertCount(2, $this->reminders());
    }

    public function test_a_document_filed_late_warns_once_at_the_gate_it_has_reached(): void
    {
        // Filed eight days before expiry, with a ninety-day window. It has passed 90, 60, 30 and
        // 14 without ever being alerted.
        $this->file([
            'warning_days' => 90,
            'expires_on' => Carbon::today()->addDays(8)->toDateString(),
        ]);

        $this->sweep();

        // One message, at the tightest gate reached — not four, which is how a ladder turns into
        // a mail filter rule.
        $this->assertCount(1, $this->reminders());
        $this->assertStringContainsString('8 days',
            json_decode((string) $this->reminders()->first()->payload, true)['body']);
    }

    public function test_a_renewal_starts_a_fresh_ladder(): void
    {
        $document = $this->file(['expires_on' => Carbon::today()->addDays(20)->toDateString()]);

        $this->sweep();
        $this->assertCount(1, $this->reminders());

        // A new expiry is a new cycle. Keyed on the gate alone, the renewal's warnings would be
        // suppressed by the alerts sent for the document it replaced.
        DB::table('sheq_contractor_documents')->where('id', $document->id)
            ->update(['expires_on' => Carbon::today()->addDays(25)->toDateString()]);

        $this->sweep();
        $this->assertCount(2, $this->reminders());
    }

    public function test_a_document_outside_every_gate_is_not_chased(): void
    {
        $this->file(['expires_on' => Carbon::today()->addMonths(10)->toDateString()]);

        $this->sweep();

        // Chasing a certificate ten months out teaches a contractor to filter the sender.
        $this->assertCount(0, $this->reminders());
    }

    public function test_a_document_that_never_expires_is_never_chased(): void
    {
        $this->file(['document_type' => 'HS_POLICY']);

        $this->sweep();

        $this->assertCount(0, $this->reminders());
    }

    public function test_a_lapsed_document_alerts_on_the_expiry_gate_and_then_stops(): void
    {
        $this->file(['expires_on' => Carbon::today()->subDays(3)->toDateString()]);

        $this->sweep();
        $this->sweep();

        // Past expiry the ladder has said its last word; after that it is the compliance status's
        // problem, not a notification's.
        $this->assertCount(1, $this->reminders());
        $this->assertSame(0, (int) DB::table('sheq_contractor_document_alerts')
            ->where('company_id', $this->companyId)->value('gate'));
    }

    public function test_a_mandatory_document_inside_a_week_is_urgent(): void
    {
        $this->file([
            'is_mandatory' => true,
            'expires_on' => Carbon::today()->addDays(5)->toDateString(),
        ]);

        $this->sweep();

        $this->assertSame('HIGH', $this->reminders()->first()->severity);
    }

    public function test_the_gate_claim_is_scoped_to_the_tenant(): void
    {
        $this->file(['expires_on' => Carbon::today()->addDays(20)->toDateString()]);
        $this->sweep();

        $this->assertSame(1, DB::table('sheq_contractor_document_alerts')
            ->where('company_id', $this->companyId)->count());
    }
}
