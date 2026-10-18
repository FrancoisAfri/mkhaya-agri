<?php

namespace Tests\Feature\Manufacturing;

use App\Core\Role\Models\Role;
use App\Modules\Operations\DocumentManagement\Support\DocumentRelations;
use App\Modules\Operations\Manufacturing\Models\Factory;
use App\Modules\Operations\Manufacturing\Models\FactoryAssignment;
use App\Modules\Operations\Manufacturing\Models\FactoryPolicy;
use App\Modules\Operations\Manufacturing\Models\Item;
use App\Modules\Operations\Manufacturing\Models\LossFinding;
use App\Modules\Operations\Manufacturing\Models\ProductionOrder as PO;
use App\Modules\Operations\Manufacturing\Models\SubcontractOrder;
use App\Modules\Operations\Manufacturing\Services\FactoryAccess;
use App\Modules\Operations\Manufacturing\Services\ProductionOrderLifecycle;
use App\Modules\Operations\Manufacturing\Services\ProductionOrders;
use App\Modules\Operations\Manufacturing\Support\RoleCatalog;
use App\Modules\Tenancy\Models\Company;
use Illuminate\Support\Facades\DB;

/**
 * MFG-023: important manufacturing actions are on the audit trail with actor, time, company,
 * record, action and before/after values; the trail is read per company and per factory; and
 * the records manufacturing files documents against are registered with Document Management.
 */
class AuditTrailTest extends ManufacturingTestCase
{
    private Factory $plant;

    private Factory $other;

    private Item $frame;

    private $auditor;

    private $local;

    protected function setUp(): void
    {
        parent::setUp();
        $this->openBooks();
        $all = Role::firstOrCreate(['name' => 'mfg test auditor', 'guard_name' => 'web']);
        $all->syncPermissions(RoleCatalog::all());
        $local = Role::firstOrCreate(['name' => 'mfg test plant auditor', 'guard_name' => 'web']);
        $local->syncPermissions(array_values(array_diff(RoleCatalog::all(), [RoleCatalog::ALL_FACTORIES])));
        $this->auditor = $this->makeUser('auditor@example.test', $all);
        $this->local = $this->makeUser('plant-auditor@example.test', $local);
        $this->grantModuleTier($this->auditor);
        $this->grantModuleTier($this->local);

        $this->plant = $this->factory('PLT');
        $this->other = $this->factory('OTH');
        $this->asCompany($this->company, fn () => FactoryAssignment::create(['company_id' => $this->company->id, 'factory_id' => $this->other->id,
            'user_id' => $this->local->id, 'granted_by' => $this->auditor->id]));
        app(FactoryAccess::class)->flush();
        foreach ([$this->plant, $this->other] as $f) {
            $this->asCompany($this->company, fn () => FactoryPolicy::for($f->id, $this->company->id)->update(['require_material_for_approval' => false, 'require_routing' => false]));
        }
        $steel = $this->stockItem('STEEL', 'kg', 10);
        $this->frame = $this->asCompany($this->company, fn () => Item::create(['company_id' => $this->company->id,
            'stock_item_id' => $this->stockItem('FRAME', 'ea', 0)->id, 'kind' => 'FINISHED', 'default_uom_id' => $this->uom('ea')->id, 'is_active' => true]));
        $this->approvedBom($this->frame, [[$steel, 2, 0, 'kg']]);
    }

    private function order(Factory $f): PO
    {
        return $this->asCompany($this->company, function () use ($f) {
            $this->actingAs($this->auditor);
            $o = app(ProductionOrders::class)->create(['item' => $this->frame, 'quantity' => 4, 'due_date' => now()->addDays(3)->toDateString(),
                'factory' => $f, 'warehouse' => $this->warehouse('W'.$f->code)], $this->auditor);
            app(ProductionOrderLifecycle::class)->plan($o, $this->auditor);

            return $o->fresh();
        });
    }

    public function test_a_change_is_recorded_with_actor_company_record_and_before_and_after(): void
    {
        $order = $this->order($this->plant);
        $entry = DB::table('activity_log')->where('log_name', 'manufacturing')->where('subject_type', PO::class)->where('subject_id', $order->id)
            ->where('event', 'updated')->orderByDesc('id')->first();
        $this->assertNotNull($entry, 'Planning the order is on the trail.');
        $this->assertSame($this->auditor->id, (int) $entry->causer_id);
        $this->assertSame($this->company->id, (int) $entry->company_id);
        $p = json_decode($entry->properties, true);
        $this->assertSame('DRAFT', $p['old']['status']);
        $this->assertSame('PLANNED', $p['attributes']['status']);
        $this->assertSame($this->plant->id, $p['factory_id']);
        $this->assertSame($order->number, $p['label']);

        // Without a person, the system is named as the actor.
        auth()->logout();
        $this->asCompany($this->company, fn () => $order->fresh()->update(['notes' => 'Changed by the nightly job']));
        $system = DB::table('activity_log')->where('subject_type', PO::class)->where('subject_id', $order->id)->orderByDesc('id')->first();
        $this->assertNull($system->causer_id);
        $this->assertStringStartsWith('system', json_decode($system->properties, true)['actor']);
    }

    public function test_the_trail_is_read_per_company_and_per_factory(): void
    {
        $here = $this->order($this->plant);
        $there = $this->order($this->other);
        // Another company's entry must never show.
        $foreign = Company::create(['name' => 'Rival Works']);
        DB::table('activity_log')->insert(['log_name' => 'manufacturing', 'description' => 'updated', 'event' => 'updated', 'subject_type' => PO::class,
            'subject_id' => 999999, 'company_id' => $foreign->id, 'properties' => json_encode(['label' => 'RIVAL-1']), 'created_at' => now(), 'updated_at' => now()]);

        $all = $this->actingAs($this->auditor)->get(route('manufacturing.audit.index', ['type' => PO::class]))->assertOk()->viewData('page');
        $labels = collect($all['props']['entries'])->pluck('label')->unique()->values()->all();
        $this->assertContains($here->number, $labels);
        $this->assertContains($there->number, $labels);
        $this->assertNotContains('RIVAL-1', $labels);

        $local = $this->actingAs($this->local)->get(route('manufacturing.audit.index', ['type' => PO::class]))->assertOk()->viewData('page');
        $labels = collect($local['props']['entries'])->pluck('label')->unique()->values()->all();
        $this->assertSame([$there->number], $labels, 'Confined to the other plant.');

        $this->actingAs($this->outsider)->get(route('manufacturing.audit.index'))->assertForbidden();
    }

    public function test_manufacturing_records_are_registered_with_document_management(): void
    {
        foreach ([\App\Modules\Operations\Manufacturing\Models\Bom::class, \App\Modules\Operations\Manufacturing\Models\Routing::class,
            LossFinding::class, SubcontractOrder::class, PO::class, \App\Modules\Operations\Manufacturing\Models\Inspection::class,
            \App\Modules\Operations\Manufacturing\Models\Nonconformance::class, \App\Modules\Operations\Manufacturing\Models\Recall::class, Item::class] as $model) {
            $this->assertNotNull(DocumentRelations::forModel(new $model), class_basename($model).' cannot hold documents.');
            $this->assertTrue(method_exists($model, 'libraryDocuments'), class_basename($model).' has no documents relation.');
        }
    }
}
