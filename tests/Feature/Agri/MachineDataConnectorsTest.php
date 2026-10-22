<?php

namespace Tests\Feature\Agri;

use App\Modules\Agri\MachineData\MachineDataSync;
use App\Modules\Agri\Models\ActivityInput;
use App\Modules\Agri\Models\Farm;
use App\Modules\Agri\Models\FarmField;
use App\Modules\Agri\Models\FarmMachine;
use App\Modules\Agri\Models\FieldActivity;
use App\Modules\Agri\Models\FieldBoundaryVersion;
use App\Modules\Agri\Models\HarvestRecord;
use App\Modules\Agri\Models\IntegrationEvent;
use App\Modules\Agri\Models\MachineConflict;
use App\Modules\Agri\Models\MachineConnection;
use App\Modules\Agri\Models\MachineLink;
use App\Modules\Agri\Models\MachineOperation;
use App\Modules\Agri\Models\MachineUsageLog;
use App\Modules\Agri\Services\FieldMaps;
use App\Modules\Agri\Support\Geo;
use App\Modules\Tenancy\Models\Company;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * John Deere Operations Center and CNH FieldOps, faked at the HTTP boundary from their documented
 * contracts: the OAuth callback keeps tokens encrypted and an expired one is refreshed; Deere's
 * organisation access grant is waited for; their boundaries become versions of ours with the
 * source noted, or a conflict when both changed; machines are paired by serial number and their
 * engine hours feed the meter; an as-applied operation becomes one field activity with its inputs
 * however often it is imported; a harvest brings its yield and becomes a harvest record on demand.
 */
class MachineDataConnectorsTest extends AgriTestCase
{
    private Farm $north;

    private FarmField $l1;

    private FarmField $l2;

    private FarmMachine $tractor;

    private array $seen = [];

    private string $access = 'at-1';

    private bool $granted = false;

    /** Their fields, machines and operations, changed by the tests between syncs. */
    private array|string $deereFields = [];

    private array $deereOps = [];

    private float $engineHours = 905.5;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        foreach (['deere', 'cnh'] as $p) {
            config(["agri.machine_data.providers.{$p}.client_id" => "{$p}-client", "agri.machine_data.providers.{$p}.client_secret" => "{$p}-secret"]);
        }
        config(['agri.machine_data.providers.cnh.subscription_key' => 'cnh-sub-key']);
        $this->openBooks();
        $this->catalogue();
        $this->north = $this->farm('NORTH');
        $this->member($this->manager, $this->north);
        $this->l1 = $this->field($this->north, 'L1', 10);
        $this->l2 = $this->field($this->north, 'L2', 10);
        $this->asCompany($this->company, fn () => $this->l2->forceFill(['boundary' => $this->square(28.10, -26.00, 0.003)])->save());
        $this->tractor = $this->asCompany($this->company, fn () => app(\App\Modules\Agri\Services\Machinery::class)->register($this->north,
            ['code' => 'T-01', 'name' => 'John Deere 6120M', 'machine_type' => 'tractor', 'hours_reading' => 900, 'service_interval_hours' => 250, 'serial_number' => '1L06120MCKR901234']));
        $this->stockItem('LAN', 'kg', 9.8);

