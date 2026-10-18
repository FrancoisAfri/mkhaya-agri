<?php

namespace App\Modules\Operations\WarehouseManagement\Http\Middleware;

use App\Modules\Tenancy\Services\CurrentCompany;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The warehouse API answers only inside a company. The api group resolves the company from the
 * token's user; a token with none (a platform administrator) is refused rather than given every
 * tenant's stock, because an unset tenant makes row-level security permit every row.
 */
class RequireTenant
{
    public function __construct(private readonly CurrentCompany $current) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->current->isSet()) {
            abort(403, 'This endpoint needs a token belonging to a user of a company.');
        }

        return $next($request);
    }
}
