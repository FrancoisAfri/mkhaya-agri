<?php

namespace Tests\Feature\Tenancy;

use App\Modules\Tenancy\Models\Company;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class RlsTest extends TestCase
{
    use DatabaseTransactions;

    private function invoice(int $companyId): void
    {
        $partyId = DB::table('fin_parties')->insertGetId([
            'uuid' => (string) Str::uuid(), 'kind' => 'customer', 'source' => 'manual',
            'code' => 'P' . uniqid(), 'name' => 'P', 'company_id' => $companyId, 'entity_id' => $this->businessFor($companyId), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('fin_invoices')->insert([
            'uuid' => (string) Str::uuid(), 'party_id' => $partyId, 'direction' => 'receivable', 'doc_type' => 'invoice',
            'number' => 'INV-' . uniqid(), 'invoice_date' => now(), 'due_date' => now(), 'total' => 100, 'amount_paid' => 0,
            'company_id' => $companyId, 'entity_id' => $this->businessFor($companyId), 'status' => 'open', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_rls_isolates_fin_invoices_even_via_raw_queries(): void
    {
        $a = Company::create(['name' => 'RLS-A']);
        $b = Company::create(['name' => 'RLS-B']);
        $this->invoice($a->id);
        $this->invoice($b->id);

        // Raw query (bypasses the Eloquent scope) still can't see across tenants
        // once the DB session is stamped — Postgres RLS is the backstop.
        DB::statement("SELECT set_config('app.company_id', ?, true)", [(string) $a->id]);
        $ids = DB::table('fin_invoices')->pluck('company_id')->unique()->values()->all();
        $this->assertEquals([$a->id], $ids);

        DB::statement("SELECT set_config('app.company_id', ?, true)", [(string) $b->id]);
        $this->assertEquals([$b->id], DB::table('fin_invoices')->pluck('company_id')->unique()->values()->all());
    }
}
