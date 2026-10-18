<?php

namespace Tests\Feature\Warehouse;

use App\Modules\Finance\Accounting\Services\StockLedger;
use App\Modules\Operations\WarehouseManagement\Models\Carrier;
use App\Modules\Operations\WarehouseManagement\Models\Webhook;
use App\Modules\Operations\WarehouseManagement\Services\FulfilmentOrders;
use App\Modules\Operations\WarehouseManagement\Services\ItemCatalogue;
use App\Modules\Operations\WarehouseManagement\Services\LocationService;
use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/**
 * QA-001: a person of one company who changes an identifier to another company's never sees or
 * changes that company's data -- across every warehouse route, web and API.
 *
 * Company B is given records carrying marker values. A manager of company A holding every
 * warehouse permission calls every route with each of B's identifiers. No response may contain a
 * marker, and B's records must be exactly as they were.
 *
 * Note: the test database connects as a superuser, which ignores row-level security; this proves
 * the application's own scoping. RLS is the second wall (see docs/warehouse/security.md).
 */
class TenantIsolationTest extends WarehouseTestCase
{
    private const MARKERS = ['ZB-SECRET-WH', 'ZB-SECRET-ITEM', 'ZB-SECRET-ORDER', 'ZB Secret Carrier', 'zb-secret-hook', 'ZB-SECRET-LOT'];

    private Company $b;

    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->openBooks();
        $this->b = Company::create(['name' => 'Other Co '.Str::random(4)]);
        $this->artisan('finance:ensure-chart', ['--company' => $this->b->id, '--install' => true]);
        $this->asCompany($this->b, function () {
            $wh = \App\Modules\Finance\Accounting\Models\FinWarehouse::create(['company_id' => $this->b->id, 'code' => 'ZB-SECRET-WH', 'name' => 'ZB-SECRET-WH', 'is_active' => true, 'is_wms_managed' => true]);
            app(ItemCatalogue::class)->ensureUnits($this->b->id);
            $bin = app(LocationService::class)->create($wh, ['code' => 'ZB1']);
            $item = app(ItemCatalogue::class)->create(['company_id' => $this->b->id, 'code' => 'ZB-SECRET-ITEM', 'name' => 'ZB-SECRET-ITEM', 'uom' => 'ea', 'standard_cost' => 5]);
            $lot = app(StockLedger::class)->lot($item->fresh(), 'ZB-SECRET-LOT');
            app(StockLedger::class)->receive($item->fresh(), 9, 5, $wh, ['bin_id' => $bin->id, 'lot_id' => $lot->id]);
            [$order] = app(FulfilmentOrders::class)->create($wh, 'ECOMMERCE', 'ZB-SECRET-ORDER', [['item_id' => $item->id, 'qty' => 1]], ['customer_name' => 'ZB-SECRET-ORDER']);
            $carrier = Carrier::create(['company_id' => $this->b->id, 'code' => 'ZBC', 'name' => 'ZB Secret Carrier', 'driver' => 'MANUAL']);
            $hook = Webhook::create(['company_id' => $this->b->id, 'name' => 'zb-secret-hook', 'url' => 'https://203.0.113.9/x', 'secret' => 's', 'secret_hint' => 'xxxx', 'event_types' => ['warehouse.receipt.posted']]);
            $run = DB::table('wms_report_runs')->insertGetId(['uuid' => (string) Str::uuid(), 'company_id' => $this->b->id, 'report' => 'stock_on_hand', 'title' => 'ZB-SECRET-ITEM',
                'parameters' => '{}', 'format' => 'CSV', 'status' => 'COMPLETE', 'requested_by' => $this->manager->id, 'file_path' => 'x', 'created_at' => now(), 'updated_at' => now()]);
            $step = DB::table('wms_approval_steps')->insertGetId(['company_id' => $this->b->id, 'subject_type' => 'ADJUSTMENT', 'subject_id' => 999999, 'subject_ref' => 'ZB-SECRET-ORDER',
                'level' => 1, 'name' => 'x', 'permission' => 'wms.stock.approve', 'status' => 'PENDING', 'due_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            $this->ids = ['uuid' => [$wh->uuid, $item->uuid, $lot->uuid, $order->uuid, $carrier->uuid, $hook->uuid, DB::table('wms_report_runs')->where('id', $run)->value('uuid')],
                'id' => [(string) $wh->id, (string) $item->id, (string) $order->id, (string) $step, (string) $bin->id],
                'code' => ['ZB-SECRET-WH', 'ZB-SECRET-ORDER', (string) $order->number]];
        });
    }

    private function snapshot(): string
    {
        $tables = ['fin_warehouses', 'fin_stock_items', 'fin_stock_positions', 'fin_stock_movements', 'wms_orders', 'wms_order_lines', 'wms_carriers', 'wms_webhooks',
            'wms_report_runs', 'wms_approval_steps', 'fin_stock_bins', 'fin_stock_lots', 'fin_stock_reservations'];

        return md5(json_encode(collect($tables)->mapWithKeys(fn ($t) => [$t => DB::table($t)->where('company_id', $this->b->id)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all()])));
    }

    public function test_another_companys_identifiers_reveal_and_change_nothing_on_any_route(): void
    {
        $before = $this->snapshot();
        $leaks = [];
        $errors = [];
        $routes = collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => Str::startsWith((string) $r->getName(), ['warehouse.', 'api.warehouse.'])
            && str_contains($r->uri(), '{'));
        $this->assertGreaterThan(40, $routes->count());

        foreach ($routes as $route) {
            $api = Str::startsWith($route->getName(), 'api.');
            foreach (array_unique(array_merge(...array_values($this->ids))) as $value) {
                $uri = '/'.preg_replace('/\{[^}]+\}/', rawurlencode($value), $route->uri());
                foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                    // Each request in its own savepoint: one that fails in SQL cannot poison the next, and
                    // whether it changed B is checked before it is undone.
                    DB::beginTransaction();
                    if ($api) {
                        app(CurrentCompany::class)->set($this->company->id);
                        Sanctum::actingAs($this->manager, ['*']);
                        $response = $this->json($method, $uri);
                    } else {
                        $response = $this->actingAs($this->manager)->call($method, $uri);
                    }
                    $body = (string) $response->getContent();
                    foreach (self::MARKERS as $m) {
                        if (str_contains($body, $m)) {
                            $leaks[] = "{$method} {$uri} ({$response->getStatusCode()}) shows {$m}";
                        }
                    }
                    if ($response->getStatusCode() >= 500) {
                        $errors[] = "{$method} {$route->uri()} answered {$response->getStatusCode()}";
                    }
                    try {
                        if ($this->snapshot() !== $before) {
                            $leaks[] = "{$method} {$uri} changed company B's records";
                        }
                    } catch (\Illuminate\Database\QueryException) {
                        // The request failed in SQL and aborted its savepoint: nothing it did survives. Only the
                        // test's outer transaction notices; in production the answer is what matters (checked above).
                    }
                    DB::rollBack();
                    $this->app['auth']->forgetGuards();
                }
            }
        }

        $this->assertSame([], $leaks, implode("\n", array_slice($leaks, 0, 30)));
        $this->assertSame([], array_values(array_unique($errors)), "Server errors:\n".implode("\n", array_unique($errors)));
        $this->assertSame($before, $this->snapshot(), "Company B's records changed.");
    }
}
