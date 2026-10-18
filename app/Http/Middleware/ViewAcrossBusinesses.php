<?php

namespace App\Http\Middleware;

use App\Modules\Finance\Accounting\Services\CurrentEntity;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Show a tenant's whole group rather than one of its businesses.
 *
 * A tenant who trades as Mkhaya MK, Mkhaya Move and Mkhaya Hub eventually needs the three added
 * up: the group's revenue, the group's debtors, one balance sheet for the accountant. Everything
 * needed for that already exists — an unresolved entity means no filter, in EntityScope and in the
 * report queries alike — so this is the switch, not the machinery.
 *
 * ── Per request, and only for reading ────────────────────────────────────────
 *
 * Two deliberate limits, both to keep it from becoming a state somebody writes in.
 *
 * It is not remembered. The choice of business is sticky because it decides whose letterhead goes
 * on an invoice; "all of them" is a way of looking at figures, and a mode that persists is one
 * somebody is still in tomorrow when they go to raise a document.
 *
 * It applies to GET only. With no business resolved, a write would be a row belonging to a tenant
 * and to none of their businesses — which the database now refuses outright, with a constraint
 * error rather than anything a person could act on. Reads are what this is for, so reads are what
 * it does.
 */
class ViewAcrossBusinesses
{
    /** The query parameter the group view is asked for with. */
    public const FLAG = 'all_businesses';

    public function __construct(private readonly CurrentEntity $entities)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->asked($request)) {
            return $next($request);
        }

        // run() rather than a bare setter: it restores what was there afterwards, so a queued job
        // or a test running in the same process is not left looking at the whole group.
        return $this->entities->run(null, fn () => $next($request));
    }

    private function asked(Request $request): bool
    {
        return $request->isMethod('GET') && $request->boolean(self::FLAG);
    }
}
