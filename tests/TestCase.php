<?php

namespace Tests;

use App\Modules\Finance\Accounting\Services\CurrentEntity;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * The business a finance row belongs to, for fixtures that insert rows directly.
     *
     * A tenant can trade under several names, so every finance row says which of them it is. The
     * application never has to think about it — the models stamp it — but a fixture writing
     * through `DB::table()` skips the model, and since Phase 4 the database refuses a row that
     * names a tenant and no business:
     *
     *     CHECK (company_id IS NULL OR entity_id IS NOT NULL)
     *
     * So a fixture that names a company names a business too. The tenant's default one, created
     * on demand if they have none, which is what a single-business tenant has anyway.
     *
     * Null in, null out: a row belonging to no company belongs to no business either, which is
     * the state the system's own shared accounts are in and which the rule deliberately allows.
     */
    protected function businessFor(?int $companyId): ?int
    {
        return $companyId === null
            ? null
            : app(CurrentEntity::class)->defaultFor($companyId)?->id;
    }
}