        $this->deereFields = [
            ['id' => 'f-1', 'name' => 'L1', 'activeBoundary' => $this->deereBoundary($this->square(28.00, -26.00, 0.003), 9.8)],
            ['id' => 'f-2', 'name' => 'Land L2', 'activeBoundary' => $this->deereBoundary($this->square(28.10, -26.00, 0.0032), 11.1)],
            ['id' => 'f-3', 'name' => 'Neighbour block', 'activeBoundary' => $this->deereBoundary($this->square(28.20, -26.00, 0.002), 4.4)],
        ];
        $this->deereOps = ['f-1' => [$this->application('op-1', 1500.0)]];
    }

    // ── OAuth ────────────────────────────────────────────────────────────────────────────────

    public function test_deere_connect_waits_for_the_organisation_grant_keeps_tokens_encrypted_and_refreshes(): void
    {
        $this->fakeDeere();
        $c = $this->connect('deere');
        $this->assertSame('needs_org_access', $c->status, 'Signing in is not enough: the organisation must grant the app access.');
        $this->assertSame('4100', $c->org_id);
        $this->assertStringNotContainsString('at-1', (string) $c->getRawOriginal('tokens'), 'Tokens are stored encrypted.');
        $this->assertSame('at-1', json_decode(Crypt::decryptString($c->getRawOriginal('tokens')), true)['access_token']);
        $this->assertSame(1, count($this->seen['token:authorization_code'] ?? []));

        $this->actingAs($this->admin)->get(route('agri.machine-data.index'))->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('Modules/Agri/MachineData/Index')->where('providers.0.status', 'needs_org_access')
                ->where('providers.0.access_url', fn ($u) => str_contains((string) $u, 'connections.deere.com')));
        $this->actingAs($this->admin)->post(route('agri.machine-data.sync', 'deere'))->assertStatus(422);

        $this->granted = true;
        $this->actingAs($this->admin)->post(route('agri.machine-data.recheck', 'deere'))->assertSessionHas('success');
        $this->assertSame('connected', $c->fresh()->status);

        // An expired access token is refreshed with the refresh token, and the new pair is kept.
        $c = $c->fresh();
        $c->putTokens(['access_token' => 'at-1', 'refresh_token' => 'rt-at-1', 'expires_at' => now()->subMinute()->timestamp]);
        $c->save();
        $this->actingAs($this->admin)->post(route('agri.machine-data.sync', 'deere'))->assertSessionHasNoErrors();
        $this->assertCount(1, $this->seen['token:refresh_token'] ?? []);
        $this->assertSame('rt-at-1', $this->seen['token:refresh_token'][0]['refresh_token']);
        $this->assertSame('at-2', $c->fresh()->tokens()['access_token']);
        $this->assertNotNull($c->fresh()->last_sync_at);
    }

    public function test_cnh_connect_choose_company_refresh_and_subscription_key(): void
    {
        $this->fakeCnh();
        $c = $this->connect('cnh');
        $this->assertSame('choose_organisation', $c->status, 'Two companies: the person chooses.');
        $this->actingAs($this->admin)->post(route('agri.machine-data.organisation', 'cnh'), ['org_id' => 'c-9'])->assertSessionHas('success');
        $c = $c->fresh();
        $this->assertTrue($c->isConnected());
        $this->assertSame('Boplaas (CNH)', $c->org_name);
        $this->assertSame('https://api.cnhind.com', $this->seen['token:authorization_code'][0]['audience']);

        $c->putTokens(['access_token' => 'at-1', 'refresh_token' => 'rt-at-1', 'expires_at' => now()->subMinute()->timestamp]);
        $c->save();
        $this->actingAs($this->admin)->post(route('agri.machine-data.sync', 'cnh'))->assertSessionHasNoErrors();
        $this->assertCount(1, $this->seen['token:refresh_token'] ?? []);
        $this->assertSame('at-2', $c->fresh()->tokens()['access_token']);
        foreach ($this->seen['api'] as $r) {
            $this->assertTrue($r->hasHeader('Ocp-Apim-Subscription-Key', 'cnh-sub-key'), 'Every FieldOps call carries the subscription key.');
        }
        $this->assertSame(1, MachineLink::withoutGlobalScopes()->where('kind', 'machine')->where('status', 'linked')->count(), 'The Case IH is paired by its serial number.');

        // Disconnect: the grant is forgotten, the pairings stay.
        $this->actingAs($this->admin)->post(route('agri.machine-data.disconnect', 'cnh'))->assertSessionHas('success');
        $this->assertSame([], $c->fresh()->tokens());
        $this->assertSame('disconnected', $c->fresh()->status);
    }

    public function test_a_platform_without_developer_approval_cannot_connect(): void
    {
        config(['agri.machine_data.providers.deere.client_id' => null]);
        $this->actingAs($this->admin)->get(route('agri.machine-data.connect', 'deere'))->assertSessionHasErrors('provider');
        $this->assertSame('needs_registration', MachineConnection::withoutGlobalScopes()->where('provider', 'deere')->value('status'));
    }

    // ── import ───────────────────────────────────────────────────────────────────────────────

    public function test_boundaries_come_in_as_versions_machines_pair_by_serial_and_hours_feed_the_meter(): void
    {
        $this->connectedDeere();
        $this->sync();

        // L1 had no boundary: theirs is version 1, its source noted.
        $l1 = $this->l1->fresh();
        $this->assertNotNull(Geo::polygon($l1->boundary));
        $v = FieldBoundaryVersion::withoutGlobalScopes()->where('field_id', $l1->id)->sole();
        $this->assertSame('imported', $v->source);
        $this->assertStringContainsString('John Deere Operations Center', $v->source_name);
        // Their third field matches nothing of ours: it waits to be paired or created.
        $this->assertSame('unmatched', MachineLink::withoutGlobalScopes()->where('external_id', 'f-3')->value('status'));
        $link = MachineLink::withoutGlobalScopes()->where('external_id', 'f-3')->first();
        $this->actingAs($this->admin)->post(route('agri.machine-data.fields.link', $link->uuid), ['action' => 'create', 'farm_id' => $this->north->uuid, 'code' => 'N1', 'name' => 'Neighbour'])
            ->assertSessionHasNoErrors();
        $n1 = FarmField::withoutGlobalScopes()->where('code', 'N1')->sole();
        $this->assertSame('imported', FieldBoundaryVersion::withoutGlobalScopes()->where('field_id', $n1->id)->value('source'));
        $this->assertSame('linked', $link->fresh()->status);

        // Machines: the 6120M by its VIN (our serial number); the sprayer is offered to be registered.
        $tLink = MachineLink::withoutGlobalScopes()->where('kind', 'machine')->where('external_id', 'm-1')->sole();
        $this->assertSame(['linked', 'vin', $this->tractor->id], [$tLink->status, $tLink->matched_by, (int) $tLink->local_id]);
        $this->assertSame('unmatched', MachineLink::withoutGlobalScopes()->where('external_id', 'm-2')->value('status'));
        $t = $this->tractor->fresh();
        $this->assertSame('deere:m-1', $t->telematics_ref);
        $this->assertSame(905.5, (float) $t->hours_reading, 'Engine hours came in through the telematics message.');
        $this->assertSame(5.5, (float) MachineUsageLog::withoutGlobalScopes()->where('vehicle_id', $t->id)->sole()->hours);
        $this->assertEqualsWithDelta(-26.001, (float) $t->last_latitude, 0.0001);

        $m2 = MachineLink::withoutGlobalScopes()->where('external_id', 'm-2')->first();
        $this->actingAs($this->admin)->post(route('agri.machine-data.machines.link', $m2->uuid), ['action' => 'create', 'farm_id' => $this->north->uuid, 'code' => 'S-01'])->assertSessionHasNoErrors();
        $s1 = FarmMachine::withoutGlobalScope('farm_access')->where('fleet_number', 'S-01')->sole();
        $this->assertSame(['sprayer', 'PCDR4730XYZ'], [$s1->machine_type, $s1->serial_number]);

        // Hours drive service due as before: the next reading past the interval makes the service due.
        $this->engineHours = 1151.0;
        $t->forceFill(['hours_reading' => 1140])->saveQuietly();
        $this->sync();
        $this->assertTrue($this->tractor->fresh()->serviceDue(), 'The meter reached the service hours.');
    }

    public function test_a_boundary_changed_on_both_sides_is_a_conflict_never_overwritten(): void
    {
        $this->connectedDeere();
        $ours = Geo::polygon($this->l2->fresh()->boundary);
        $this->sync();

        // L2: two different shapes meet for the first time — a conflict, ours untouched.
        $conflict = MachineConflict::withoutGlobalScopes()->where('field_id', $this->l2->id)->sole();
        $this->assertSame('open', $conflict->status);
        $this->assertEquals($ours, Geo::polygon($this->l2->fresh()->boundary));
        $this->assertSame(0, FieldBoundaryVersion::withoutGlobalScopes()->where('field_id', $this->l2->id)->count());
        $this->sync();
        $this->assertSame(1, MachineConflict::withoutGlobalScopes()->where('field_id', $this->l2->id)->count(), 'The same conflict, not a second one.');

        $this->actingAs($this->admin)->post(route('agri.machine-data.conflicts.resolve', $conflict->uuid), ['choice' => 'theirs'])->assertSessionHasNoErrors();
        $this->assertSame('took_theirs', $conflict->fresh()->status);
        $this->assertSame(1, FieldBoundaryVersion::withoutGlobalScopes()->where('field_id', $this->l2->id)->where('source', 'imported')->count());

        // L1 agreed after the first sync. Now both sides change it.
        $this->asCompany($this->company, fn () => app(FieldMaps::class)->setFieldBoundary($this->l1->fresh(), $this->square(28.00, -26.00, 0.0034), $this->admin));
        $ourNew = Geo::polygon($this->l1->fresh()->boundary);
        $this->deereFields[0]['activeBoundary'] = $this->deereBoundary($this->square(28.00, -26.00, 0.0028), 8.5);
        $this->sync();
        $c2 = MachineConflict::withoutGlobalScopes()->where('field_id', $this->l1->id)->where('status', 'open')->sole();
        $this->assertEquals($ourNew, Geo::polygon($this->l1->fresh()->boundary), 'Neither side wins on its own.');

        $this->actingAs($this->admin)->post(route('agri.machine-data.conflicts.resolve', $c2->uuid), ['choice' => 'ours'])->assertSessionHasNoErrors();
        $this->assertSame('kept_ours', $c2->fresh()->status);
        $this->assertCount(1, $this->seen['boundary_post'] ?? [], 'Ours is sent to them.');
        $this->sync();
        $this->assertSame(0, MachineConflict::withoutGlobalScopes()->where('status', 'open')->count());

        // Only theirs changes next: it simply becomes our next version.
        $this->deereFields[0]['activeBoundary'] = $this->deereBoundary($this->square(28.00, -26.00, 0.0031), 9.6);
        $before = FieldBoundaryVersion::withoutGlobalScopes()->where('field_id', $this->l1->id)->count();
        $this->sync();
        $this->assertSame($before + 1, FieldBoundaryVersion::withoutGlobalScopes()->where('field_id', $this->l1->id)->count());
    }

    public function test_an_as_applied_operation_becomes_one_activity_with_inputs_however_often_it_is_imported(): void
    {
        $this->connectedDeere();
        $this->sync();
        $this->sync();

        $op = MachineOperation::withoutGlobalScopes()->where('external_id', 'op-1')->sole();
        $this->assertSame('applied', $op->status);
        $this->assertSame('op-1', $op->raw['operation']['id'], 'The raw payload is kept for audit.');
        $this->assertSame((int) $this->tractor->id, (int) $op->machine_id);
        $activity = FieldActivity::withoutGlobalScopes()->sole();
        $this->assertSame([$this->l1->id, 'fertilising', 'in_progress', (int) $this->tractor->id], [(int) $activity->field_id, $activity->activity_type, $activity->status, (int) $activity->machine_id]);
        $this->assertSame(10.0, (float) $activity->area_covered);
        $this->assertStringContainsString('Operator: J. Mokoena', (string) $activity->notes);
        $input = ActivityInput::withoutGlobalScopes()->sole();
        $this->assertSame([1500.0, 150.0, 'planned'], [(float) $input->quantity, (float) $input->rate_per_ha, $input->status]);
        $this->assertGreaterThanOrEqual(1, (int) IntegrationEvent::withoutGlobalScopes()->where('integration', 'machine_import')->sum('replays'), 'The second import was answered from the first.');

        // Corrected on their side: the same activity's input follows, nothing is doubled.
        $this->deereOps['f-1'] = [$this->application('op-1', 1550.0)];
        $this->sync();
        $this->assertSame(1, FieldActivity::withoutGlobalScopes()->count());
        $this->assertSame(1550.0, (float) ActivityInput::withoutGlobalScopes()->sole()->quantity);
    }

    public function test_an_unknown_product_waits_and_applies_once_paired(): void
    {
        $this->connectedDeere();
        $this->deereOps = ['f-1' => [$this->application('op-9', 900.0, 'Urea granular 46')]];
        $this->stockItem('UREA', 'kg', 11);
        $this->sync();
        $op = MachineOperation::withoutGlobalScopes()->where('external_id', 'op-9')->sole();
        $this->assertSame('needs_product', $op->status);
        $this->assertSame(0, FieldActivity::withoutGlobalScopes()->count());

        $link = MachineLink::withoutGlobalScopes()->where('kind', 'product')->where('status', 'unmatched')->sole();
        $item = \App\Modules\Finance\Accounting\Models\FinStockItem::withoutGlobalScopes()->where('code', 'UREA')->sole();
        $this->actingAs($this->admin)->post(route('agri.machine-data.products.link', $link->uuid), ['stock_item_id' => $item->id])->assertSessionHasNoErrors();
        $this->assertSame('applied', $op->fresh()->status);
        $this->assertSame((int) $item->id, (int) ActivityInput::withoutGlobalScopes()->sole()->stock_item_id);
    }

    public function test_yield_comes_in_and_is_recorded_as_a_harvest_on_demand(): void
    {
        $plan = $this->planted();
        $this->connectedDeere();
        $this->deereOps = ['f-1' => [['id' => 'op-h', 'fieldOperationType' => 'harvest', 'startDate' => now()->toIso8601String(), 'endDate' => now()->toIso8601String(),
            'cropName' => 'CORN_WET', 'machines' => [['id' => 'm-1']]]]];
        $this->sync();

        $op = MachineOperation::withoutGlobalScopes()->where('external_id', 'op-h')->sole();
        $this->assertSame(['harvest', 'imported', 72.0, 't', 7.2, 14.5], [$op->operation_type, $op->status, $op->total, $op->total_unit, $op->yield_per_ha, $op->moisture_pct]);
        $this->assertCount(2, $op->zones, 'Yield by zone, where the data has it.');
        $this->assertSame(0, HarvestRecord::withoutGlobalScopes()->count(), 'A combine estimate is not a harvest until somebody says so.');

        $this->actingAs($this->admin)->post(route('agri.machine-data.operations.harvest', $op->uuid), ['destination' => 'own_use'])->assertSessionHasNoErrors();
        $h = HarvestRecord::withoutGlobalScopes()->sole();
        $this->assertSame([(int) $plan->id, 72.0, 't', 14.5, 'yield_monitor'], [(int) $h->crop_plan_id, (float) $h->quantity, $h->unit, (float) $h->moisture_pct, $h->measuring_method]);
        $this->assertSame((int) $h->id, (int) $op->fresh()->harvest_record_id);
        $this->actingAs($this->admin)->post(route('agri.machine-data.operations.harvest', $op->uuid), ['destination' => 'own_use'])->assertSessionHasErrors('operation');
    }

    public function test_a_failed_pull_is_logged_and_retried(): void
    {
        $this->connectedDeere();
        $this->deereFields = 'boom';
        $this->actingAs($this->admin)->post(route('agri.machine-data.sync', 'deere'))->assertSessionHas('error');
        $e = IntegrationEvent::withoutGlobalScopes()->where('integration', 'machine_pull')->sole();
        $this->assertSame('failed', $e->status);
        $this->assertStringContainsString('Service unavailable', (string) $e->error);
        $this->assertNotNull(MachineConnection::withoutGlobalScopes()->where('provider', 'deere')->value('last_error'));

        $this->deereFields = [];
        $this->actingAs($this->admin)->post(route('agri.machine-data.retry', $e->uuid))->assertSessionHas('success');
        $this->assertSame('processed', $e->fresh()->status);
    }

    // ── permissions, tenants, pages ─────────────────────────────────────────────────────────

    public function test_permissions_tenant_isolation_and_pages(): void
    {
        $this->connectedDeere();
        $this->sync();
        $this->actingAs($this->outsider)->get(route('agri.machine-data.index'))->assertForbidden();
        $this->actingAs($this->worker)->get(route('agri.machine-data.index'))->assertForbidden();
        $this->actingAs($this->worker)->post(route('agri.machine-data.sync', 'deere'))->assertForbidden();
        foreach (['connections', 'fields', 'machines', 'operations', 'conflicts', 'log'] as $tab) {
            $this->actingAs($this->manager)->get(route('agri.machine-data.index', ['tab' => $tab, 'provider' => 'deere']))->assertOk()
                ->assertInertia(fn (Assert $p) => $p->component('Modules/Agri/MachineData/Index')->where('tab', $tab));
        }
        $this->actingAs($this->admin)->get(route('agri.machine-data.index', ['tab' => 'operations']))->assertInertia(fn (Assert $p) => $p->has('data.operations', 1)
            ->where('data.operations.0.external_id', 'op-1')->where('data.operations.0.status', 'applied'));
        $raw = $this->actingAs($this->admin)->get(route('agri.machine-data.operations.raw', MachineOperation::withoutGlobalScopes()->sole()->uuid))->assertOk()->json();
        $this->assertSame('application', $raw['operation']['fieldOperationType']);

        $other = Company::create(['name' => 'Ander Plaas']);
        $this->entitle($other);
        $otherAdmin = $this->makeUser('admin@ander.test', \App\Core\Role\Models\Role::firstOrCreate(['name' => 'agri test org admin', 'guard_name' => 'web']), $other);
        $this->actingAs($otherAdmin)->get(route('agri.machine-data.index', ['tab' => 'fields']))->assertOk()
            ->assertInertia(fn (Assert $p) => $p->where('providers.0.status', 'disconnected')->where('providers.0.org_id', null)->has('data.links', 0));
        $link = MachineLink::withoutGlobalScopes()->where('company_id', $this->company->id)->where('external_id', 'f-3')->first();
        $this->actingAs($otherAdmin)->post(route('agri.machine-data.fields.link', $link->uuid), ['action' => 'ignore'])->assertNotFound();
        $op = MachineOperation::withoutGlobalScopes()->sole();
        $this->actingAs($otherAdmin)->get(route('agri.machine-data.operations.raw', $op->uuid))->assertNotFound();
        $this->assertSame(0, $this->asCompany($other, fn () => MachineOperation::query()->count()));
    }

    // ── helpers ──────────────────────────────────────────────────────────────────────────────

    private function connect(string $provider): MachineConnection
    {
        $r = $this->actingAs($this->admin)->get(route('agri.machine-data.connect', $provider));
        $loc = $r->headers->get("X-Inertia-Location") ?? $r->headers->get("Location");
        if (! $loc && $r->exception) { throw $r->exception; }
        $this->assertNotNull($loc, 'Connect sends the person to the platform.');
        parse_str((string) parse_url($loc, PHP_URL_QUERY), $q);
        $this->assertSame("{$provider}-client", $q['client_id']);
        $this->assertSame(route('agri.machine-data.callback', $provider), $q['redirect_uri']);
        $this->actingAs($this->admin)->get(route('agri.machine-data.callback', ['provider' => $provider, 'code' => 'code-1', 'state' => $q['state']]))->assertRedirect();

        return MachineConnection::withoutGlobalScopes()->where('company_id', $this->company->id)->where('provider', $provider)->sole();
    }

    private function connectedDeere(): MachineConnection
    {
        $this->granted = true;
        $this->fakeDeere();

        return $this->connect('deere');
    }

    private function sync(): IntegrationEvent
    {
        $c = MachineConnection::withoutGlobalScopes()->where('company_id', $this->company->id)->where('provider', 'deere')->sole();
        $e = $this->asCompany($this->company, fn () => app(MachineDataSync::class)->sync($c, 'now', $this->admin));
        $this->assertSame('processed', $e->status, (string) $e->error);

        return $e;
    }

    private function planted(): \App\Modules\Agri\Models\CropPlan
    {
        $this->actingAs($this->admin)->post(route('agri.crops.plans.store'), ['farm_id' => $this->north->uuid, 'field_id' => $this->l1->uuid, 'season_id' => $this->seasonId(),
            'crop_type_id' => $this->cropTypeId(), 'code' => 'MZ-1', 'planned_area' => 10, 'area_unit' => 'ha', 'planned_planting_on' => today()->subMonths(4)->toDateString(),
            'planned_harvest_on' => today()->addWeeks(4)->toDateString(), 'expected_yield' => 7, 'yield_unit' => 't'])->assertSessionHasNoErrors();
        $plan = \App\Modules\Agri\Models\CropPlan::withoutGlobalScopes()->where('code', 'MZ-1')->firstOrFail();
        $this->actingAs($this->admin)->post(route('agri.crops.plans.transition', $plan->uuid), ['action' => 'approve'])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post(route('agri.crops.plans.transition', $plan->uuid), ['action' => 'plant'])->assertSessionHasNoErrors();

        return $plan->fresh();
    }

    private function square(float $lng, float $lat, float $size): array
    {
        return ['type' => 'Polygon', 'coordinates' => [[[$lng, $lat], [$lng + $size, $lat], [$lng + $size, $lat - $size], [$lng, $lat - $size], [$lng, $lat]]]];
    }

    private function deereBoundary(array $g, float $ha): array
    {
        return ['id' => 'b-'.md5(json_encode($g)), 'active' => true, 'area' => ['valueAsDouble' => $ha, 'unit' => 'ha'], 'lastModifiedTime' => now()->toIso8601String(),
            'multipolygons' => [['rings' => [['type' => 'exterior', 'passable' => true, 'points' => array_map(fn ($c) => ['lat' => $c[1], 'lon' => $c[0]], $g['coordinates'][0])]]]]];
    }

    private function application(string $id, float $total, string $product = 'LAN (test)'): array
    {
        return ['id' => $id, 'fieldOperationType' => 'application', 'startDate' => now()->subDays(2)->toIso8601String(), 'endDate' => now()->subDays(2)->addHours(3)->toIso8601String(),
            'modifiedTime' => now()->toIso8601String(), 'machines' => [['id' => 'm-1']], 'operators' => [['name' => 'J. Mokoena']],
            '_measurement' => ['area' => ['value' => 10.0, 'unit' => 'ha'], 'averageMaterial' => ['value' => $total / 10, 'unit' => 'kg1ha'], 'totalMaterial' => ['value' => $total, 'unit' => 'kg'],
                'productTotals' => [['name' => $product, 'averageMaterial' => ['value' => $total / 10, 'unit' => 'kg1ha'], 'totalMaterial' => ['value' => $total, 'unit' => 'kg'], 'area' => ['value' => 10.0, 'unit' => 'ha']]]]];
    }

    private function fakeDeere(): void
    {
        $this->seen = [];
        Http::fake(function (HttpRequest $r) {
            $url = $r->url();
            $path = (string) parse_url($url, PHP_URL_PATH);
            if (str_contains($url, 'signin.johndeere.com') && str_ends_with($path, '/v1/token')) {
                $this->seen['token:'.$r['grant_type']][] = $r->data();
                $this->access = $r['grant_type'] === 'refresh_token' ? 'at-2' : 'at-1';

                return Http::response(['access_token' => $this->access, 'refresh_token' => 'rt-'.$this->access, 'expires_in' => 43200, 'token_type' => 'Bearer']);
            }
            if (! $r->hasHeader('Authorization', 'Bearer '.$this->access)) {
                return Http::response(['errors' => [['message' => 'stale token']]], 401);
            }
            $this->assertTrue($r->hasHeader('Accept', 'application/vnd.deere.axiom.v3+json'), "Deere calls ask for the axiom v3 media type ({$path}).");
            $this->seen['api'][] = $r;
            $p = substr($path, strlen('/platform'));
            $m = $r->method();
            $ops = collect($this->deereOps)->flatten(1)->keyBy('id');

            return match (true) {
                $p === '/organizations' => Http::response(['values' => [['id' => '4100', 'name' => 'Boplaas Boerdery', 'type' => 'customer', 'member' => true,
                    'links' => $this->granted ? [['rel' => 'self', 'uri' => 'https://partnerapi.deere.com/platform/organizations/4100']]
                        : [['rel' => 'connections', 'uri' => 'https://connections.deere.com/connections/deere-client/select-organizations']]]]]),
                $p === '/organizations/4100/fields' && $m === 'GET' => is_array($this->deereFields) ? Http::response(['values' => $this->deereFields, 'links' => []])
                    : Http::response(['errors' => [['message' => 'Service unavailable']]], 503),
                $p === '/organizations/4100/fields' && $m === 'POST' => Http::response(null, 201, ['Location' => 'https://partnerapi.deere.com/platform/organizations/4100/fields/f-new']),
                (bool) preg_match('#^/organizations/4100/fields/([^/]+)/boundaries$#', $p, $mm) => tap(Http::response(null, 201, ['Location' => 'https://partnerapi.deere.com/platform/boundaries/b-new']),
                    function () use ($r, $mm) {
                        $this->seen['boundary_post'][] = $r;
                        // What we send is what they then hold.
                        foreach ($this->deereFields as $i => $f) {
                            if ($f['id'] === $mm[1]) {
                                $this->deereFields[$i]['activeBoundary'] = ['id' => 'b-sent', 'active' => true, 'area' => ['valueAsDouble' => 0, 'unit' => 'ha'], 'multipolygons' => $r['multipolygons']];
                            }
                        }
                    }),
                (bool) preg_match('#^/organizations/4100/fields/([^/]+)/fieldOperations$#', $p, $mm) => Http::response(['values' => array_map(fn ($o) => array_diff_key($o, ['_measurement' => 1, '_moisture' => 1]),
                    $this->deereOps[$mm[1]] ?? [])]),
                (bool) preg_match('#^/fieldOperations/([^/]+)/measurementTypes/(\w+)$#', $p, $mm) => $this->deereMeasurement($ops[$mm[1]] ?? null, $mm[2]),
                $p === '/organizations/4100/machines' => Http::response(['values' => [
                    ['id' => 'm-1', 'name' => '6120M', 'vin' => '1L06120MCKR901234', 'equipmentMake' => ['name' => 'John Deere'], 'equipmentModel' => ['name' => '6120M'], 'equipmentType' => ['name' => 'Tractor']],
                    ['id' => 'm-2', 'name' => 'R4030 sprayer', 'serialNumber' => 'PCDR4730XYZ', 'equipmentMake' => ['name' => 'John Deere'], 'equipmentModel' => ['name' => 'R4030'], 'equipmentType' => ['name' => 'Sprayer']],
                ]]),
                $p === '/machines/m-1/engineHours' => Http::response(['values' => [['reading' => ['valueAsDouble' => $this->engineHours, 'unit' => 'Hours'], 'reportTime' => now()->subMinutes(5)->addSeconds((int) $this->engineHours)->toIso8601String()]]]),
                $p === '/machines/m-1/locationHistory' => Http::response(['values' => [['point' => ['lat' => -26.001, 'lon' => 28.001], 'eventTimestamp' => now()->subMinutes(5)->toIso8601String()]]]),
                str_starts_with($p, '/machines/') => Http::response(['values' => []]),
                $p === '/organizations/4100/files' && $m === 'POST' => tap(Http::response(null, 201, ['Location' => 'https://partnerapi.deere.com/platform/files/file-77']), fn () => $this->seen['file_post'][] = $r),
                $p === '/files/file-77' && $m === 'PUT' => tap(Http::response(null, 204), fn () => $this->seen['file_put'][] = $r),
                $p === '/files/file-77' => Http::response(['id' => 'file-77', 'status' => 'Ready']),
                default => Http::response(['errors' => [['message' => "Unexpected {$m} {$p}"]]], 404),
            };
        });
    }

    private function deereMeasurement(?array $op, string $type)
    {
        if (! $op) {
            return Http::response(['errors' => [['message' => 'No operation']]], 404);
        }
        if ($op['fieldOperationType'] === 'harvest') {
            return $type === 'HarvestMoistureResult' ? Http::response(['averageMoisture' => ['value' => 14.5, 'unit' => 'prcnt']])
                : Http::response(['area' => ['value' => 10.0, 'unit' => 'ha'], 'averageYield' => ['value' => 7.2, 'unit' => 't1ha'], 'totalYield' => ['value' => 72.0, 'unit' => 't'],
                    'yieldZones' => [['name' => 'North', 'averageYield' => ['value' => 7.9]], ['name' => 'South', 'averageYield' => ['value' => 6.4]]]]);
        }

        return Http::response($op['_measurement']);
    }

    private function fakeCnh(): void
    {
        $this->seen = [];
        Http::fake(function (HttpRequest $r) {
            $url = $r->url();
            $path = (string) parse_url($url, PHP_URL_PATH);
            if (str_contains($url, 'identity.cnhind.com/oauth/token')) {
                $this->seen['token:'.$r['grant_type']][] = $r->data();
                $this->access = $r['grant_type'] === 'refresh_token' ? 'at-2' : 'at-1';

                return Http::response(['access_token' => $this->access, 'refresh_token' => 'rt-'.$this->access, 'expires_in' => 3600]);
            }
            if (! $r->hasHeader('Authorization', 'Bearer '.$this->access)) {
                return Http::response(['message' => 'stale token'], 401);
            }
            $this->seen['api'][] = $r;
            $p = substr($path, strlen('/v1'));

            return match (true) {
                $p === '/companies' => Http::response(['data' => [['id' => 'c-9', 'name' => 'Boplaas (CNH)'], ['id' => 'c-10', 'name' => 'Contractor fleet']]]),
                $p === '/companies/c-9/fields' => Http::response(['data' => [['id' => 'cf-1', 'name' => 'L1', 'boundary' => $this->square(28.00, -26.00, 0.003), 'area' => ['value' => 9.8, 'unit' => 'ha']]], 'links' => []]),
                $p === '/companies/c-9/vehicles' => Http::response(['data' => [['id' => 'v-1', 'name' => 'Axial-Flow 7150', 'serialNumber' => 'YGG123456', 'brand' => 'Case IH', 'model' => '7150', 'type' => 'combine']]]),
                $p === '/vehicles/v-1/telemetry/latest' => Http::response(['engineHours' => 3141.0, 'latitude' => -26.0, 'longitude' => 28.0, 'timestamp' => now()->toIso8601String()]),
                $p === '/companies/c-9/operations' => Http::response(['data' => []]),
                default => Http::response(['message' => "Unexpected {$r->method()} {$p}"], 404),
            };
        });
        $this->asCompany($this->company, fn () => app(\App\Modules\Agri\Services\Machinery::class)->register($this->north,
            ['code' => 'H-01', 'name' => 'Case IH Axial-Flow 7150', 'machine_type' => 'harvester', 'hours_reading' => 3139, 'serial_number' => 'YGG123456']));
    }
}
