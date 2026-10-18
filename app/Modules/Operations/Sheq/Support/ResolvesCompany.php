<?php

namespace App\Modules\Operations\Sheq\Support;

use App\Modules\Tenancy\Support\ResolvesTenant;

/**
 * Resolves the company a SHEQ request belongs to.
 *
 * A thin alias over {@see ResolvesTenant}, kept so the ~20 controllers using it need no change and
 * so the module's own name stays readable at each call site. The behaviour — including the
 * self-hosted fallback and the edition-specific refusal — lives in one place.
 *
 * Why it matters here specifically: an unscoped read would return every tenant's incidents, and a
 * SHEQ incident carries injury detail about a named person. That is a POPIA breach, not merely a
 * data-quality problem.
 */
trait ResolvesCompany
{
    use ResolvesTenant;
}
