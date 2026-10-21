<?php

namespace App\Modules\Agri\Http\Controllers;

use App\Modules\Agri\Models\AgriExportedJournal;
use App\Modules\Agri\Models\AgriFinanceExport;
use App\Modules\Agri\Models\Farm;
use App\Modules\Agri\Models\FarmBudgetLine;
use App\Modules\Agri\Models\FarmBudgetRevision;
use App\Modules\Agri\Models\FarmCapexPlan;
use App\Modules\Agri\Models\FarmLoan;
use App\Modules\Agri\Services\FarmBudgets;
use App\Modules\Agri\Services\FarmCashFlow;
use App\Modules\Agri\Services\FarmCostReports;
use App\Modules\Agri\Services\FinanceIntegration;
use App\Modules\Agri\Support\RoleCatalog as R;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Phase 2 §6: budgets with owners, approval and revisions; cost per worker, activity, machine hour
 * and buyer; the cash-flow forecast with its assumptions and scenarios; reconciliation with the
 * books; and the export of farm journals to an accounting system that is the source of truth.
 */
class FarmFinanceIntelligenceController extends AgriController
{
    public const TABS = ['budgets', 'costs', 'cashflow', 'reconcile', 'exports'];

    public function __construct(private readonly FarmBudgets $budgets, private readonly FarmCostReports $costs,
        private readonly FarmCashFlow $cash, private readonly FinanceIntegration $integration) {}

    public function index(Request $request, string $tab = 'budgets'): Response
    {
        abort_unless(in_array($tab, self::TABS, true), 404);
        $this->allow($request, R::VIEW_FINANCE);
        $farms = Farm::query()->orderBy('name')->get();
        $farm = $this->farmFilter($request) ?? $farms->first();
        $from = $request->date('from')?->toDateString() ?? today()->startOfYear()->toDateString();
        $to = $request->date('to')?->toDateString() ?? today()->toDateString();
        $companyId = (int) $request->user()->company_id;
        $scenario = collect(FarmCashFlow::SCENARIO)->map(fn ($v, $k) => (float) $request->input("s.{$k}", $v))->all();

        return Inertia::render('Modules/Agri/Finance/Intelligence', [
            'tab' => $tab, 'from' => $from, 'to' => $to,
            'farm' => $farm ? ['id' => $farm->uuid, 'name' => $farm->name] : null,
            'farms' => $farms->map(fn ($f) => ['id' => $f->uuid, 'name' => $f->name])->values(),
            'data' => match ($tab) {
                'budgets' => $farm ? ['lines' => $this->budgets->comparison($farm, $from, $to),
                    'revisions' => FarmBudgetRevision::query()->where('farm_id', $farm->id)->orderByDesc('id')->limit(100)->get()
                        ->map(fn ($r) => $r->toArray() + ['line' => FarmBudgetLine::query()->whereKey($r->budget_line_id)->first(['uuid', 'category', 'description'])])] : [],
                'costs' => $farm ? ['workers' => $this->costs->perWorker($farm, $from, $to), 'activities' => $this->costs->perActivity($farm, $from, $to),
                    'machines' => $this->costs->perMachineHour($farm, $from, $to)] + $this->costs->perBuyer($farm) : [],
                'cashflow' => ['forecast' => $this->cash->forecast($companyId, (int) min(52, max(4, $request->integer('weeks', 26))), $scenario),
                    'loans' => FarmLoan::query()->orderBy('first_payment_on')->get(), 'capex' => FarmCapexPlan::query()->orderBy('planned_on')->get()],
                'reconcile' => $farm ? $this->integration->reconcile($farm, $from, $to) : [],
                'exports' => ['exports' => AgriFinanceExport::query()->orderByDesc('id')->limit(50)->get()->map(fn ($e) => $e->toArray() + [
                    'failed' => AgriExportedJournal::query()->where('export_id', $e->id)->where('status', 'failed')->get(['external_ref', 'error'])])],
            },
            'options' => ['categories' => FarmBudgetLine::CATEGORIES, 'scenario' => FarmCashFlow::SCENARIO, 'frequencies' => ['monthly', 'quarterly', 'annually', 'seasonal'],
                'farms' => $this->farmOptions()],
            'can' => ['manage' => $request->user()->can(R::MANAGE_FINANCE), 'approve' => $request->user()->can(R::APPROVE_BUDGETS)],
        ]);
    }

    // ── §6.1 budgets ─────────────────────────────────────────────────────────────────────────

    public function submitBudget(Request $request, FarmBudgetLine $line): RedirectResponse
    {
        $this->allow($request, R::MANAGE_FINANCE);
        $this->assertWritable($request, $line->farm_id);
        $this->budgets->submit($line, $request->user());

        return back()->with('success', 'Budget line submitted for approval.');
    }

    public function decideBudget(Request $request, FarmBudgetLine $line): RedirectResponse
    {
        $this->allow($request, R::APPROVE_BUDGETS);
        $data = $request->validate(['approve' => ['required', 'boolean']]);
        $this->budgets->decide($line, (bool) $data['approve'], $request->user());

        return back()->with('success', $data['approve'] ? 'Budget line approved; it now counts.' : 'Budget line sent back.');
    }

    public function reviseBudget(Request $request, FarmBudgetLine $line): RedirectResponse
    {
        $this->allow($request, R::MANAGE_FINANCE);
        $this->assertWritable($request, $line->farm_id);
        $data = $request->validate(['amount' => ['required', 'numeric', 'gt:0'], 'reason' => ['required', 'string', 'max:500']]);
        $this->budgets->revise($line, (float) $data['amount'], $data['reason'], $request->user());

        return back()->with('success', 'Revision asked for. The approved amount stands until it is approved.');
    }

