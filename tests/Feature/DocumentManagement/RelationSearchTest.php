<?php

namespace Tests\Feature\DocumentManagement;

use App\Modules\Operations\DocumentManagement\Support\DocumentRelations;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Finding records to link a document to.
 *
 * The picker searches eight modules through one registry, and the thing that makes that fragile is
 * that the modules were written separately: a column this side assumes — `code` on a project,
 * `serial_number` on an asset — is a 500 the first time somebody types, not a compile error.
 */
class RelationSearchTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;

    protected function setUp(): void
    {
        parent::setUp();

        $companyId = DB::table('companies')->min('id');
        if ($companyId === null) {
            $this->markTestSkipped('No company in the test database.');
        }
        $this->companyId = (int) $companyId;
        app(CurrentCompany::class)->set($this->companyId);
        DB::statement("SELECT set_config('app.company_id', ?, false)", [(string) $companyId]);
    }

    public function test_every_declared_relation_can_be_searched_without_failing(): void
    {
        // The whole point of the test. Each of these queries a different module's table with
        // columns named in one registry, and a column that has been renamed since only shows up
        // when somebody searches for it.
        foreach (array_keys(DocumentRelations::available()) as $relation) {
            $results = DocumentRelations::search($relation, '');

            $this->assertIsArray($results, "Searching {$relation} failed.");
        }
    }

    public function test_a_result_carries_what_the_picker_needs(): void
    {
        $supplier = \App\Modules\SupplierManagement\Models\Supplier::create([
            'company_name' => 'Findable Trading ' . Str::random(4),
            'company_id' => $this->companyId,
        ]);

        $results = collect(DocumentRelations::search(DocumentRelations::SUPPLIER, 'Findable'));
        $found = $results->firstWhere('id', $supplier->id);

        $this->assertNotNull($found, 'The supplier was not found by name.');
        $this->assertSame($supplier->company_name, $found['label']);
        $this->assertArrayHasKey('subtitle', $found);
    }

    public function test_searching_narrows_rather_than_returning_everything(): void
    {
        \App\Modules\SupplierManagement\Models\Supplier::create([
            'company_name' => 'Alpha Supplies ' . Str::random(4), 'company_id' => $this->companyId,
        ]);
        \App\Modules\SupplierManagement\Models\Supplier::create([
            'company_name' => 'Beta Logistics ' . Str::random(4), 'company_id' => $this->companyId,
        ]);

        $labels = collect(DocumentRelations::search(DocumentRelations::SUPPLIER, 'Alpha Supplies'))
            ->pluck('label');

        $this->assertTrue($labels->contains(fn ($l) => str_contains($l, 'Alpha Supplies')));
        $this->assertFalse($labels->contains(fn ($l) => str_contains($l, 'Beta Logistics')));
    }

    public function test_an_unknown_relation_returns_nothing_rather_than_failing(): void
    {
        // The endpoint validates against the same list, so this is defence in depth — but a picker
        // that 500s on a stale relation name is worse than one that finds nothing.
        $this->assertSame([], DocumentRelations::search('nonsense', 'anything'));
    }

    public function test_results_are_capped(): void
    {
        $this->assertLessThanOrEqual(5, count(DocumentRelations::search(DocumentRelations::ASSET, '', 5)));
    }
}
