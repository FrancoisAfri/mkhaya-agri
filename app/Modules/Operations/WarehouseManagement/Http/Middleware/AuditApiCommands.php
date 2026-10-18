<?php

namespace App\Modules\Operations\WarehouseManagement\Http\Middleware;

use App\Modules\Operations\WarehouseManagement\Services\WarehouseAudit;
use App\Modules\Tenancy\Services\CurrentCompany;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Service-account activity (WMS-203): every command a token makes through the warehouse API is recorded. */
class AuditApiCommands
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        if (! $request->isMethodSafe() && $response->getStatusCode() < 400 && ($cid = app(CurrentCompany::class)->id())) {
            app(WarehouseAudit::class)->record((int) $cid, 'api.'.($request->route()?->getName() ?? 'call'), 'API', mb_substr($request->path(), 0, 80),
                "{$request->method()} /{$request->path()} → {$response->getStatusCode()}".($response->headers->has('Idempotent-Replay') ? ' (replayed)' : ''));
        }

        return $response;
    }
}
