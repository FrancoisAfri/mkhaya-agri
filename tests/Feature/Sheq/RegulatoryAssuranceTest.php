<?php

namespace Tests\Feature\Sheq;

use App\Core\User\Models\User;
use App\Modules\Operations\Sheq\Regulatory\PackAssurance;
use App\Modules\Operations\Sheq\Regulatory\PackManifest;
use App\Modules\Operations\Sheq\Regulatory\SheqReadiness;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Regulatory pack integrity, the legal review gate, and installation readiness
 * (REG-FIX-1, REG-FIX-2, REG-FIX-4, REG-FIX-5).
 *
 * A pack decides whether an incident is reportable to the Department of Employment and Labour
 * within seven days. It is the most consequential content in the module and the least visible:
 * nobody opens it, everybody relies on it. So the tests here are about what can be running as the
 * authority without anybody having checked it.
 *
 * The one hard line is tampering. Everything else — unsigned, unreviewed, not yet effective — is
 * acknowledgeable, and that is a deliberate choice rather than a weak one: a hard block on
 * unreviewed packs would stop every existing installation reporting incidents the day it shipped
 * and would be worked around within a week, after which the gate exists and means nothing.
 */
class RegulatoryAssuranceTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;

    private User $user;

    private int $versionId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        $companyId = DB::table('companies')->min('id');

        if ($companyId === null) {
            $this->markTestSkipped('No company in the test database.');
        }

        $this->companyId = (int) $companyId;
        $this->user = $this->userWith(['sheq.pack.manage']);
        $this->versionId = $this->packVersion();
    }

    private function assurance(): PackAssurance
    {
        return app(PackAssurance::class);
    }

    // ── REG-FIX-4 ─────────────────────────────────────────────────────────────────────────────

    public function test_a_pack_whose_contents_no_longer_match_its_checksum_is_blocked(): void
    {
        /*
         * The realistic attacker on a self-hosted install is somebody with psql and a deadline,
         * and the realistic edit is to a classification rule — making a reportable incident
         * stop being reportable. The checksum and the manifest are two columns on the same row,
         * so recomputing one from the other catches an edit to either.
         */
        $before = $this->assurance()->assess($this->versionId);

        $this->assertTrue($before['may_activate_accepted']);
        $this->assertNotContains(PackAssurance::TAMPERED, $before['blocking']);

        $manifest = json_decode((string) DB::table('sheq_regulatory_pack_versions')
            ->where('id', $this->versionId)->value('manifest'), true);

        $manifest['classification_rules'][] = ['id' => 'smuggled', 'then' => ['reportable' => false]];

        DB::table('sheq_regulatory_pack_versions')->where('id', $this->versionId)
            ->update(['manifest' => json_encode($manifest)]);

        $after = $this->assurance()->assess($this->versionId);

        $this->assertContains(PackAssurance::TAMPERED, $after['blocking'],
            'A pack whose rules were edited in the database still reads as intact, so somebody '
            .'with psql can decide what is reportable.');
        $this->assertFalse($after['may_activate_accepted']);
    }

    public function test_tampering_is_the_only_gap_that_cannot_be_acknowledged(): void
    {
        foreach (PackAssurance::GAPS as $code => $gap) {
            $this->assertArrayHasKey('blocking', $gap);
            $this->assertNotSame('', trim($gap['label']));
            $this->assertGreaterThan(40, strlen($gap['detail']),
                "The {$code} gap has no explanation, so a compliance warning appears that nobody "
                .'can act on — which is a warning that gets dismissed.');

            if ($code === PackAssurance::TAMPERED) {
                $this->assertTrue($gap['blocking']);
            } else {
                $this->assertFalse($gap['blocking'],
                    "The {$code} gap blocks activation outright. A hard block on this would stop "
                    .'existing installations working and be routed around within a week.');
            }
        }
    }

    public function test_release_provenance_is_recorded_against_the_version(): void
    {
        $this->assurance()->recordRelease($this->versionId, [
            'publisher' => 'Mkhaya Compliance',
            'published_on' => '2026-01-15',
            'source_instrument' => 'Occupational Health and Safety Act 85 of 1993',
            'source_reference' => 'GN R.1010 of 2026',
            'changelog' => 'Section 24 thresholds updated.',
        ], $this->user->id);

        $metadata = $this->assurance()->assess($this->versionId)['release_metadata'];

        // REG-FIX-4's "release metadata": where these rules came from, which had no answer
        // beyond a filename.
        $this->assertSame('Mkhaya Compliance', $metadata['publisher']);
        $this->assertSame('GN R.1010 of 2026', $metadata['source_reference']);
        $this->assertStringContainsString('85 of 1993', $metadata['source_instrument']);
    }

    // ── REG-FIX-5 ─────────────────────────────────────────────────────────────────────────────

    public function test_an_unreviewed_pack_is_reported_as_such(): void
    {
        $result = $this->assurance()->assess($this->versionId);

        $this->assertContains(PackAssurance::NOT_LEGALLY_REVIEWED, $result['acknowledgeable']);
        $this->assertNull($result['legal_review']);
        $this->assertTrue($result['requires_acknowledgement'],
            'An unreviewed pack can become the live authority with nobody noticing.');
    }

    public function test_a_legal_review_needs_a_name_and_a_capacity(): void
    {
        /*
         * "Reviewed by J Smith" is worth very little to a customer's legal department. "Reviewed
         * by J Smith, admitted attorney, occupational health and safety" is what they ask for.
         */
        foreach ([
            ['reviewer_name' => 'J Smith'],
            ['reviewer_capacity' => 'Admitted attorney'],
            [],
        ] as $incomplete) {
            try {
                $this->assurance()->recordLegalReview($this->versionId, $incomplete,
                    $this->user->id);

                $this->fail('A legal review was accepted without a name or a capacity.');
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('not evidence', $e->getMessage());
            }
        }
    }

    public function test_recording_a_review_clears_the_gap(): void
    {
        $this->assurance()->recordLegalReview($this->versionId, [
            'reviewer_name' => 'J Smith',
            'reviewer_capacity' => 'Admitted attorney, occupational health and safety',
            'reference' => 'OP-2026-114',
            'valid_until' => Carbon::today()->addYear()->toDateString(),
        ], $this->user->id);

        $result = $this->assurance()->assess($this->versionId);

        $this->assertNotContains(PackAssurance::NOT_LEGALLY_REVIEWED, $result['acknowledgeable']);
        $this->assertSame('J Smith', $result['legal_review']['reviewer_name']);
        $this->assertStringContainsString('attorney', $result['legal_review']['reviewer_capacity']);

        /*
         * Logged against the platform, not a tenant. `sheq_audit_log.company_id` is NOT NULL and
         * correctly so — that log is tenant evidence — whereas a pack version is shared product
         * content governing everybody who activates it, so its review belongs in the
         * platform-wide `activity_log`.
         */
        $this->assertTrue(DB::table('activity_log')
            ->whereNull('company_id')
            ->where('log_name', 'sheq.regulatory')
            ->where('description', 'PACK_LEGAL_REVIEW_RECORDED')
            ->where('subject_id', $this->versionId)->exists(),
            'Recording a legal review is not logged, so the one piece of evidence that the '
            .'statutory content was checked has no provenance of its own.');
    }

    public function test_an_expired_review_counts_as_no_review(): void
    {
        /*
         * Regulations are amended. A 2024 review of a pack does not cover a 2027 amendment, and
         * a review with no expiry that silently stands for ever is the version of this control
         * that provides false comfort.
         */
        $this->assurance()->recordLegalReview($this->versionId, [
            'reviewer_name' => 'J Smith', 'reviewer_capacity' => 'Admitted attorney',
            'valid_until' => Carbon::today()->subDay()->toDateString(),
        ], $this->user->id);

        $result = $this->assurance()->assess($this->versionId);

        $this->assertContains(PackAssurance::REVIEW_EXPIRED, $result['acknowledgeable'],
            'An expired legal review reads as a current one.');
        $this->assertTrue($result['requires_acknowledgement']);
    }

    public function test_a_review_with_no_expiry_stands(): void
    {
        // Not every review is time-limited, and forcing an expiry would make somebody invent one.
        $this->assurance()->recordLegalReview($this->versionId, [
            'reviewer_name' => 'J Smith', 'reviewer_capacity' => 'Admitted attorney',
        ], $this->user->id);

        $result = $this->assurance()->assess($this->versionId);

        $this->assertNotContains(PackAssurance::REVIEW_EXPIRED, $result['acknowledgeable']);
    }

    public function test_the_effective_window_is_assessed(): void
    {
        DB::table('sheq_regulatory_pack_versions')->where('id', $this->versionId)
            ->update(['effective_from' => Carbon::today()->addMonth()->toDateString()]);

        $this->assertContains(PackAssurance::NOT_YET_EFFECTIVE,
            $this->assurance()->assess($this->versionId)['acknowledgeable'],
            'A pack that comes into force next month can be activated today with nothing said, '
            .'so incidents are classified against rules that are not yet law.');

        DB::table('sheq_regulatory_pack_versions')->where('id', $this->versionId)->update([
            'effective_from' => Carbon::today()->subYears(2)->toDateString(),
            'effective_to' => Carbon::today()->subMonth()->toDateString(),
        ]);

        $this->assertContains(PackAssurance::SUPERSEDED,
            $this->assurance()->assess($this->versionId)['acknowledgeable']);
    }

    // ── The activation gate ───────────────────────────────────────────────────────────────────

    public function test_activating_an_unreviewed_pack_as_the_authority_needs_an_acknowledgement(): void
    {
        $this->actingAs($this->user)
            ->post('/sheq/regulatory-packs/activate', [
                'pack_version_id' => $this->versionId,
                'status' => 'ACCEPTED',
            ])
            ->assertSessionHasErrors('assurance_acknowledgement');

        $this->assertFalse(DB::table('sheq_regulatory_pack_activations')
            ->where('company_id', $this->companyId)
            ->where('pack_version_id', $this->versionId)->exists());
    }

    public function test_the_acknowledgement_is_kept_with_the_gaps_as_they_were(): void
    {
        $this->actingAs($this->user)
            ->post('/sheq/regulatory-packs/activate', [
                'pack_version_id' => $this->versionId,
                'status' => 'ACCEPTED',
                'assurance_acknowledgement' => 'We authored this pack ourselves against our own '
                    .'legal advice. Accepted by the SHEQ manager.',
            ])
            ->assertRedirect();

        $row = DB::table('sheq_regulatory_pack_activations')
            ->where('company_id', $this->companyId)
            ->where('pack_version_id', $this->versionId)->first();

        $this->assertNotNull($row);
        $this->assertNotNull($row->assurance_acknowledged_at);
        $this->assertSame($this->user->id, (int) $row->assurance_acknowledged_by,
            'Nothing records who accepted that an unreviewed rulebook could be the authority.');
        $this->assertStringContainsString('authored this pack ourselves',
            $row->assurance_acknowledgement);

        $gaps = json_decode((string) $row->assurance_gaps, true);

        $this->assertContains(PackAssurance::NOT_LEGALLY_REVIEWED, $gaps,
            'The gaps are not frozen at the moment of activation, so once somebody reviews the '
            .'pack the record reads as though it had always been reviewed.');
    }

    public function test_a_preview_activation_is_not_gated(): void
    {
        /*
         * The whole point of previewing an unreviewed pack is to find out what it would do.
         * Gating a sandbox is how somebody ends up activating it for real to see.
         */
        $this->actingAs($this->user)
            ->post('/sheq/regulatory-packs/activate', [
                'pack_version_id' => $this->versionId,
                'status' => 'PREVIEW',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('PREVIEW', DB::table('sheq_regulatory_pack_activations')
            ->where('company_id', $this->companyId)
            ->where('pack_version_id', $this->versionId)->value('status'));
    }

    public function test_a_tampered_pack_cannot_be_activated_even_with_an_acknowledgement(): void
    {
        $manifest = json_decode((string) DB::table('sheq_regulatory_pack_versions')
            ->where('id', $this->versionId)->value('manifest'), true);

        $manifest['jurisdiction'] = 'TAMPERED';

        DB::table('sheq_regulatory_pack_versions')->where('id', $this->versionId)
            ->update(['manifest' => json_encode($manifest)]);

        $this->actingAs($this->user)
            ->post('/sheq/regulatory-packs/activate', [
                'pack_version_id' => $this->versionId,
                'status' => 'ACCEPTED',
                'assurance_acknowledgement' => 'I accept the risk.',
            ])
            ->assertSessionHasErrors('pack_version_id');

        $this->assertFalse(DB::table('sheq_regulatory_pack_activations')
            ->where('company_id', $this->companyId)
            ->where('pack_version_id', $this->versionId)->exists(),
            'A tampered pack was activated because somebody typed a sentence. A checksum is not '
            .'a matter of judgement.');
    }

    // ── REG-FIX-1 ─────────────────────────────────────────────────────────────────────────────

    public function test_a_pack_can_be_activated_for_one_site(): void
    {
        $siteId = $this->site('Mine shaft');

        $this->actingAs($this->user)
            ->post('/sheq/regulatory-packs/activate', [
                'pack_version_id' => $this->versionId,
                'site_id' => $siteId,
                'status' => 'ACCEPTED',
                'assurance_acknowledgement' => 'Own pack.',
            ])
            ->assertRedirect();

        $this->assertSame($siteId, (int) DB::table('sheq_regulatory_pack_activations')
            ->where('company_id', $this->companyId)
            ->where('pack_version_id', $this->versionId)->value('site_id'),
            'REG-FIX-1: the activation did not record the site, so a group cannot run the mining '
            .'Act on a mine and the OHS Act on a factory at once.');
    }

    public function test_activating_for_another_tenants_site_is_refused(): void
    {
        /*
         * The site id was validated for existence and not for ownership, so another tenant's site
         * passed. The resulting row then matches nothing, which means the pack silently never
         * applies to the site somebody believed they had just configured.
         */
        $other = (int) DB::table('companies')->where('id', '!=', $this->companyId)->min('id');

        if ($other === 0) {
            $this->markTestSkipped('Only one company in the test database.');
        }

        $foreign = (int) DB::table('sheq_sites')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $other,
            'code' => 'F'.Str::upper(Str::random(5)), 'name' => 'Not ours',
            'site_type' => 'CONSTRUCTION', 'timezone' => 'Africa/Johannesburg',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($this->user)
            ->post('/sheq/regulatory-packs/activate', [
                'pack_version_id' => $this->versionId,
                'site_id' => $foreign,
                'status' => 'PREVIEW',
            ])
            ->assertSessionHasErrors('site_id');
    }

    public function test_a_site_activation_does_not_deactivate_the_company_wide_one(): void
    {
        $siteId = $this->site('Mine shaft');

        $this->actingAs($this->user)->post('/sheq/regulatory-packs/activate', [
            'pack_version_id' => $this->versionId, 'status' => 'ACCEPTED',
            'assurance_acknowledgement' => 'Own pack.',
        ])->assertRedirect();

        $second = $this->packVersion('za-test-2');

        $this->actingAs($this->user)->post('/sheq/regulatory-packs/activate', [
            'pack_version_id' => $second, 'site_id' => $siteId, 'status' => 'ACCEPTED',
            'assurance_acknowledgement' => 'Own pack.',
        ])->assertRedirect();

        $live = DB::table('sheq_regulatory_pack_activations')
            ->where('company_id', $this->companyId)->whereNull('deactivated_at')
            ->get(['site_id', 'pack_version_id']);

        $this->assertCount(2, $live,
            'Activating a pack for one site switched off the company-wide one, so the two packs '
            .'are mutually exclusive in practice — which is the REG-FIX-1 symptom.');
    }

    // ── REG-FIX-2 ─────────────────────────────────────────────────────────────────────────────

    public function test_the_readiness_check_reports_the_things_a_fresh_deployment_lacks(): void
    {
        $result = app(SheqReadiness::class)->check($this->companyId);

        $keys = array_column($result['checks'], 'key');

        foreach (['packs_installed', 'inspection_templates', 'inductions', 'pack_integrity',
            'pack_legal_review', 'pack_activated', 'sites'] as $key) {
            $this->assertContains($key, $keys,
                "The readiness check says nothing about {$key}, so a fresh deployment finds out "
                .'about it from a safety officer failing to file an incident.');
        }

        foreach ($result['checks'] as $check) {
            $this->assertArrayHasKey('blocking', $check);
            $this->assertNotSame('', trim($check['fix']),
                "The {$check['key']} check has no fix, so it tells somebody they have a problem "
                .'and not what to do about it.');
        }
    }

    public function test_a_company_with_no_pack_in_force_is_not_ready(): void
    {
        /*
         * The failure REG-FIX-2 exists for: everything installs, every screen renders, and the
         * first real incident report is refused at the moment of submitting it.
         */
        DB::table('sheq_regulatory_pack_activations')
            ->where('company_id', $this->companyId)->delete();

        $result = app(SheqReadiness::class)->check($this->companyId);

        $this->assertFalse($result['ready']);

        $activated = collect($result['checks'])->firstWhere('key', 'pack_activated');

        $this->assertFalse($activated['ok']);
        $this->assertTrue($activated['blocking'],
            'No pack in force is reported as a warning. Every incident report is refused.');
        $this->assertStringContainsString('refused', $activated['detail']);
    }

    public function test_the_installation_check_alone_ignores_tenant_configuration(): void
    {
        // A deployment pipeline checks the installation; it cannot know which tenants exist yet.
        $keys = array_column(app(SheqReadiness::class)->check(null)['checks'], 'key');

        $this->assertContains('packs_installed', $keys);
        $this->assertNotContains('pack_activated', $keys,
            'An installation check fails because no tenant has activated a pack, which a '
            .'deployment cannot do on their behalf.');
    }

    public function test_the_doctor_exits_non_zero_only_on_a_blocker(): void
    {
        $this->artisan('sheq:doctor')->assertSuccessful();

        DB::table('sheq_regulatory_pack_activations')
            ->where('company_id', $this->companyId)->delete();

        $this->artisan('sheq:doctor', ['--company' => $this->companyId])->assertFailed();
    }

    public function test_an_unreviewed_pack_is_a_warning_and_not_a_blocker(): void
    {
        /*
         * It does not stop the system working. It stops the output being honestly describable as
         * statutory compliance, which is a commercial fact rather than a technical one — and a
         * check that fails a deployment pipeline over it is a check somebody disables.
         */
        $review = collect(app(SheqReadiness::class)->check(null)['checks'])
            ->firstWhere('key', 'pack_legal_review');

        $this->assertFalse($review['blocking']);
        $this->assertStringContainsString('must not be presented', $review['detail']);
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────────────────────

    private function packVersion(string $packId = 'za-test'): int
    {
        $packRow = (int) DB::table('sheq_regulatory_packs')->insertGetId([
            'pack_id' => $packId.'-'.Str::lower(Str::random(5)),
            'jurisdiction' => 'ZA', 'name' => 'Test pack',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $manifest = [
            'pack_id' => $packId, 'version' => '1.0.0', 'jurisdiction' => 'ZA',
            'classification_rules' => [],
            'obligations' => [],
            'metrics' => ['definitions' => []],
            'retention' => ['incident_records' => 'P40Y'],
        ];

        return (int) DB::table('sheq_regulatory_pack_versions')->insertGetId([
            'pack_id' => $packRow,
            'version' => '1.0.0',
            'effective_from' => Carbon::today()->subYear()->toDateString(),
            'manifest' => json_encode($manifest),
            // The real checksum, so a clean pack assesses as clean.
            'checksum' => hash('sha256', PackManifest::canonicalJson($manifest)),
            'installed_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function site(string $name): int
    {
        return (int) DB::table('sheq_sites')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'code' => 'R'.Str::upper(Str::random(5)), 'name' => $name,
            'site_type' => 'MINE', 'timezone' => 'Africa/Johannesburg',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function userWith(array $permissions): User
    {
        $userId = DB::table('users')->insertGetId([
            'uuid' => (string) Str::uuid(), 'email' => 'reg-'.Str::random(8).'@example.test',
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
}
