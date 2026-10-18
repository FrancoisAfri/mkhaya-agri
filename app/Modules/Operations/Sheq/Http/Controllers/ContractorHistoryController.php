<?php

namespace App\Modules\Operations\Sheq\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\Sheq\Compliance\ContractorHistoryService;
use App\Modules\Operations\Sheq\Support\ResolvesCompany;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Everything that has happened to a contractor, and why the answer is what it is (CON-22, COM-5).
 *
 * Read-only, and that is the requirement rather than a simplification: a history a screen can
 * edit is not a history. The audit log underneath is append-only and hash-chained in the database
 * — triggers reject UPDATE, DELETE and TRUNCATE — so there is nothing here that could write even
 * if somebody added a button.
 *
 * The screen answers two different questions that happen to need the same evidence. "What has
 * happened to this contractor", which is what an auditor or a client asks and which runs to
 * hundreds of rows. And "why can this person not work this morning", which is what a site manager
 * asks and which needs four of them.
 */
class ContractorHistoryController extends Controller
{
    use ResolvesCompany;

    public function __construct(private readonly ContractorHistoryService $history) {}

    public function index(Request $request): Response
    {
        $companyId = $this->companyId($request);

        $contractors = $companyId === null ? [] : DB::table('sheq_contractors')
            ->where('company_id', $companyId)->whereNull('deleted_at')
            ->orderBy('name')->get(['uuid', 'name', 'access_status'])
            ->map(fn ($c) => (array) $c)->all();

        $selected = $request->query('contractor')
            ?: ($contractors[0]['uuid'] ?? null);

        $contractor = $selected === null || $companyId === null ? null
            : DB::table('sheq_contractors')->where('company_id', $companyId)
                ->where('uuid', $selected)->whereNull('deleted_at')
                ->first(['id', 'uuid', 'name', 'access_status']);

        $kinds = array_values(array_filter(
            explode(',', (string) $request->query('kinds', ''))));

        return Inertia::render('Modules/Sheq/Registers/ContractorHistory', [
            'noCompany' => $companyId === null,
            'contractors' => $contractors,
            'selected' => $contractor === null ? null : (array) $contractor,
            'kinds' => array_keys(ContractorHistoryService::SOURCES),
            'activeKinds' => $kinds,
            'history' => $contractor === null ? ['events' => [], 'counts' => []]
                : $this->history->forContractor($companyId, (int) $contractor->id,
                    ['kinds' => $kinds]),
            /*
             * The live explanation (COM-5), alongside the history rather than on another screen.
             * They are read together: the decision says a certificate is missing, and the history
             * says it was rejected on the 11th and by whom — which is the half somebody can act
             * on.
             */
            'why' => $contractor === null ? null
                : $this->history->why($companyId, (int) $contractor->id, $this->workerId($request,
                    $companyId), $this->siteId($request)),
            'workers' => $contractor === null ? [] : DB::table('sheq_contractor_workers')
                ->where('company_id', $companyId)->where('contractor_id', $contractor->id)
                ->whereNull('deleted_at')->where('is_active', true)
                ->orderBy('name')->get(['uuid', 'name'])->map(fn ($w) => (array) $w)->all(),
            'sites' => $companyId === null ? [] : DB::table('sheq_sites')
                ->where('company_id', $companyId)->whereNull('deleted_at')
                ->where('is_active', true)->orderBy('name')->get(['id', 'name'])
                ->map(fn ($s) => (array) $s)->all(),
            'worker' => $request->query('worker'),
            'site' => $request->query('site') === null ? null : (int) $request->query('site'),
        ]);
    }

    private function workerId(Request $request, ?int $companyId): ?int
    {
        $uuid = $request->query('worker');

        if ($uuid === null || $companyId === null) {
            return null;
        }

        $id = DB::table('sheq_contractor_workers')->where('company_id', $companyId)
            ->where('uuid', $uuid)->whereNull('deleted_at')->value('id');

        return $id === null ? null : (int) $id;
    }

    private function siteId(Request $request): ?int
    {
        $site = $request->query('site');

        return $site === null || $site === '' ? null : (int) $site;
    }
}
