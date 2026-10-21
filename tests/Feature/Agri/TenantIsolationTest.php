<?php

namespace Tests\Feature\Agri;

use App\Modules\Agri\Models\ProduceLot;
use App\Modules\Agri\Models\SalesContract;
use App\Modules\Agri\Models\WeatherRule;
use App\Modules\HR\Hr\Attendance\Services\ClockDevices;
use App\Modules\Agri\Support\RoleCatalog;
use App\Modules\Tenancy\Models\Company;
use Illuminate\Support\Facades\DB;
use App\Core\Role\Models\Role;

/**
 * Phase 2 §15.1: one organisation cannot read or change another's records — through the Phase 2
 * pages, their actions, the exports, the device APIs or the assistant — even with every agri
 * permission and the other organisation's identifiers in hand. The database refuses it as well.
 */
class TenantIsolationTest extends AgriTestCase
{
    public function test_another_organisation_with_every_permission_gets_nothing_of_ours(): void
    {
        $this->catalogue();
        $this->openBooks();
        // Our records.
        $farm = $this->farm('NORTH');
        $this->member($this->manager, $farm);
        $store = $this->asCompany($this->company, fn () => app(\App\Modules\Agri\Services\FarmInventory::class)->addStore($farm, ['kind' => 'cold_room', 'name' => 'Ours']));
        $this->asCompany($this->company, fn () => $store->update(['sensor_ref' => 'OURS-1']));
        $machine = $this->asCompany($this->company, fn () => app(\App\Modules\Agri\Services\Machinery::class)->register($farm, ['code' => 'OUR-T', 'name' => 'Our tractor', 'machine_type' => 'tractor', 'hours_reading' => 1]));
        $lot = $this->asCompany($this->company, fn () => ProduceLot::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'farm_id' => $farm->id, 'code' => 'OUR-LOT',
            'kind' => 'harvest', 'product' => 'Apples', 'quantity' => 10, 'remaining' => 10, 'unit' => 'kg', 'produced_on' => today(), 'status' => 'available']));
        $doc = $this->fileDocument($farm, 'lease', 'Our lease');
        $buyer = $this->asCompany($this->company, fn () => app(\App\Modules\Shared\Party\PartyDirectory::class)->create(['company_id' => $this->company->id, 'kind' => 'customer', 'name' => 'Our buyer'], 'finance'));
        $rule = $this->asCompany($this->company, fn () => WeatherRule::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'name' => 'Our rule', 'activity' => 'frost',
            'conditions' => [['metric' => 'temperature', 'op' => '<', 'value' => 2]], 'advice' => 'x', 'basis' => 'y', 'status' => 'draft', 'created_by' => $this->manager->id]));

        // Them: a whole organisation, its owner holding every agri permission.
        $other = Company::create(['name' => 'Ander Boerdery']);
        $this->entitle($other);
        $role = Role::firstOrCreate(['name' => 'other org owner', 'guard_name' => 'web']);
        $role->syncPermissions(RoleCatalog::everything());
        $them = $this->makeUser('owner@ander.test', $role, $other);
        $theirFarm = $this->farm('ANDER', $other);
        $this->member($them, $theirFarm);

        // Pages: they see only their own. (A fresh session: our own flash messages are not theirs.)
        $this->flushSession();
        foreach ([['agri.packhouse', 'lots'], ['agri.sales', 'contracts'], ['agri.weather', 'rules'], ['agri.machinery.fleet', 'risk'],
            ['agri.finance.intelligence', 'reconcile'], ['agri.insights', 'owner']] as [$name, $tab]) {
            $html = json_encode($this->actingAs($them)->get(route($name, [$tab, 'farm' => $farm->uuid]))->assertOk()->viewData('page')['props']);
            foreach (['OUR-LOT', 'Our buyer', 'Our rule', 'OUR-T', 'Farm NORTH'] as $ours) {
                $this->assertStringNotContainsString($ours, $html, "{$name}/{$tab} showed {$ours}");
            }
        }

        // Records by their identifiers: not found, and nothing changes.
        $this->actingAs($them)->getJson(route('agri.packhouse.lots.trace', $lot->uuid))->assertNotFound();
        $this->actingAs($them)->post(route('agri.packhouse.lots.recall', $lot->uuid), ['reason' => 'x', 'severity' => 'high'])->assertNotFound();
        // Our farm records are Document Management documents: theirs to neither open nor approve.
        $this->assertContains($this->actingAs($them)->get(route('document-management.documents.show', $doc->uuid))->status(), [403, 404]);
        $this->actingAs($them)->post(route('agri.weather.rules.approve', $rule->uuid))->assertNotFound();
        $this->actingAs($them)->getJson(route('agri.machinery.costs', $machine->uuid))->assertNotFound();
        $this->actingAs($them)->post(route('agri.machinery.out-of-service', $machine->uuid), ['reason' => 'x'])->assertNotFound();
        $this->actingAs($them)->post(route('agri.safety.report'), ['farm_id' => $farm->uuid, 'kind' => 'hazard', 'description' => 'x'])->assertNotFound();
        $this->assertSame('submitted', $doc->fresh()->approval_status);
        $this->assertSame('draft', $rule->fresh()->status);
        $this->assertSame('available', $lot->fresh()->status);

        // Exports and the assistant.
        $csv = $this->actingAs($them)->get(route('agri.insights.export', ['owner', 'production']))->assertOk()->streamedContent();
        $this->assertStringNotContainsString('NORTH', $csv);
        $a = $this->actingAs($them)->postJson(route('agri.assistant.ask'), ['question' => 'Which machines should be inspected first?'])->assertOk()->json();
        $this->assertStringNotContainsString('OUR-T', json_encode($a));

        // Devices: their gateway cannot write into our store, even naming our sensor.
        [$gw, $secret] = $this->asCompany($other, fn () => app(ClockDevices::class)->register($theirFarm, 'Their gateway', 'sensor_gateway', $them));
        $r = $this->withToken($secret)->postJson(route('api.agri.devices.readings', $gw->uuid), ['readings' => [['sensor' => 'OURS-1', 'temperature' => 30]]])->assertOk()->json('results');
        $this->assertFalse($r[0]['ok']);
        $this->assertSame(0, DB::table('storage_readings')->where('store_id', $store->id)->count());

        // And the database itself: with an organisation set on the connection (as the tenancy
        // middleware does for every request), row-level security returns only that organisation's rows.
        // That includes Accounting: the buyer is an Accounting customer, and the shared chart of accounts
        // (no organisation) stays visible to everyone.
        $probe = function (int $companyId) use ($lot, $rule, $buyer) {
            DB::select("SELECT set_config('app.company_id', ?, true)", [(string) $companyId]);
            try {
                return [DB::table('produce_lots')->where('id', $lot->id)->count(), DB::table('agri_weather_rules')->where('id', $rule->id)->count(),
                    DB::table('fin_parties')->where('id', $buyer->id)->count()];
            } finally {
                DB::select("SELECT set_config('app.company_id', '', true)");
            }
        };
        $this->assertSame([0, 0, 0], $probe($other->id), 'Row-level security hides another organisation\'s rows.');
        $this->assertSame([1, 1, 1], $probe($this->company->id), 'and shows the organisation its own.');
    }
}
