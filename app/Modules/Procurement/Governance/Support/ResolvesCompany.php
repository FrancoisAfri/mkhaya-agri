<?php

namespace App\Modules\Procurement\Governance\Support;

use App\Modules\Tenancy\Support\ResolvesTenant;

/**
 * Resolves the company a Procurement Governance request belongs to.
 *
 * A thin alias over {@see ResolvesTenant} so the eleven controllers using it need no change.
 *
 * Why it matters here specifically: an unscoped read would expose one customer's bid evaluations
 * to another — commercially fatal, and invisible at the time it happens.
 */
trait ResolvesCompany
{
    use ResolvesTenant;
}
