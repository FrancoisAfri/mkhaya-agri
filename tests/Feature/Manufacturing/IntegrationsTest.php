<?php

namespace Tests\Feature\Manufacturing;

use App\Core\Role\Models\Role;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Models\FinStockMovement;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Finance\Accounting\Services\StockLedger;
use App\Modules\Operations\Manufacturing\Models\DowntimeEvent;
use App\Modules\Operations\Manufacturing\Models\DowntimeReason;
use App\Modules\Operations\Manufacturing\Models\Factory;
use App\Modules\Operations\Manufacturing\Models\FactoryPolicy;
use App\Modules\Operations\Manufacturing\Models\Integration;
use App\Modules\Operations\Manufacturing\Models\IntegrationEvent;
use App\Modules\Operations\Manufacturing\Models\IntegrationMapping;
use App\Modules\Operations\Manufacturing\Models\Item;
use App\Modules\Operations\Manufacturing\Models\ProductionOrder as PO;
use App\Modules\Operations\Manufacturing\Models\WorkCentre;
use App\Modules\Operations\Manufacturing\Services\IntegrationHub;
use App\Modules\Operations\Manufacturing\Services\ProductionExecution;
use App\Modules\Operations\Manufacturing\Services\ProductionOrderLifecycle;
use App\Modules\Operations\Manufacturing\Services\ProductionOrders;
use App\Modules\Operations\Manufacturing\Support\RoleCatalog;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * MFG-022: integrations go live only once validated against the customer's edition and access;
 * secrets are encrypted and never shown or logged; every message is processed once however often
 * it arrives; failures retry and end in a dead-letter queue a person can work.
 */
class IntegrationsTest extends ManufacturingTestCase
{
    private Factory $plant;

    private FinWarehouse $store;

    private FinStockItem $steel;

    private Item $frame;

    private $admin;

    private $viewer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->openBooks();
        $admin = Role::firstOrCreate(['name' => 'mfg test integration admin', 'guard_name' => 'web']);
        $admin->syncPermissions(RoleCatalog::all());
        $viewer = Role::firstOrCreate(['name' => 'mfg test integration viewer', 'guard_name' => 'web']);
        $viewer->syncPermissions([RoleCatalog::VIEW_DASHBOARD, RoleCatalog::VIEW_INTEGRATIONS]);
        $this->admin = $this->makeUser('int-admin@example.test', $admin);
        $this->viewer = $this->makeUser('int-viewer@example.test', $viewer);
        $this->grantModuleTier($this->admin);
        $this->grantModuleTier($this->viewer);

