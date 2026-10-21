<?php

namespace Tests\Feature\Agri;

use App\Modules\Agri\Models\FarmAlert;
use App\Modules\Agri\Models\FarmMachine;
use App\Modules\Agri\Models\FarmWorkOrder as WO;
use App\Modules\Agri\Services\FarmAlerts;
use App\Modules\Agri\Services\FarmCompliance;
use App\Modules\Agri\Services\FarmDocuments;
use App\Modules\Agri\Services\FarmSafety;
use App\Modules\Operations\DocumentManagement\Models\DocumentApprovalAssignment;
use App\Modules\Operations\DocumentManagement\Services\ApprovalService;
use App\Modules\Operations\Sheq\Regulatory\PackLoader;
use App\Modules\Operations\Sheq\Services\ComplianceSchemes;
use App\Modules\Operations\Sheq\Services\InspectionGates;
use App\Modules\Operations\Sheq\Services\InspectionService;
use App\Modules\Operations\Sheq\Services\LegalRegisterService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Core\Role\Models\Role;

/**
 * Phase 2 §12 on SHEQ and Document Management: the farm's obligations are SHEQ legal register lines
 * seeded from a scheme the organisation writes and adopts at the farm's site, their status following
 * linked documents and the evidence only farm records give; farm safety reports land in SHEQ; a SHEQ
 * inspection gates risky farm work; and farm records are Document Management documents approved by
 * someone other than whoever filed them.
 */
class ComplianceSafetyTest extends AgriTestCase
{
    private Role $farmManagerRole;

    protected function setUp(): void
    {
        parent::setUp();
        $this->catalogue();
        // The farm records workflow is approved by Farm Managers; the uploader holds the role too.
        $this->farmManagerRole = Role::firstOrCreate(['name' => 'Farm Manager', 'guard_name' => 'web']);
        $this->manager->assignRole($this->farmManagerRole);
    }

