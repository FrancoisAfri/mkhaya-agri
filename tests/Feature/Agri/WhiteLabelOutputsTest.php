<?php

namespace Tests\Feature\Agri;

use App\Core\User\Models\User;
use App\Modules\Agri\Models\CarbonRegistration;
use App\Modules\Agri\Models\ExportConsignment;
use App\Modules\Agri\Models\Farm;
use App\Modules\Agri\Models\GrowerAccount;
use App\Modules\Agri\Models\ProduceLot;
use App\Modules\Agri\Models\TraceToken;
use App\Modules\Agri\Notifications\FarmWorkNotice;
use App\Modules\Agri\Notifications\GrowerPortalMessage;
use App\Modules\Agri\Services\CarbonRegistry;
use App\Modules\Agri\Services\CertificationSchemes;
use App\Modules\Agri\Services\EnvironmentReport;
use App\Modules\Agri\Services\ExportDocuments;
use App\Modules\Agri\Services\GrowerPortal;
use App\Modules\Agri\Services\Outgrowers;
use App\Modules\Agri\Services\PublicTrace;
use App\Modules\Agri\Models\EnvironmentRecord;
use App\Modules\Tenancy\Models\Company;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * White label on every outward-facing output: with it on, the trace page, the label sheet, the
 * export documents, the certification auditor pack, the carbon registry summary, the grower portal
 * and statement, and farm emails carry the company's own name and logo and never "Mkhaya"; with it
 * off they are as they always were. Also: the in-app header follows the company a platform
 * administrator switched into, and only a platform administrator may turn white label on.
 */
class WhiteLabelOutputsTest extends AgriTestCase
{
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private const BRAND = 'Kliprivier Fresh';

