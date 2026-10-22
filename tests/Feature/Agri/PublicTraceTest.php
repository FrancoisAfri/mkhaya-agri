<?php

namespace Tests\Feature\Agri;

use App\Modules\Agri\Models\AgriOrgSetting;
use App\Modules\Agri\Models\CropPlan;
use App\Modules\Agri\Models\CropVariety;
use App\Modules\Agri\Models\Farm;
use App\Modules\Agri\Models\FarmField;
use App\Modules\Agri\Models\HarvestRecord;
use App\Modules\Agri\Models\PackhouseOperation;
use App\Modules\Agri\Models\ProduceLot;
use App\Modules\Agri\Models\TraceToken;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia;

/**
 * The consumer trace page behind a pack's QR code: public and light, only the parts the
 * organisation allowed, never anything commercial or personal, gone when revoked.
 */
class PublicTraceTest extends AgriTestCase
{
    private Farm $farmA;

    private ProduceLot $packed;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->catalogue();
        $this->farmA = $this->farm('NORTH');
        $this->asCompany($this->company, fn () => $this->farmA->forceFill(['name' => 'Boplaas Noord', 'location' => 'Bethlehem', 'settings' => ['province' => 'Free State']])->save());
        $this->member($this->manager, $this->farmA);
        $this->member($this->worker, $this->farmA, 'worker');
        $field = $this->field($this->farmA, 'SECRETCODE', 20);
        $this->asCompany($this->company, fn () => FarmField::withoutGlobalScopes()->whereKey($field->id)->update(['name' => 'Kliprant orchard']));
        $this->packed = $this->asCompany($this->company, function () use ($field) {
            $variety = CropVariety::withoutGlobalScopes()->where('company_id', $this->company->id)->where('name', 'PAN 5R-591R')->first();
            $plan = CropPlan::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'farm_id' => $this->farmA->id, 'field_id' => $field->id,
                'season_id' => $this->seasonId(), 'crop_type_id' => $this->cropTypeId('MAIZE-W'), 'variety_id' => $variety?->id, 'code' => 'PLAN-SECRET-77',
                'planned_area' => 20, 'area_unit' => 'ha', 'planned_planting_on' => today()->subDays(150), 'planned_harvest_on' => today()->subDays(10),
                'status' => CropPlan::HARVESTING, 'yield_unit' => 't', 'expected_price' => 4321.99]);
            $rec = HarvestRecord::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'farm_id' => $this->farmA->id, 'crop_plan_id' => $plan->id,
                'field_id' => $field->id, 'harvested_on' => today()->subDays(12), 'quantity' => 1234.5, 'unit' => 't', 'destination' => 'store', 'unit_value' => 3777.77,
                'notes' => 'Sold to Woolworths at a premium', 'recorded_by' => $this->manager->id]);
            $harvest = ProduceLot::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'farm_id' => $this->farmA->id, 'code' => 'H-0001', 'kind' => 'harvest',
                'product' => 'White maize', 'crop_type_id' => $this->cropTypeId('MAIZE-W'), 'crop_plan_id' => $plan->id, 'field_id' => $field->id, 'harvest_record_id' => $rec->id,
                'quantity' => 1234.5, 'remaining' => 0, 'unit' => 't', 'unit_cost' => 3111.11, 'status' => 'consumed', 'produced_on' => today()->subDays(12), 'pallet_ref' => 'PALLET-SECRET']);
            $packed = ProduceLot::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'farm_id' => $this->farmA->id, 'code' => 'P-0001', 'kind' => 'packed',
                'product' => 'Maize meal 5 kg', 'grade' => 'Super', 'quantity' => 998.0, 'remaining' => 998.0, 'unit' => 'bags', 'unit_cost' => 55.55, 'status' => 'available',
                'produced_on' => today()->subDays(5), 'best_before' => today()->addMonths(6), 'location' => 'BIN-SECRET']);
            $op = PackhouseOperation::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'farm_id' => $this->farmA->id, 'reference' => 'OP-1', 'kind' => 'packing',
                'performed_on' => today()->subDays(4), 'input_quantity' => 1234.5, 'output_quantity' => 998, 'labour_cost' => 8888.88, 'input_value' => 99999, 'output_value' => 765432]);
            DB::table('produce_lot_links')->insert(['company_id' => $this->company->id, 'parent_lot_id' => $harvest->id, 'child_lot_id' => $packed->id, 'operation_id' => $op->id,
                'quantity' => 1234.5, 'created_at' => now(), 'updated_at' => now()]);
            AgriOrgSetting::for($this->company->id)->update(['certifications' => ['GLOBALG.A.P. (GGN 4063061234567)', 'SIZA']]);

            return $packed;
        });
    }

    private function issue(array $body = []): TraceToken
    {
        $this->actingAs($this->manager)->post(route('agri.trace.issue', $this->packed->uuid), $body)->assertSessionHasNoErrors();

        return TraceToken::withoutGlobalScopes()->where('produce_lot_id', $this->packed->id)->where('status', 'active')->sole();
    }

    public function test_public_page_shows_only_the_allowed_story_and_nothing_private(): void
    {
        $token = $this->issue();
        $this->assertSame(40, strlen($token->token));
        $this->assertStringNotContainsString('P-0001', $token->token);
        $this->assertFalse($token->visibility['field'], 'Field names are off by default.');

        auth()->logout();
        $r = $this->get('/t/'.$token->token)->assertOk();
        $r->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->assertStringContainsString('max-age', (string) $r->headers->get('Cache-Control'));
        $html = $r->getContent();
        $this->assertStringContainsString('<meta name="robots" content="noindex, nofollow">', $html);
        foreach (['Maize meal 5 kg', 'Super', 'P-0001', 'Boplaas Noord', 'Free State', 'White maize', today()->subDays(12)->toDateString(),
            today()->subDays(4)->toDateString(), 'GLOBALG.A.P. (GGN 4063061234567)', 'SIZA'] as $shown) {
            $this->assertStringContainsString(e($shown), $html, "The page shows {$shown}.");
        }
        foreach (['Kliprant', 'SECRETCODE', 'PLAN-SECRET', '3111', '3777', '4321', '55.55', '8888', '99999', '765432', '1234.5', '998', 'PALLET-SECRET', 'BIN-SECRET',
            'Woolworths', $this->manager->name, $this->manager->email, 'H-0001'] as $hidden) {
            $this->assertStringNotContainsString($hidden, $html, "The page never shows {$hidden}.");
        }
        $this->assertSame(1, $token->fresh()->views);

        // The organisation turns the field on and the farm name off for this lot.
        $this->actingAs($this->manager)->patch(route('agri.trace.update', $token->uuid), ['visibility' => ['field' => true, 'farm_name' => false]])->assertSessionHasNoErrors();
        auth()->logout();
        $html = $this->get('/t/'.$token->token)->assertOk()->getContent();
        $this->assertStringContainsString('Kliprant orchard', $html);
        $this->assertStringNotContainsString('Boplaas Noord', $html);
        $this->assertStringNotContainsString('SECRETCODE', $html, 'The field is shown by its name, not its internal code.');
    }

    public function test_revoked_and_unknown_tokens_are_not_found(): void
    {
        $token = $this->issue();
        auth()->logout();
        $this->get('/t/'.$token->token)->assertOk();

        $this->actingAs($this->worker)->post(route('agri.trace.revoke', $token->uuid))->assertForbidden();
        $this->actingAs($this->manager)->post(route('agri.trace.revoke', $token->uuid), ['reason' => 'Label misprint'])->assertSessionHasNoErrors();
        auth()->logout();
        $this->get('/t/'.$token->token)->assertNotFound();
        $this->get('/t/'.str_repeat('a', 40))->assertNotFound();
        $this->assertNotSame(200, $this->get('/t/short')->status(), 'Not a token: never the trace page.');

        // A new code for the same lot is a different token; the old one stays dead.
        $again = $this->issue();
        $this->assertNotSame($token->token, $again->token);
        auth()->logout();
        $this->get('/t/'.$again->token)->assertOk();
        $this->get('/t/'.$token->token)->assertNotFound();
    }

    public function test_labels_permissions_rate_limit_and_the_management_page(): void
    {
        $this->actingAs($this->worker)->post(route('agri.trace.issue', $this->packed->uuid))->assertForbidden();
        $this->actingAs($this->outsider)->get(route('agri.trace.index'))->assertForbidden();
        $token = $this->issue();

        $pdf = $this->actingAs($this->manager)->get(route('agri.trace.labels', ['lots' => [$this->packed->uuid], 'copies' => 3]))->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $pdf->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $pdf->getContent());

        $this->actingAs($this->manager)->post(route('agri.trace.settings'), ['defaults' => ['field' => true, 'farm_name' => true], 'certifications' => ['Organic (Ecocert)', '']])
            ->assertSessionHasNoErrors();
        $this->assertSame(['Organic (Ecocert)'], AgriOrgSetting::withoutGlobalScopes()->where('company_id', $this->company->id)->value('certifications'));

        $this->actingAs($this->manager)->get(route('agri.trace.index', ['farm' => $this->farmA->uuid]))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->component('Modules/Agri/Trace/Index')
                ->where('lots', fn ($lots) => collect($lots)->contains(fn ($l) => $l['code'] === 'P-0001' && $l['token']['url'] === url('/t/'.$token->token)))
                ->where('settings.defaults.field', true));

        $this->assertContains('throttle:60,1', Route::getRoutes()->getByName('public.trace')->gatherMiddleware(), 'The public page is rate-limited.');
    }
}