    private function activatePack(): void
    {
        $manifest = (new PackLoader)->load(base_path('resources/regulatory-packs/za-ohsa'));
        $packId = DB::table('sheq_regulatory_packs')->insertGetId(['pack_id' => 'za-ohsa-agri-'.Str::random(6), 'jurisdiction' => 'ZA', 'name' => 'OHSA',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $versionId = DB::table('sheq_regulatory_pack_versions')->insertGetId(['pack_id' => $packId, 'version' => $manifest->version, 'effective_from' => '2020-01-01',
            'manifest' => json_encode($manifest->manifest), 'checksum' => $manifest->checksum(), 'signature_verified' => false, 'installed_at' => now(),
            'created_at' => now(), 'updated_at' => now()]);
        DB::table('sheq_regulatory_pack_activations')->insert(['company_id' => $this->company->id, 'uuid' => (string) Str::uuid(), 'site_id' => null,
            'pack_version_id' => $versionId, 'status' => 'ACCEPTED', 'activated_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }

    private function file($farm, string $type, string $title, ?string $expires)
    {
        $this->actingAs($this->manager);

        return $this->asCompany($this->company, fn () => app(FarmDocuments::class)->file($farm, ['document_type' => $type, 'title' => $title, 'expires_on' => $expires],
            ['original_name' => Str::slug($title).'.pdf', 'mime' => 'application/pdf', 'data' => base64_encode("%PDF-1.4\n% {$title}\n")], $this->manager));
    }

    /** The farm-records approval: someone other than the uploader, holding the approving role. */
    private function approve($doc, $as): array
    {
        $this->actingAs($as);
        $assignment = DocumentApprovalAssignment::withoutGlobalScopes()->whereHas('request', fn ($q) => $q->withoutGlobalScopes()->where('document_id', $doc->id))
            ->where('status', 'pending')->where('assigned_to', $as->id)->first();

        return $assignment ? $this->asCompany($this->company, fn () => app(ApprovalService::class)->decide($assignment, 'approved', $as))
            : ['request' => null, 'error' => 'not assigned'];
    }

    public function test_12_1_a_scheme_adopted_at_the_farm_seeds_its_sheq_register_and_status_follows_documents_and_farm_records(): void
    {
        $farm = $this->farm('NORTH');
        $this->member($this->manager, $farm);
        $c = $this->company->id;
        $siteId = $this->asCompany($this->company, fn () => app(FarmSafety::class)->site($farm, $this->manager));

        // The organisation writes its scheme in SHEQ and adopts it at the farm.
        $schemes = app(ComplianceSchemes::class);
        $scheme = $this->asCompany($this->company, fn () => $schemes->create($c, ['name' => 'ZA irrigated crops', 'code' => 'za-irrigated', 'jurisdiction' => 'ZA'], $this->manager->id));
        $this->asCompany($this->company, function () use ($schemes, $c, $scheme) {
            $schemes->clause($c, $scheme, ['clause' => 'W1', 'requirement' => 'A current water use licence', 'evidence_types' => ['DM_DOCUMENT']]);
            $schemes->clause($c, $scheme, ['clause' => 'M1', 'requirement' => 'Pressure vessels and lifting machinery inspected', 'evidence_types' => [FarmCompliance::MACHINE_INSPECTION]]);
            $schemes->clause($c, $scheme, ['clause' => 'F1', 'requirement' => 'First-aid box checked', 'evidence_types' => ['DM_DOCUMENT'], 'review_frequency' => 'P12M']);
        });
        $this->expectsAdoptionRefusedUntilPublished($schemes, $c, $scheme, $siteId);
        $this->asCompany($this->company, fn () => $schemes->publish($c, $scheme, $this->manager->id));
        $scheme = DB::table('sheq_compliance_schemes')->find($scheme->id);
        $this->asCompany($this->company, fn () => $schemes->adopt($c, $scheme, $siteId, $this->manager->id));
        $this->asCompany($this->company, fn () => $schemes->adopt($c, $scheme, $siteId, $this->manager->id));
        $this->assertSame(3, DB::table('sheq_legal_register_lines')->where('site_id', $siteId)->where('regulatory_pack_id', 'scheme:za-irrigated')->count(), 'Adopting twice adds nothing twice.');
        $line = fn (string $clause) => DB::table('sheq_legal_register_lines')->where('site_id', $siteId)->where('clause', $clause)->first();
        $register = app(LegalRegisterService::class);

        // The licence counts once a farm record is linked and approved by someone other than who filed it.
        // Approvers are assigned when a record is filed: a second farm manager is on hand.
        $second = $this->makeUser('manager2@boplaas.test', $this->farmManagerRole, $this->company);
        $this->assertSame('NOT_ASSESSED', $line('W1')->status);
        $doc = $this->file($farm, 'water_use_licence', 'WUL 2026', today()->addDays(200)->toDateString());
        $this->assertSame((string) $farm->id, (string) FarmDocuments::farmIdOf($doc));
        $this->asCompany($this->company, fn () => $register->linkEvidence($c, $line('W1')->uuid, ['evidence_type' => 'DM_DOCUMENT', 'evidence_id' => $doc->id]));
        $this->assertSame('PARTIAL', $line('W1')->status, 'On file but not yet approved.');
        $this->assertSame(__('document_management.approvals.not_your_own'), $this->approve($doc, $this->manager)['error'], 'The uploader does not approve it.');
        $this->assertNull($this->approve($doc, $second)['error']);
        $this->asCompany($this->company, fn () => $register->compute($c, $line('W1')->uuid));
        $this->assertSame('COMPLIANT', $line('W1')->status);

        // Machine inspections follow the farm's machines, with nothing to link.
        $this->asCompany($this->company, fn () => $register->compute($c, $line('M1')->uuid));
        $this->assertSame('COMPLIANT', $line('M1')->status);
        $this->asCompany($this->company, fn () => app(\App\Modules\Agri\Services\Machinery::class)->register($farm, ['code' => 'FL-1', 'name' => 'Forklift', 'machine_type' => 'other',
            'hours_reading' => 0, 'inspection_required' => true, 'inspection_expires_on' => today()->subDay()->toDateString()]));
        $this->asCompany($this->company, fn () => $register->compute($c, $line('M1')->uuid));
        $this->assertSame('NON_COMPLIANT', $line('M1')->status);

        // Met with its evidence, it is due again a year on.
        $proof = $this->file($farm, 'report', 'First-aid box check', null);
        $this->approve($proof, $second);
        $this->asCompany($this->company, fn () => $register->linkEvidence($c, $line('F1')->uuid, ['evidence_type' => 'DM_DOCUMENT', 'evidence_id' => $proof->id]));
        $this->asCompany($this->company, fn () => $register->recordReview($c, $line('F1')->uuid, today()->toDateString()));
        $this->assertSame('COMPLIANT', $line('F1')->status);
        $this->assertSame(today()->addYear()->toDateString(), $line('F1')->review_due);

        // The farm alert scan raises what is not met, and links to the farm's SHEQ register.
        $this->asCompany($this->company, fn () => app(FarmAlerts::class)->scan($c));
        $alerts = FarmAlert::withoutGlobalScopes()->where('kind', 'compliance')->where('status', 'open')->get();
        $this->assertCount(1, $alerts);
        $this->assertStringContainsString('M1', $alerts->first()->title);
        $this->assertStringStartsWith('/sheq/legal-register?site=', $alerts->first()->link);
    }

    private function expectsAdoptionRefusedUntilPublished(ComplianceSchemes $schemes, int $c, object $scheme, int $siteId): void
    {
        try {
            $this->asCompany($this->company, fn () => $schemes->adopt($c, $scheme, $siteId, $this->manager->id));
            $this->fail('A draft scheme must not be adopted.');
        } catch (\InvalidArgumentException) {
        }
    }

    public function test_12_2_reports_from_the_farm_land_in_sheq_and_a_sheq_inspection_gates_risky_farm_work(): void
    {
        $farm = $this->farm('NORTH');
        $this->member($this->manager, $farm);
        $this->member($this->worker, $farm, 'worker');
        $tractor = $this->asCompany($this->company, fn () => app(\App\Modules\Agri\Services\Machinery::class)->register($farm, ['code' => 'T1', 'name' => 'Tractor', 'machine_type' => 'tractor', 'hours_reading' => 10]));
        $report = fn (array $d, $u = null) => $this->actingAs($u ?? $this->worker)->post(route('agri.safety.report'), $d + ['farm_id' => $farm->uuid]);

        // A hazard needs no regulatory pack; an incident does, and says so.
        $report(['kind' => 'hazard', 'description' => 'Broken guard rail on the silo ladder'])->assertSessionHasNoErrors();
        $this->assertSame(1, DB::table('sheq_hazards')->where('site_id', $farm->fresh()->sheq_site_id)->count());
        $report(['kind' => 'near_miss', 'description' => 'Bakkie reversed past a worker'])->assertSessionHasErrors('kind');

        $this->activatePack();
        $report(['kind' => 'near_miss', 'description' => 'Bakkie reversed past a worker', 'severity' => 4])->assertSessionHasNoErrors();
        $report(['kind' => 'chemical_spill', 'description' => 'Drum split in the chemical store', 'substance' => 'Glyphosate 480', 'quantity' => 20, 'unit' => 'L',
            'immediate_action' => 'Bunded, absorbent down'])->assertSessionHasNoErrors();
        $report(['kind' => 'unsafe_equipment', 'description' => 'PTO shield missing', 'machine_id' => $tractor->uuid])->assertSessionHasNoErrors();
        $site = $farm->fresh()->sheq_site_id;
        $this->assertSame(3, DB::table('sheq_incidents')->where('site_id', $site)->count());
        $this->assertSame('AGRI', DB::table('sheq_incidents')->where('site_id', $site)->value('source_channel'));
        $this->assertSame('Glyphosate 480', DB::table('sheq_spills')->where('site_id', $site)->value('substance'));
        $this->assertSame(FarmMachine::OUT_OF_SERVICE, $tractor->fresh()->status);

        // SHEQ's "before spraying" inspection gates spray jobs.
        $c = $this->company->id;
        $this->asCompany($this->company, fn () => app(InspectionService::class)->publishTemplate($c, ['code' => 'PRE-SPRAY', 'title' => 'Before spraying', 'scoring_mode' => 'PASS_FAIL',
            'schema' => ['sections' => [['title' => 'Before spraying', 'questions' => [['id' => 'ppe', 'label' => 'Respirator and gloves on', 'critical' => true],
                ['id' => 'wind', 'label' => 'Wind checked', 'critical' => true]]]]]], $this->admin->id));
        $this->asCompany($this->company, fn () => app(InspectionGates::class)->gate($c, 'PRE-SPRAY', InspectionGates::SUBJECT_WORK_ORDER, 'spraying', $this->admin->id));

        $this->actingAs($this->manager)->post(route('agri.work-orders.store'), ['farm_id' => $farm->uuid, 'category' => 'spraying', 'title' => 'Herbicide L1', 'priority' => 'normal',
            'planned_start' => now()->addHour()->toDateTimeString(), 'planned_end' => now()->addHours(4)->toDateTimeString()])->assertSessionHasNoErrors();
        $wo = WO::withoutGlobalScopes()->sole();
        $this->actingAs($this->admin)->post(route('agri.work-orders.transition', $wo->uuid), ['to' => WO::APPROVED])->assertSessionHasNoErrors();
        $start = fn () => $this->actingAs($this->manager)->post(route('agri.work-orders.transition', $wo->uuid), ['to' => WO::IN_PROGRESS]);
        $start()->assertSessionHasErrors('to');

        // The job page lists the check and opens SHEQ's own form for this job.
        $page = $this->actingAs($this->manager)->get(route('agri.work-orders.show', $wo->uuid))->assertOk()->viewData('page')['props'];
        $this->assertSame('Before spraying', $page['safety'][0]['title']);
        $perform = $this->actingAs($this->manager)->get($page['safety'][0]['url'])->assertRedirect();
        $this->assertStringContainsString('/sheq/inspections/', $perform->headers->get('Location'));
        $this->assertSame(1, DB::table('sheq_inspection_schedules')->where('subject_type', 'WORK_ORDER')->where('subject_id', $wo->id)->count());

        $inspect = fn (string $ppe) => $this->asCompany($this->company, fn () => app(FarmSafety::class)->inspect($wo->fresh(), 'PRE-SPRAY', ['ppe' => $ppe, 'wind' => 'YES'], $this->manager));
        $this->assertFalse((bool) $inspect('NO')['passed']);
        $start()->assertSessionHasErrors('to');
        $this->assertTrue((bool) $inspect('YES')['passed']);
        $start()->assertSessionHasNoErrors();
        $this->assertSame(WO::IN_PROGRESS, $wo->fresh()->status);
    }

    public function test_12_3_farm_records_are_document_management_documents_versioned_and_approved_by_a_second_person(): void
    {
        $farm = $this->farm('NORTH');
        $this->member($this->manager, $farm);
        $v1 = $this->file($farm, 'pesticide_registration', 'Spray licence', today()->addYear()->toDateString());
        $this->assertSame('Farm records', $v1->category?->name);
        $this->assertSame('submitted', $v1->approval_status, 'Filed, and sent for approval.');
        $version = DB::table('document_versions')->where('document_id', $v1->id)->first();
        $this->assertSame(hash('sha256', "%PDF-1.4\n% Spray licence\n"), $version->checksum);

        // A new version keeps the document and its history.
        $this->actingAs($this->manager);
        $v2 = $this->asCompany($this->company, fn () => app(FarmDocuments::class)->file($farm, ['expires_on' => today()->addYears(2)->toDateString()],
            ['original_name' => 'spray-licence-2.pdf', 'mime' => 'application/pdf', 'data' => base64_encode("%PDF-1.4\n% renewed\n")], $this->manager, $v1));
        $this->assertSame($v1->id, $v2->id);
        $this->assertSame(2, DB::table('document_versions')->where('document_id', $v1->id)->count());
        $this->assertSame(today()->addYears(2)->toDateString(), $v2->expiry_date->toDateString());

        // The expiring record shows in the farm's alerts and dashboards through Document Management.
        $soon = $this->file($farm, 'permit', 'Burning permit', today()->addDays(5)->toDateString());
        $expiring = $this->asCompany($this->company, fn () => FarmDocuments::expiring([$farm->id]));
        $this->assertSame([$soon->id], $expiring->pluck('document.id')->all());
        $this->assertStringContainsString('/document-management/documents/', FarmDocuments::url($soon));
    }
}
