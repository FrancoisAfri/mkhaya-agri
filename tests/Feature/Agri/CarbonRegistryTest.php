<?php

namespace Tests\Feature\Agri;

use App\Modules\Agri\Models\CarbonExport;
use App\Modules\Agri\Models\CarbonIssuance;
use App\Modules\Agri\Models\CarbonPeriod;
use App\Modules\Agri\Models\CarbonRegistration;
use App\Modules\Agri\Models\EnvironmentRecord;
use App\Modules\Agri\Models\Farm;
use App\Modules\Agri\Services\EnvironmentReport;
use App\Modules\Agri\Support\UiLabels;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia;

/**
 * A verified carbon project tracked with its registry: listing, a monitoring period with data,
 * verification, issuance with the buffer, retirement, documents — and the registry-ready zip, kept
 * in the database, with the summary PDF, the monitoring CSV and every evidence document in it.
 */
class CarbonRegistryTest extends AgriTestCase
{
    private Farm $farmA;

    private EnvironmentRecord $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->catalogue();
        $this->farmA = $this->farm('NORTH');
        $this->member($this->manager, $this->farmA);
        $this->member($this->worker, $this->farmA, 'worker');
        $this->actingAs($this->manager);
        $this->project = $this->asCompany($this->company, function () {
            $env = app(EnvironmentReport::class);
            $env->record($this->farmA, ['kind' => 'fuel', 'recorded_on' => '2026-03-10', 'quantity' => 4200, 'unit' => 'l', 'method' => 'meter', 'description' => 'Diesel issued — March'], $this->manager);

            return $env->record($this->farmA, ['kind' => 'carbon_project', 'recorded_on' => '2026-01-15', 'quantity' => 212, 'unit' => 't CO2e', 'method' => 'model',
                'description' => 'No-till soil carbon on Kliprant', 'verification' => 'verified', 'methodology' => 'VM0042 v2.0', 'verifier' => 'SGS South Africa'], $this->manager,
                ['original_name' => 'soil-carbon-verification.pdf', 'mime' => 'application/pdf', 'data' => base64_encode("%PDF-1.4\n% verification statement\n"), 'size' => 32]);
        });
    }

    public function test_registry_tracking_and_the_export_package(): void
    {
        $this->actingAs($this->worker)->post(route('agri.carbon.register', $this->project->uuid), ['registry' => 'verra_vcs'])->assertForbidden();
        $this->actingAs($this->manager)->post(route('agri.carbon.register', $this->project->uuid), ['registry' => 'other'])->assertSessionHasErrors('registry_name');
        $this->actingAs($this->manager)->post(route('agri.carbon.register', $this->project->uuid), ['registry' => 'verra_vcs', 'registry_project_id' => 'VCS-4821',
            'status' => 'registered', 'buffer_pct' => 20, 'crediting_starts_on' => '2026-01-01', 'crediting_ends_on' => '2045-12-31', 'proponent' => 'Boplaas Boerdery'])->assertSessionHasNoErrors();
        $reg = CarbonRegistration::withoutGlobalScopes()->sole();
        $this->assertSame('VM0042 v2.0', $reg->methodology, 'The methodology comes from the verified project.');

        $this->actingAs($this->manager)->post(route('agri.carbon.periods.store', $reg->uuid), ['starts_on' => '2026-01-01', 'ends_on' => '2026-06-30', 'claimed_t' => 260])->assertSessionHasNoErrors();
        $this->actingAs($this->manager)->post(route('agri.carbon.periods.store', $reg->uuid), ['starts_on' => '2026-06-01', 'ends_on' => '2026-12-31'])->assertSessionHasErrors('starts_on');
        $period = CarbonPeriod::withoutGlobalScopes()->sole();
        $this->actingAs($this->manager)->post(route('agri.carbon.monitoring.store', $period->uuid), ['measured_on' => '2026-05-02', 'parameter' => 'SOC stock 0–30 cm',
            'value' => 48.6, 'unit' => 't C/ha', 'method' => 'Dry combustion', 'source' => 'Bemlab report 2214'])->assertSessionHasNoErrors();
        $this->actingAs($this->manager)->post(route('agri.carbon.monitoring.store', $period->uuid), ['measured_on' => '2026-08-02', 'parameter' => 'x', 'value' => 1, 'unit' => 'x'])
            ->assertSessionHasErrors('measured_on');
        $this->actingAs($this->manager)->patch(route('agri.carbon.periods.update', $period->uuid), ['status' => 'verified'])->assertSessionHasErrors('status');
        $this->actingAs($this->manager)->patch(route('agri.carbon.periods.update', $period->uuid), ['status' => 'verified', 'verified_t' => 250, 'verifier' => 'SGS South Africa',
            'verified_on' => '2026-09-01'])->assertSessionHasNoErrors();

        // 200 t to the project and 50 t (20 % of 250) into Verra's buffer pool.
        $this->actingAs($this->manager)->post(route('agri.carbon.issuances.store', $reg->uuid), ['period_id' => $period->uuid, 'vintage' => 2026, 'quantity_t' => 200,
            'serial_start' => 'VCS-4821-2026-0000001', 'serial_end' => 'VCS-4821-2026-0000200', 'issued_on' => '2026-10-01'])->assertSessionHasNoErrors();
        $iss = CarbonIssuance::withoutGlobalScopes()->sole();
        $this->assertEqualsWithDelta(50.0, $iss->buffer_t, 0.001);
        $this->assertSame('issuing', $reg->fresh()->status);
        $this->assertSame('issued', $period->fresh()->status);
        $this->actingAs($this->manager)->post(route('agri.carbon.issuances.store', $reg->uuid), ['period_id' => $period->uuid, 'vintage' => 2026, 'quantity_t' => 10, 'issued_on' => '2026-10-02'])
            ->assertSessionHasErrors('quantity_t');

        $this->actingAs($this->manager)->post(route('agri.carbon.transactions.store', $iss->uuid), ['kind' => 'retirement', 'quantity_t' => 50, 'transacted_on' => '2026-10-05',
            'beneficiary' => 'Mkhaya Foods (Scope 3 2026)', 'serial_start' => 'VCS-4821-2026-0000001', 'serial_end' => 'VCS-4821-2026-0000050'])->assertSessionHasNoErrors();
        $this->actingAs($this->manager)->post(route('agri.carbon.transactions.store', $iss->uuid), ['kind' => 'transfer', 'quantity_t' => 300, 'transacted_on' => '2026-10-06', 'counterparty' => 'ACC-9'])
            ->assertSessionHasErrors('quantity_t');

        $this->actingAs($this->manager)->post(route('agri.carbon.documents.store', $reg->uuid), ['kind' => 'pdd',
            'file' => UploadedFile::fake()->createWithContent('Project description.pdf', "%PDF-1.4\n% PDD\n")])->assertSessionHasNoErrors();

        // The package.
        $this->actingAs($this->worker)->post(route('agri.carbon.exports.store', $reg->uuid))->assertForbidden();
        $this->actingAs($this->manager)->post(route('agri.carbon.exports.store', $reg->uuid))->assertSessionHasNoErrors();
        $export = CarbonExport::withoutGlobalScopes()->sole();
        $zipBytes = base64_decode($export->getRawOriginal('data'));
        $this->assertSame($export->sha256, hash('sha256', $zipBytes));
        $tmp = tempnam(sys_get_temp_dir(), 'carbon');
        file_put_contents($tmp, $zipBytes);
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($tmp) === true);
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }
        foreach (['project-summary.pdf', 'monitoring/2026-01-01_2026-06-30.csv', 'ledger/issuances.csv', 'ledger/transactions.csv',
            'evidence/verification-soil-carbon-verification.pdf', 'evidence/pdd/Project-description.pdf', 'manifest.json'] as $f) {
            $this->assertContains($f, $names, "The package has {$f}.");
        }
        $this->assertStringStartsWith('%PDF', $zip->getFromName('project-summary.pdf'));
        $csv = $zip->getFromName('monitoring/2026-01-01_2026-06-30.csv');
        $this->assertStringContainsString('2026-05-02,project,"SOC stock 0–30 cm",48.6,"t C/ha"', $csv);
        $this->assertStringContainsString('fuel: Diesel issued — March', $csv, 'The farm\'s own records for the period sit beside the project\'s measurements.');
        $this->assertStringContainsString('VCS-4821-2026-0000200', $zip->getFromName('ledger/issuances.csv'));
        $this->assertStringContainsString('Mkhaya Foods (Scope 3 2026)', $zip->getFromName('ledger/transactions.csv'));
        $this->assertSame("%PDF-1.4\n% PDD\n", $zip->getFromName('evidence/pdd/Project-description.pdf'));
        $manifest = json_decode($zip->getFromName('manifest.json'), true);
        $this->assertSame('VCS-4821', $manifest['registry_project_id']);
        $this->assertSame(hash('sha256', $zip->getFromName('ledger/issuances.csv')), collect($manifest['files'])->firstWhere('file', 'ledger/issuances.csv')['sha256']);
        $this->assertStringContainsString('no submission API', $manifest['note']);
        $zip->close();
        @unlink($tmp);

        $dl = $this->actingAs($this->manager)->get(route('agri.carbon.exports.show', $export->uuid))->assertOk();
        $this->assertSame('application/zip', $dl->headers->get('Content-Type'));

        $this->actingAs($this->manager)->get(route('agri.carbon.index'))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->component('Modules/Agri/Carbon/Index')
                ->where('projects.0.registration.registry_project_id', 'VCS-4821')
                ->where('projects.0.registration.balance.held', fn ($v) => (float) $v === 150.0)
                ->where('projects.0.registration.balance.buffer', fn ($v) => (float) $v === 50.0)
                ->where('projects.0.registration.balance.retired', fn ($v) => (float) $v === 50.0)
                ->has('projects.0.registration.exports', 1));
        $this->assertNotSame('agri.carbon.no_api', UiLabels::get('agri.carbon.no_api'), 'The page says there is no live registry API.');
    }

    public function test_only_a_carbon_project_is_listed_and_credits_need_a_registered_project(): void
    {
        $fuel = EnvironmentRecord::withoutGlobalScopes()->where('kind', 'fuel')->sole();
        $this->actingAs($this->manager)->post(route('agri.carbon.register', $fuel->uuid), ['registry' => 'gold_standard'])->assertSessionHasErrors('record');
        $this->actingAs($this->manager)->post(route('agri.carbon.register', $this->project->uuid), ['registry' => 'gold_standard', 'status' => 'under_validation'])->assertSessionHasNoErrors();
        $reg = CarbonRegistration::withoutGlobalScopes()->sole();
        $this->actingAs($this->manager)->post(route('agri.carbon.issuances.store', $reg->uuid), ['vintage' => 2026, 'quantity_t' => 10, 'issued_on' => '2026-10-01'])
            ->assertSessionHasErrors('registration');
    }
}
