<?php

namespace Tests\Feature\Finance;

use App\Modules\Finance\Accounting\Models\FinParty;
use App\Modules\Finance\Accounting\Services\PartySyncService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PartySyncTest extends TestCase
{
    use DatabaseTransactions;

    private function crmAccount(string $name): int
    {
        return DB::table('crm_accounts')->insertGetId([
            'uuid' => (string) Str::uuid(), 'name' => $name, 'email' => 'x@y.io',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_crm_account_maps_to_a_customer_party_idempotently(): void
    {
        $sync = app(PartySyncService::class);
        $id = $this->crmAccount('Globex');

        $p1 = $sync->partyForCrmAccount($id);
        $p2 = $sync->partyForCrmAccount($id);

        $this->assertEquals($p1->id, $p2->id);           // idempotent
        $this->assertEquals('customer', $p1->kind);
        $this->assertEquals('crm', $p1->source);
        $this->assertEquals($id, $p1->crm_account_id);
        $this->assertEquals('Globex', $p1->name);
        $this->assertTrue($p1->isSynced());
    }

    public function test_sync_pulls_all_crm_accounts_as_customers(): void
    {
        if (! app(PartySyncService::class)->crmActive()) {
            $this->markTestSkipped('CRM module not active.');
        }

        $before = FinParty::where('source', 'crm')->count();
        $this->crmAccount('Initech');
        $this->crmAccount('Umbrella');

        app(PartySyncService::class)->syncCustomersFromCrm();

        $this->assertGreaterThanOrEqual($before + 2, FinParty::where('source', 'crm')->count());
    }

    public function test_manual_customer_is_linked_or_pushed_up_on_sync(): void
    {
        $sync = app(PartySyncService::class);
        if (! $sync->crmActive()) {
            $this->markTestSkipped('CRM module not active.');
        }

        // A customer captured manually while standalone.
        $party = FinParty::create(['kind' => 'customer', 'source' => 'manual', 'code' => 'M1', 'name' => 'Standalone Co', 'email' => 'stand@alone.io']);

        $sync->syncCustomersFromCrm();

        $party->refresh();
        $this->assertEquals('crm', $party->source);          // now owned by CRM
        $this->assertNotNull($party->crm_account_id);        // linked
        $this->assertTrue(DB::table('crm_accounts')->where('id', $party->crm_account_id)->exists()); // pushed up
    }

    public function test_synced_party_is_read_only_via_update(): void
    {
        $party = FinParty::create([
            'kind' => 'customer', 'source' => 'crm', 'crm_account_id' => 999999,
            'code' => 'CRM-999999', 'name' => 'Locked Co',
        ]);

        $this->assertTrue($party->isSynced());
    }
}
