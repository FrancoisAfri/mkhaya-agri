<?php

namespace Tests\Feature\Agri;

use App\Modules\Agri\Models\FarmAlert;
use App\Modules\Agri\Services\FarmAlerts;
use App\Modules\HR\Hr\Attendance\Models\Timesheet;
use Illuminate\Support\Facades\DB;

/**
 * Alerts reach every part of the farm — a machine stopped by a fault, a cold store out of range with
 * nothing done, time waiting for approval, a short-staffed shift, failed integration messages — and
 * the dashboard shows every area the person may open, each with its figures.
 */
class AlertsEveryAreaTest extends AgriTestCase
{
    public function test_alerts_cover_machines_cold_stores_time_roster_and_integrations_and_clear_when_dealt_with(): void
    {
        $farm = $this->farm('NORTH');
        $this->member($this->manager, $farm);
        $this->asCompany($this->company, function () use ($farm) {
            $m = app(\App\Modules\Agri\Services\Machinery::class)->register($farm, ['code' => 'T-01', 'name' => 'Tractor', 'machine_type' => 'tractor', 'hours_reading' => 1000]);
            \App\Modules\Agri\Models\MachineFault::create(['company_id' => $this->company->id, 'farm_id' => $farm->id, 'machine_id' => $m->id, 'severity' => 'critical',
                'description' => 'Hydraulic pump failed', 'stops_machine' => true, 'status' => 'open', 'down_from' => now()]);
            $store = app(\App\Modules\Agri\Services\FarmInventory::class)->addStore($farm, ['kind' => 'cold_room', 'name' => 'Cold room 1']);
            $store->update(['temperature_min' => 0, 'temperature_max' => 4]);
            app(\App\Modules\Agri\Services\PostHarvest::class)->reading($store->fresh(), ['temperature' => 9.5], $this->manager);
            $w = $this->worker($farm, 'Anna');
            Timesheet::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'farm_id' => $farm->id, 'worker_id' => $w->id, 'work_date' => today()->subDays(4),
                'clock_in_at' => today()->subDays(4)->setTime(7, 0), 'clock_out_at' => today()->subDays(4)->setTime(15, 0), 'worked_minutes' => 450, 'status' => Timesheet::COMPLETE]);
            $tpl = $this->template('DAY', '07:00', '15:00', 30, ['min_staff' => 3]);
            app(\App\Modules\HR\Hr\Attendance\Services\Rostering::class)->fromTemplate($farm, $tpl, today()->addDays(2), $this->manager);
            DB::table('agri_integration_events')->insert(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'company_id' => $this->company->id, 'farm_id' => $farm->id,
                'integration' => 'weather', 'direction' => 'out', 'idempotency_key' => 'x1', 'payload' => '{}', 'status' => 'dead', 'attempts' => 5,
                'error' => 'Provider unreachable', 'created_at' => now(), 'updated_at' => now()]);
        });

        $this->asCompany($this->company, fn () => app(FarmAlerts::class)->scan($this->company->id));
        $open = FarmAlert::withoutGlobalScopes()->where('company_id', $this->company->id)->whereIn('status', [FarmAlert::OPEN])->get()->keyBy('kind');
        foreach (['machine_fault', 'cold_store', 'timesheets', 'roster_gap', 'roster_draft', 'integration'] as $kind) {
            $this->assertTrue($open->has($kind), "No {$kind} alert; raised: ".$open->keys()->implode(', '));
        }
        $this->assertSame('critical', $open['machine_fault']->severity);
        $this->assertStringStartsWith('Machine stopped', $open['machine_fault']->title);

        // Dealt with: the corrective action is recorded, so the cold-store alert resolves on the next scan.
        $this->asCompany($this->company, function () {
            $r = \App\Modules\Agri\Models\StorageReading::withoutGlobalScopes()->where('out_of_range', true)->sole();
            app(\App\Modules\Agri\Services\PostHarvest::class)->correct($r, 'Door closed; compressor reset', $this->manager);
            app(FarmAlerts::class)->scan($this->company->id);
        });
        $this->assertSame(FarmAlert::RESOLVED, FarmAlert::withoutGlobalScopes()->where('kind', 'cold_store')->value('status'));

        // The dashboard shows every area the manager may open, with its figures.
        $areas = collect($this->actingAs($this->manager)->get(route('agri.dashboard'))->assertOk()->viewData('page')['props']['areas'])->keyBy('key');
        foreach (['work', 'people', 'piecework', 'packhouse', 'water', 'compliance'] as $key) {
            $this->assertTrue($areas->has($key), "No {$key} area; shown: ".$areas->keys()->implode(', '));
        }
        $people = collect($areas['people']['figures'])->keyBy('label');
        $this->assertSame(1, $people['Timesheets to approve']['value']);
        // A worker sees only the areas their permissions open.
        $this->assertFalse(collect($this->actingAs($this->worker)->get(route('agri.dashboard'))->viewData('page')['props']['areas'])->contains('key', 'integrations'));
    }
}