    private Farm $farmA;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->catalogue();
        $this->farmA = $this->farm('RIV');
        $this->member($this->manager, $this->farmA);
        $this->identity($this->company, self::BRAND);
    }

    private function identity(Company $c, string $name): void
    {
        DB::table('company_identities')->insert(['company_id' => $c->id, 'company_name' => $name,
            'company_logo' => json_encode(['data' => self::PNG, 'mime' => 'image/png', 'name' => 'logo.png']), 'created_at' => now(), 'updated_at' => now()]);
    }

    private function whiteLabel(bool $on, ?Company $c = null): void
    {
        ($c ?? $this->company)->forceFill(['white_label' => $on])->save();
    }

    /** Branded: the company's name and logo, and not a word of the product's name. */
    private function assertBranded(string $out, string $what): void
    {
        $this->assertStringContainsString(self::BRAND, $out, "{$what} names the company.");
        $this->assertStringContainsString('data:image/png;base64,'.self::PNG, $out, "{$what} carries the company logo.");
        $this->assertStringNotContainsStringIgnoringCase('mkhaya', $out, "{$what} says nothing of Mkhaya.");
    }

    private function assertDefault(string $out, string $what): void
    {
        $this->assertStringNotContainsString(self::BRAND, $out, "{$what} is not white-labelled.");
        $this->assertStringNotContainsString('data:image/png;base64,'.self::PNG, $out, "{$what} has no company logo.");
    }

    public function test_only_a_platform_administrator_turns_white_label_on_and_off(): void
    {
        $this->actingAs($this->admin)->post(route('tenancy.companies.white-label', $this->company->id), ['enabled' => 1]);
        $this->assertFalse((bool) $this->company->fresh()->white_label, 'A company administrator cannot grant it to themselves.');

        $platform = User::factory()->create(['email' => 'platform@mkhaya.test', 'status' => 1, 'company_id' => $this->company->id, 'is_platform_admin' => true]);
        $this->actingAs($platform)->post(route('tenancy.companies.white-label', $this->company->id), ['enabled' => 1])->assertSessionHasNoErrors();
        $this->assertTrue((bool) $this->company->fresh()->white_label);
        $this->actingAs($platform)->post(route('tenancy.companies.white-label', $this->company->id), ['enabled' => 0])->assertSessionHasNoErrors();
        $this->assertFalse((bool) $this->company->fresh()->white_label);
    }

    public function test_a_platform_administrator_switched_into_a_company_sees_that_companys_branding(): void
    {
        $client = Company::create(['name' => 'Client Co']);
        $this->identity($client, 'Client Orchards');
        $this->whiteLabel(true, $client);
        $this->whiteLabel(false);
        $platform = User::factory()->create(['email' => 'platform@mkhaya.test', 'status' => 1, 'company_id' => $this->company->id, 'is_platform_admin' => true]);

        $branding = fn () => $this->actingAs($platform)->get(route('tenancy.companies.index'))->assertOk()->viewData('page')['props']['branding'];
        $this->assertFalse($branding()['white_label'], 'At home: the home company, which is not white-labelled.');

        session(['impersonate_company_id' => $client->id]);
        $b = $branding();
        $this->assertTrue($b['white_label'], 'Switched in: the client\'s branding, not the home company\'s.');
        $this->assertSame('Client Orchards', $b['system_name']);
        $this->assertStringStartsWith('/settings/branding/logo', $b['system_logo']);
        $logo = $this->actingAs($platform)->get(route('settings.branding.logo'))->assertOk();
        $this->assertSame(base64_decode(self::PNG), $logo->getContent());

        // An ordinary user of a white-labelled company sees their company's branding as before.
        $this->whiteLabel(true);
        $this->assertSame(self::BRAND, $this->actingAs($this->manager)->get(route('agri.outgrowers.index'))->assertOk()->viewData('page')['props']['branding']['system_name']);
    }

    public function test_the_trace_page_and_label_sheet(): void
    {
        $lot = $this->asCompany($this->company, fn () => ProduceLot::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'farm_id' => $this->farmA->id,
            'code' => 'P-0001', 'kind' => 'packed', 'product' => 'Lemons 15 kg', 'grade' => 'Class 1', 'quantity' => 60, 'remaining' => 60, 'unit' => 'ctn',
            'status' => 'available', 'produced_on' => today()->subDays(2)]));
        $this->actingAs($this->manager)->post(route('agri.trace.issue', $lot->uuid))->assertSessionHasNoErrors();
        $token = TraceToken::withoutGlobalScopes()->sole();
        Auth::forgetGuards();

        $off = $this->get('/t/'.$token->token)->assertOk()->getContent();
        $this->assertDefault($off, 'The trace page');
        $this->assertStringContainsString('Traceability by Mkhaya Agri', $off, 'Off: the product credit as before.');
        $labels = fn () => $this->asCompany($this->company, fn () => app(PublicTrace::class)->labelsHtml([['product' => 'Lemons', 'lot' => 'P-0001', 'grade' => null, 'url' => 'x',
            'qr' => 'data:image/svg+xml;base64,AA==']], $this->company->id));
        $this->assertDefault($labels(), 'The label sheet');

        $this->whiteLabel(true);
        $on = $this->get('/t/'.$token->token)->assertOk()->getContent();
        $this->assertBranded($on, 'The trace page');
        $this->assertStringContainsString('Traceability by '.self::BRAND, $on);
        $this->assertBranded($labels(), 'The label sheet');
        $this->actingAs($this->manager)->get(route('agri.trace.labels', ['lots' => [$lot->uuid]]))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_export_documents_certification_pack_and_carbon_summary(): void
    {
        $this->openBooks();
        $c = $this->asCompany($this->company, fn () => ExportConsignment::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'farm_id' => $this->farmA->id,
            'wms_order_id' => 990001, 'reference' => 'EXP-0001', 'consignee_name' => 'Importer NL', 'destination_country' => 'NL', 'required_documents' => ['packing_list']]));
        $export = fn () => $this->asCompany($this->company, fn () => app(ExportDocuments::class)->documentHtml($c, 'packing_list'));

        $this->actingAs($this->manager)->post(route('agri.certification.install'), ['template' => 'globalgap-ifa-v6-smart-fv'])->assertSessionHasNoErrors();
        $scheme = DB::table('sheq_compliance_schemes')->where('company_id', $this->company->id)->where('code', 'globalgap-ifa-v6-smart-fv')->first();
        $this->actingAs($this->manager)->post(route('agri.certification.adopt', $this->farmA->uuid), ['scheme_id' => $scheme->id])->assertSessionHasNoErrors();
        $cert = fn () => $this->asCompany($this->company, fn () => app(CertificationSchemes::class)->html($this->farmA->fresh(), $scheme, $this->manager));

        $project = $this->asCompany($this->company, fn () => app(EnvironmentReport::class)->record($this->farmA, ['kind' => 'carbon_project', 'recorded_on' => '2026-01-15',
            'quantity' => 212, 'unit' => 't CO2e', 'method' => 'model', 'description' => 'No-till soil carbon', 'verification' => 'verified', 'methodology' => 'VM0042 v2.0',
            'verifier' => 'SGS South Africa'], $this->manager,
            ['original_name' => 'verification.pdf', 'mime' => 'application/pdf', 'data' => base64_encode("%PDF-1.4\n% verification\n"), 'size' => 24]));
        $this->actingAs($this->manager)->post(route('agri.carbon.register', $project->uuid), ['registry' => 'verra_vcs', 'registry_project_id' => 'VCS-4821', 'status' => 'registered',
            'buffer_pct' => 20, 'proponent' => 'Boplaas Boerdery'])->assertSessionHasNoErrors();
        $reg = CarbonRegistration::withoutGlobalScopes()->sole();
        $carbon = fn () => $this->asCompany($this->company, fn () => app(CarbonRegistry::class)->summaryHtml($reg, EnvironmentRecord::withoutGlobalScopes()->find($project->id),
            $this->farmA, collect()));

        $this->assertDefault($export(), 'The export document');
        $this->assertStringContainsString('Prepared with Mkhaya Agri', $export(), 'Off: the product credit as before.');
        $this->assertDefault($cert(), 'The certification pack');
        $this->assertDefault($carbon(), 'The carbon summary');

        $this->whiteLabel(true);
        $this->assertBranded($export(), 'The export document');
        $this->assertBranded($cert(), 'The certification pack');
        $this->assertBranded($carbon(), 'The carbon summary');
        // The PDFs themselves are still produced.
        $this->assertStringStartsWith('%PDF', $this->actingAs($this->manager)->get(route('agri.certification.pdf', $this->farmA->uuid).'?scheme='.$scheme->id)->assertOk()->getContent());
    }

    public function test_the_grower_portal_statement_and_farm_emails(): void
    {
        $grower = $this->asCompany($this->company, fn () => app(Outgrowers::class)->register($this->farmA, ['name' => 'Sipho Dlamini', 'code' => 'G-001'], $this->manager));
        $account = $this->asCompany($this->company, fn () => GrowerAccount::create(['company_id' => $this->company->id, 'grower_id' => $grower->id, 'name' => 'Sipho',
            'email' => 'sipho@growers.test', 'status' => 'active']));
        $uuid = $this->company->fresh()->uuid;
        $statement = fn () => $this->asCompany($this->company, fn () => app(GrowerPortal::class)->statementHtml($grower));
        $login = function () use ($uuid) {
            Auth::forgetGuards();

            return $this->get(route('grower.portal.login', ['c' => $uuid]))->assertOk()->viewData('page')['props']['brand'];
        };
        $mail = fn () => $this->asCompany($this->company, fn () => (string) (new GrowerPortalMessage('email', 'Your code', 'Your code is 123456.', 'https://example.test/grower', 'Open'))
            ->toMail($account)->render());
        $staffMail = fn () => (string) (new FarmWorkNotice('Job WO-1', 'You are on job WO-1.', '/agri/my-work'))->toMail($this->manager)->render();

        $this->assertDefault($statement(), 'The grower statement');
        $this->assertStringContainsString('Prepared with Mkhaya Agri', $statement());
        $b = $login();
        $this->assertFalse($b['white_label']);
        $this->assertTrue($b['powered_by']);
        $this->get(route('grower.portal.logo', $uuid))->assertNotFound();
        $this->assertStringContainsString(config('app.name'), $mail(), 'Off: the email is the standard one.');
        $this->assertStringNotContainsString(self::BRAND, $staffMail());

        $this->whiteLabel(true);
        $this->assertBranded($statement(), 'The grower statement');
        $b = $login();
        $this->assertSame([true, self::BRAND, false], [$b['white_label'], $b['name'], $b['powered_by']]);
        $this->assertStringContainsString('/grower/brand/'.$uuid.'/logo', $b['logo_url']);
        $this->assertSame(base64_decode(self::PNG), $this->get($b['logo_url'])->assertOk()->getContent());
        foreach (['The grower email' => $mail(), 'The staff farm email' => $staffMail()] as $what => $html) {
            $this->assertStringContainsString(self::BRAND, $html, "{$what} names the company.");
            $this->assertStringNotContainsStringIgnoringCase('mkhaya', $html, "{$what} says nothing of Mkhaya.");
        }
    }
}
