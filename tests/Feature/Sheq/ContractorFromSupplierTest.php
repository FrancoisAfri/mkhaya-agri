<?php

namespace Tests\Feature\Sheq;

use App\Core\User\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Registering a contractor who is already a supplier.
 *
 * `sheq_contractors.supplier_id` has always existed and the only way to fill it was a Link button
 * on the contractor's page — *after* somebody had typed the company in by hand. So the normal
 * path created a second record for a company already on file, with the safety file against one
 * row and the commercial record against another and nothing joining them. "Is this supplier
 * cleared to work on site" then has two answers depending on which record you opened.
 */
class ContractorFromSupplierTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        // These tests read what a page hands its component, not the compiled front end. Without
        // this the suite depends on `public/build/manifest.json`, so an unrelated `vite build`
        // running in another checkout turns every page assertion below into a 500.
        $this->withoutVite();

        $companyId = DB::table('companies')->min('id');

        if ($companyId === null) {
            $this->markTestSkipped('No company in the test database.');
        }

        if (! Schema::hasTable('suppliers')) {
            $this->markTestSkipped('Supplier Management is not installed on this instance.');
        }

        $this->companyId = (int) $companyId;
        $this->user = $this->userWith(['sheq.contractor.manage', 'sheq.configure']);
    }

    private function userWith(array $permissions): User
    {
        $userId = DB::table('users')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'email' => Str::random(12).'@example.test',
            'password' => bcrypt('secret'), 'status' => 1,
            'company_id' => $this->companyId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $user = User::findOrFail($userId);

        foreach ($permissions as $permission) {
            $user->givePermissionTo(
                \Spatie\Permission\Models\Permission::findOrCreate($permission, 'web'),
            );
        }

        return $user->fresh();
    }

    private function supplier(string $name): int
    {
        return (int) DB::table('suppliers')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'company_id' => $this->companyId,
            'company_name' => $name,
            'company_registration_number' => '2019/'.random_int(100000, 999999).'/07',
            'email' => Str::random(8).'@supplier.test',
            'phone_number' => '011 555 0100',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_a_contractor_can_be_registered_against_an_existing_supplier(): void
    {
        $supplierId = $this->supplier('Acme Scaffolding (Pty) Ltd');

        $this->actingAs($this->user)
            ->post('/sheq/contractors', [
                'name' => 'Acme Scaffolding (Pty) Ltd',
                'trade' => 'SCAFFOLDING',
                'supplier_id' => $supplierId,
            ])->assertRedirect();

        $contractor = DB::table('sheq_contractors')
            ->where('company_id', $this->companyId)->where('supplier_id', $supplierId)
            ->first(['name', 'access_status']);

        $this->assertNotNull($contractor, 'The contractor was not tied to the supplier.');
        $this->assertSame('Acme Scaffolding (Pty) Ltd', $contractor->name);

        // Picking a supplier is not a shortcut past the gate: they are still blocked until the
        // safety file is complete, which is the whole point of the register.
        $this->assertSame('BLOCKED', $contractor->access_status);
    }

    public function test_one_supplier_cannot_become_two_contractors(): void
    {
        /*
         * The duplicate this feature exists to prevent. Two contractor rows against one commercial
         * record would mean two safety files for one company, and "may they work" would depend on
         * which row somebody happened to open.
         */
        $supplierId = $this->supplier('Bravo Rigging');

        $this->actingAs($this->user)->post('/sheq/contractors', [
            'name' => 'Bravo Rigging', 'supplier_id' => $supplierId,
        ])->assertRedirect();

        $this->actingAs($this->user)->post('/sheq/contractors', [
            'name' => 'Bravo Rigging again', 'supplier_id' => $supplierId,
        ])->assertSessionHasErrors('supplier_id');

        $this->assertSame(1, DB::table('sheq_contractors')
            ->where('company_id', $this->companyId)->where('supplier_id', $supplierId)
            ->whereNull('deleted_at')->count());
    }

    public function test_a_supplier_from_another_company_cannot_be_claimed(): void
    {
        // The picker is scoped, but the request is what decides. Without this a tenant could tie
        // their contractor to another tenant's commercial record by guessing an id.
        $otherCompany = (int) DB::table('companies')->insertGetId([
            'uuid' => (string) Str::uuid(), 'name' => 'Other Employer',
            'slug' => 'sheq-sup-other-'.Str::lower(Str::random(8)), 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $foreign = DB::table('suppliers')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $otherCompany,
            'company_name' => 'Someone else’s supplier',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($this->user)->post('/sheq/contractors', [
            'name' => 'Charlie Civils', 'supplier_id' => $foreign,
        ])->assertSessionHasErrors('supplier_id');
    }

    public function test_the_register_screen_offers_suppliers_that_are_not_already_contractors(): void
    {
        $free = $this->supplier('Delta Electrical');
        $taken = $this->supplier('Echo Plumbing');

        $this->actingAs($this->user)->post('/sheq/contractors', [
            'name' => 'Echo Plumbing', 'supplier_id' => $taken,
        ])->assertRedirect();

        $offered = collect(
            $this->actingAs($this->user)->get('/sheq/contractors')
                ->assertOk()
                ->viewData('page')['props']['suppliers'] ?? []
        )->pluck('id')->all();

        $this->assertContains($free, $offered);
        $this->assertNotContains($taken, $offered,
            'A supplier that is already a contractor was offered for registering a second one.');
    }

    public function test_a_contractor_who_is_not_a_supplier_still_registers(): void
    {
        // The picker is an option, never a requirement: plenty of contractors were never onboarded
        // commercially, and the register has to take them.
        $this->actingAs($this->user)
            ->post('/sheq/contractors', ['name' => 'Foxtrot Painting', 'trade' => 'PAINTING'])
            ->assertRedirect();

        $row = DB::table('sheq_contractors')
            ->where('company_id', $this->companyId)->where('name', 'Foxtrot Painting')
            ->first(['supplier_id']);

        $this->assertNotNull($row);
        $this->assertNull($row->supplier_id);
    }
}
