<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A second tenant, on demand.
 *
 * Tenant-isolation tests used to look for another company and skip when the database had only one.
 * After a `RefreshDatabase` run there *is* only one, so the tests that most need to run — the ones
 * asserting that one company cannot see another's data — were the ones that quietly did not. A
 * skipped isolation test looks identical to a passing one in the summary.
 *
 * Creating the company here costs a single insert and rolls back with the surrounding transaction,
 * so the test no longer depends on what happens to be in the database.
 */
trait MakesCompanies
{
    /**
     * Another company's id, guaranteed — reused if the database already has one, created if not.
     */
    protected function otherCompanyId(int $notThisOne): int
    {
        $existing = DB::table('companies')->where('id', '!=', $notThisOne)->min('id');

        return $existing !== null ? (int) $existing : $this->makeCompany('Second Tenant');
    }

    protected function makeCompany(string $name = 'Test Tenant'): int
    {
        return (int) DB::table('companies')->insertGetId([
            'uuid'       => (string) Str::uuid(),
            'slug'       => Str::slug($name).'-'.Str::random(8),
            'name'       => $name.' '.Str::random(4),
            'status'     => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