        $this->store = $this->warehouse('STORE');
        $this->plant = $this->factory('PLT');
        $this->asCompany($this->company, function () {
            $this->plant->update(['default_warehouse_id' => $this->store->id]);
            FactoryPolicy::for($this->plant->id, $this->company->id)->update(['require_material_for_approval' => false, 'require_routing' => false]);
        });
        $this->steel = $this->stockItem('STEEL', 'kg', 10);
        $this->frame = $this->asCompany($this->company, fn () => Item::create(['company_id' => $this->company->id,
            'stock_item_id' => $this->stockItem('FRAME', 'ea', 0)->id, 'kind' => 'FINISHED', 'default_uom_id' => $this->uom('ea')->id, 'is_active' => true]));
        $this->approvedBom($this->frame, [[$this->steel, 2, 0, 'kg']]);
        $this->asCompany($this->company, fn () => app(StockLedger::class)->receive($this->steel->fresh(), 100, 10, $this->store, ['reference' => 'GRN']));
    }

    private function make(string $connector, string $name): Integration
    {
        $this->actingAs($this->admin)->post(route('manufacturing.integrations.store'), ['name' => $name, 'connector' => $connector])->assertRedirect();

        return $this->asCompany($this->company, fn () => Integration::query()->where('name', $name)->firstOrFail());
    }

    private function live(Integration $i): Integration
    {
        $this->actingAs($this->admin)->post(route('manufacturing.integrations.act', [$i->uuid, 'validate']),
            ['notes' => 'Customer runs the licensed edition; API access confirmed in writing by their IT.'])->assertSessionHas('success');
        $this->actingAs($this->admin)->post(route('manufacturing.integrations.act', [$i->uuid, 'activate']))->assertSessionHas('success');

        return $i->fresh();
    }

    private function signed(Integration $i, array $body)
    {
        $json = json_encode($body);

        return $this->call('POST', route('api.manufacturing.integrations.webhook', $i->uuid), [], [], [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_MKHAYA_SIGNATURE' => hash_hmac('sha256', $json, $i->fresh()->webhook_secret)], $json);
    }

    public function test_an_api_integration_is_validated_before_it_goes_live_and_its_secrets_never_show(): void
    {
        Http::fake(['https://erp.example.test/*' => Http::response(['ok' => true], 200)]);
        $i = $this->make('SYSPRO', 'Head office SYSPRO');
        $this->actingAs($this->admin)->post(route('manufacturing.integrations.act', [$i->uuid, 'activate']))->assertSessionHas('error');

        $this->actingAs($this->admin)->patch(route('manufacturing.integrations.update', $i->uuid), ['name' => 'Head office SYSPRO',
            'base_url' => 'https://erp.example.test', 'auth_type' => 'BASIC', 'settings' => ['health_path' => '/api/health', 'password' => 'should-not-stay'],
            'credentials' => ['username' => 'mkhaya', 'password' => 'S3cret-Passw0rd']])->assertSessionHas('success');
        $raw = DB::table('mfg_integrations')->where('id', $i->id)->value('credentials');
        $this->assertStringNotContainsString('S3cret-Passw0rd', $raw, 'Encrypted at rest.');
        $this->assertSame('[redacted]', $i->fresh()->settings['password'], 'Secrets do not belong in settings.');

        $this->actingAs($this->admin)->post(route('manufacturing.integrations.act', [$i->uuid, 'validate']), ['notes' => 'too short'])->assertSessionHas('error');
        $this->live($i);
        Http::assertSent(fn ($r) => $r->url() === 'https://erp.example.test/api/health' && $r->hasHeader('Authorization'));

        $page = $this->actingAs($this->viewer)->get(route('manufacturing.integrations.show', $i->uuid))->assertOk()->viewData('page');
        $this->assertSame(['username', 'password'], $page['props']['integration']['credential_keys']);
        $this->assertStringNotContainsString('S3cret-Passw0rd', json_encode($page['props']));
        $this->assertFalse(DB::table('activity_log')->where('properties', 'like', '%S3cret%')->exists(), 'Not in the audit trail either.');
        $this->actingAs($this->viewer)->post(route('manufacturing.integrations.act', [$i->uuid, 'pause']))->assertForbidden();
        $this->actingAs($this->outsider)->get(route('manufacturing.integrations.index'))->assertForbidden();
    }

    public function test_a_retried_weighing_issues_material_once_and_a_bad_signature_is_refused(): void
    {
        $order = $this->asCompany($this->company, function () {
            $o = app(ProductionOrders::class)->create(['item' => $this->frame, 'quantity' => 10, 'due_date' => now()->addDays(3)->toDateString(),
                'factory' => $this->plant, 'warehouse' => $this->store], $this->admin);
            $life = app(ProductionOrderLifecycle::class);
            $life->plan($o, $this->admin);
            $life->approve($o->fresh(), $this->admin);
            $life->release($o->fresh(), $this->admin);

            return $o->fresh();
        });
        $scale = $this->live($this->make('SCALE', 'Batching scale'));
        $msg = ['type' => 'WEIGHT_READING', 'id' => 'scale-7781', 'data' => ['order' => $order->number, 'material' => 'STEEL', 'weight' => 12.5]];

        $this->call('POST', route('api.manufacturing.integrations.webhook', $scale->uuid), [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_MKHAYA_SIGNATURE' => 'forged'],
            json_encode($msg))->assertStatus(401);
        $this->signed($scale, $msg)->assertOk()->assertJsonPath('results.0.status', 'PROCESSED');
        $this->signed($scale, $msg)->assertOk()->assertJsonPath('results.0.duplicate', true);
        $this->signed($scale, $msg)->assertOk();

        $this->assertEquals(12.5, (float) $order->components()->first()->issued_qty, 'Three deliveries, one issue.');
        $this->assertSame(1, FinStockMovement::withoutGlobalScopes()->where('source_id', $order->id)->where('type', 'mfg_issue')->count());
        $this->assertSame(2, $this->asCompany($this->company, fn () => IntegrationEvent::query()->firstOrFail()->duplicates));
    }

    public function test_machine_states_open_and_close_downtime_and_failures_end_in_a_dead_letter_queue_a_person_can_clear(): void
    {
        $centre = $this->asCompany($this->company, fn () => WorkCentre::create(['company_id' => $this->company->id, 'factory_id' => $this->plant->id,
            'code' => 'PRS', 'name' => 'Press', 'is_active' => true, 'concurrent_jobs' => 1]));
        $this->asCompany($this->company, fn () => DowntimeReason::query()->where('code', 'BRK')->exists()
            || DowntimeReason::create(['company_id' => $this->company->id, 'code' => 'BRK', 'name' => 'Breakdown', 'category' => 'BREAKDOWN', 'is_planned' => false, 'is_active' => true]));
        $iot = $this->live($this->make('IOT_GATEWAY', 'Plant gateway'));

        // Unknown machine: fails, retries, and ends as a dead letter.
        $this->signed($iot, ['type' => 'MACHINE_STATE', 'id' => 'g-1', 'data' => ['machine' => 'M-17', 'state' => 'STOP', 'api_key' => 'leaky']])->assertOk();
        $event = $this->asCompany($this->company, fn () => IntegrationEvent::query()->firstOrFail());
        $this->assertSame('FAILED', $event->status);
        $this->assertNotNull($event->next_attempt_at);
        $this->assertSame('[redacted]', $event->payload['api_key']);
        foreach (range(2, 5) as $n) {
            $this->asCompany($this->company, fn () => app(IntegrationHub::class)->process($event->fresh()));
        }
        $this->assertSame('DEAD', $event->fresh()->status);

        // Somebody maps the machine and retries it.
        $this->actingAs($this->admin)->post(route('manufacturing.integrations.map', $iot->uuid), ['entity' => 'WORK_CENTRE', 'local_id' => $centre->id, 'external_ref' => 'M-17'])
            ->assertSessionHas('success');
        $this->actingAs($this->admin)->post(route('manufacturing.integrations.events.act', [$event->uuid, 'retry']))->assertSessionHas('success');
        $this->assertSame(1, $this->asCompany($this->company, fn () => DowntimeEvent::query()->where('work_centre_id', $centre->id)->whereNull('ended_at')->count()));

        $this->signed($iot, ['type' => 'MACHINE_STATE', 'id' => 'g-2', 'data' => ['machine' => 'M-17', 'state' => 'RUN']])->assertOk();
        $this->assertSame(0, $this->asCompany($this->company, fn () => DowntimeEvent::query()->where('work_centre_id', $centre->id)->whereNull('ended_at')->count()));
    }

    public function test_files_import_orders_once_and_export_output_once(): void
    {
        $file = $this->live($this->make('FILE', 'Legacy ERP files'));
        foreach ([['PRODUCT', $this->frame->id, 'FRM-001'], ['WAREHOUSE', $this->store->id, 'WH-MAIN']] as [$entity, $local, $ext]) {
            $this->actingAs($this->admin)->post(route('manufacturing.integrations.map', $file->uuid), ['entity' => $entity, 'local_id' => $local, 'external_ref' => $ext]);
        }
        $csv = "id,product,qty,warehouse,due_date\nSO-9001,FRM-001,6,WH-MAIN,".now()->addDays(9)->toDateString()."\n";
        foreach ([1, 2] as $upload) {
            $this->actingAs($this->admin)->post(route('manufacturing.integrations.import', $file->uuid), ['type' => 'ORDER_IMPORT',
                'file' => UploadedFile::fake()->createWithContent('orders.csv', $csv)])->assertSessionHas('success');
        }
        $orders = $this->asCompany($this->company, fn () => PO::query()->where('demand_reference', 'FILE:SO-9001')->get());
        $this->assertCount(1, $orders, 'The same file twice is one order.');
        $this->assertSame(PO::DRAFT, $orders->first()->status, 'An imported order is a draft for people to plan and approve.');

        // Output received after go-live leaves once.
        $order = $orders->first();
        $this->asCompany($this->company, function () use ($order) {
            $life = app(ProductionOrderLifecycle::class);
            $life->plan($order, $this->admin);
            $life->approve($order->fresh(), $this->admin);
            $life->release($order->fresh(), $this->admin);
            app(ProductionExecution::class)->receiveOutput($order->fresh(), 6, $this->admin);
        });
        $first = $this->actingAs($this->admin)->get(route('manufacturing.integrations.export', $file->uuid))->assertOk()->streamedContent();
        $this->assertStringContainsString('FRM-001,WH-MAIN,6', $first);
        $again = $this->actingAs($this->admin)->get(route('manufacturing.integrations.export', $file->uuid))->assertOk()->streamedContent();
        $this->assertStringNotContainsString('FRM-001', $again);

        $page = $this->actingAs($this->admin)->get(route('manufacturing.integrations.show', $file->uuid))->assertOk()->viewData('page');
        $this->assertSame(1, $page['props']['reconciliation']['output_receipts_exported']);
        $this->actingAs($this->admin)->get(route('manufacturing.integrations.index'))->assertOk();
        $this->artisan('mfg:sync-integrations', ['--company' => $this->company->id])->assertSuccessful();
    }
}