    public function decideRevision(Request $request, FarmBudgetRevision $revision): RedirectResponse
    {
        $this->allow($request, R::APPROVE_BUDGETS);
        $data = $request->validate(['approve' => ['required', 'boolean'], 'note' => ['nullable', 'string', 'max:300']]);
        $this->budgets->decideRevision($revision, (bool) $data['approve'], $data['note'] ?? null, $request->user());

        return back()->with('success', $data['approve'] ? 'Revision approved; the budget now carries the new amount.' : 'Revision rejected.');
    }

    // ── §6.4 loans and capital plans ─────────────────────────────────────────────────────────

    public function storeLoan(Request $request): RedirectResponse
    {
        $this->allow($request, R::MANAGE_FINANCE);
        $data = $request->validate(['farm_id' => ['nullable', 'string'], 'lender' => ['required', 'string', 'max:160'], 'reference' => ['nullable', 'string', 'max:60'],
            'principal' => ['required', 'numeric', 'gt:0'], 'annual_rate_pct' => ['nullable', 'numeric', 'min:0', 'max:100'], 'first_payment_on' => ['required', 'date'],
            'payment' => ['required', 'numeric', 'gt:0'], 'frequency' => ['required', Rule::in(['monthly', 'quarterly', 'annually', 'seasonal'])],
            'instalments' => ['required', 'integer', 'min:1', 'max:600']]);
        $farm = ! empty($data['farm_id']) ? $this->workingFarm($request, $data['farm_id']) : null;
        FarmLoan::create(['company_id' => $request->user()->company_id, 'farm_id' => $farm?->id] + collect($data)->except('farm_id')->all());

        return back()->with('success', 'Loan added to the cash-flow forecast.');
    }

    public function storeCapex(Request $request): RedirectResponse
    {
        $this->allow($request, R::MANAGE_FINANCE);
        $data = $request->validate(['farm_id' => ['nullable', 'string'], 'description' => ['required', 'string', 'max:200'], 'amount' => ['required', 'numeric', 'gt:0'],
            'planned_on' => ['required', 'date'], 'status' => ['nullable', Rule::in(['planned', 'committed'])]]);
        $farm = ! empty($data['farm_id']) ? $this->workingFarm($request, $data['farm_id']) : null;
        FarmCapexPlan::create(['company_id' => $request->user()->company_id, 'farm_id' => $farm?->id, 'status' => $data['status'] ?? 'planned']
            + collect($data)->except(['farm_id', 'status'])->all());

        return back()->with('success', 'Capital spending planned.');
    }

    public function updateCapex(Request $request, FarmCapexPlan $capex): RedirectResponse
    {
        $this->allow($request, R::MANAGE_FINANCE);
        $data = $request->validate(['status' => ['required', Rule::in(['planned', 'committed', 'done', 'dropped'])]]);
        $capex->update($data);

        return back()->with('success', 'Capital plan updated.');
    }

    public function closeLoan(Request $request, FarmLoan $loan): RedirectResponse
    {
        $this->allow($request, R::MANAGE_FINANCE);
        $loan->update(['is_active' => false]);

        return back()->with('success', 'Loan closed; it leaves the forecast.');
    }

    // ── §6.5 traceability and export ─────────────────────────────────────────────────────────

    public function trace(Request $request, int $journal): JsonResponse
    {
        $this->allow($request, R::VIEW_FINANCE);
        abort_unless(\Illuminate\Support\Facades\DB::table('fin_journals')->where('id', $journal)->where('company_id', $request->user()->company_id)->exists(), 404);
        $t = $this->integration->trace($journal);
        abort_unless($t, 404);

        return response()->json($t);
    }

    /** The batch, as JSON the other system imports; each journal carries the reference it will be acknowledged by. */
    public function export(Request $request): StreamedResponse
    {
        $this->allow($request, R::MANAGE_FINANCE);
        $data = $request->validate(['target' => ['required', 'string', 'max:40'], 'from' => ['required', 'date'], 'to' => ['required', 'date', 'after_or_equal:from']]);
        $out = $this->integration->export((int) $request->user()->company_id, $data['target'], $data['from'], $data['to'], $request->user());
        $body = json_encode(['export' => $out['export']->uuid, 'target' => $data['target'], 'from' => $data['from'], 'to' => $data['to'], 'lines' => $out['lines']], JSON_PRETTY_PRINT);

        return response()->streamDownload(fn () => print $body, "farm-journals-{$data['from']}-{$data['to']}.json", ['Content-Type' => 'application/json']);
    }

    /** The other system's answer: {"results": {"<external_ref>": {"status": "acknowledged"|"failed", "target_id": "...", "error": "..."}}}. */
    public function acknowledge(Request $request, AgriFinanceExport $export): RedirectResponse
    {
        $this->allow($request, R::MANAGE_FINANCE);
        $data = $request->validate(['results' => ['nullable', 'array'], 'file' => ['nullable', 'file', 'mimetypes:application/json,text/plain', 'max:5120']]);
        $results = $data['results'] ?? (json_decode((string) $request->file('file')?->get(), true)['results'] ?? null);
        abort_unless(is_array($results), 422, 'Give the results the accounting system sent back.');
        $export = $this->integration->acknowledge($export, $results);

        return back()->with('success', "Export {$export->status}. Failed journals go again in the next export.");
    }
}
