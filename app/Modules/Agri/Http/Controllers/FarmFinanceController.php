<?php

namespace App\Modules\Agri\Http\Controllers;

use App\Modules\Agri\Models\CropPlan;
use App\Modules\Agri\Models\CropSeason;
use App\Modules\Agri\Models\Farm;
use App\Modules\Agri\Models\FarmBudgetLine;
use App\Modules\Agri\Models\FarmTransaction;
use App\Modules\Agri\Models\LivestockGroup;
use App\Modules\Agri\Services\AgriLedger;
use App\Modules\Agri\Services\FarmProfitability;
use App\Modules\Agri\Support\RoleCatalog as R;
use App\Modules\Finance\Accounting\Exceptions\PostingException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Module E: farm budgets, expenses and sales (posted to the shared ledger) and profitability by
 * farm and enterprise.
 */
class FarmFinanceController extends AgriController
{
    public function __construct(private readonly FarmProfitability $profitability, private readonly AgriLedger $ledger) {}

    public function index(Request $request): Response
    {
        $this->allow($request, R::VIEW_FINANCE);
        [$from, $to] = $this->period($request);
        $farms = Farm::query()->orderBy('name')->get();
        $farm = $this->farmFilter($request) ?? $farms->first();

        return Inertia::render('Modules/Agri/Finance/Index', [
            'farm' => $farm ? ['id' => $farm->uuid, 'name' => $farm->name, 'currency' => $farm->currency] : null,
            'farms' => $farms->map(fn ($f) => ['id' => $f->uuid, 'name' => $f->name])->values(),
            'period' => ['from' => $from, 'to' => $to],
            'report' => $farm ? $this->profitability->forFarm($farm, $from, $to) : null,
            'summary' => $this->profitability->summary($farms, $from, $to),
            'transactions' => $farm ? FarmTransaction::query()->where('farm_id', $farm->id)->orderByDesc('transacted_on')->orderByDesc('id')->limit(100)->get()
                ->map(fn ($t) => ['id' => $t->uuid, 'kind' => $t->kind, 'category' => $t->category, 'date' => $t->transacted_on->toDateString(),
                    'description' => $t->description, 'counterparty' => $t->counterparty, 'amount' => (float) $t->amount,
                    'enterprise' => $t->enterprise, 'settled_by' => $t->settled_by, 'journal_id' => $t->journal_id,
                    'from_store' => (bool) $t->stock_movement_id, 'quantity' => self::num($t->quantity), 'unit' => $t->unit,
                    'reversed' => (bool) $t->reversed_at, 'reversal_reason' => $t->reversal_reason]) : [],
            'budget' => $farm ? FarmBudgetLine::query()->where('farm_id', $farm->id)->orderBy('period_start')->get()
                ->map(fn ($b) => ['id' => $b->uuid, 'enterprise' => $b->enterprise, 'category' => $b->category, 'description' => $b->description,
                    'amount' => (float) $b->amount, 'period_start' => $b->period_start->toDateString(), 'period_end' => $b->period_end->toDateString()]) : [],
            'options' => [
                'enterprises' => $farm ? $this->enterprises($farm) : [],
                'expense_categories' => FarmTransaction::EXPENSE_CATEGORIES,
                'sale_categories' => FarmTransaction::SALE_CATEGORIES,
                'budget_categories' => FarmBudgetLine::CATEGORIES,
                'seasons' => CropSeason::query()->orderByDesc('starts_on')->get(['id', 'name']),
            ],
            'can' => ['manage' => $request->user()->can(R::MANAGE_FINANCE)],
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $this->allow($request, R::VIEW_FINANCE);
        [$from, $to] = $this->period($request);
        $farm = $this->farm($request->query('farm'));
        $report = $this->profitability->forFarm($farm, $from, $to);

        return response()->streamDownload(function () use ($report) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Enterprise', 'Kind', 'Hectares', 'Head', 'Income', 'Costs (ledger)', 'Gross margin', 'Margin per ha', 'Estimated labour & machine', 'Budget costs', 'Budget variance']);
            foreach ($report['enterprises'] as $e) {
                fputcsv($out, [$e['name'], $e['kind'], $e['hectares'], $e['head'], $e['income'], $e['costs'], $e['gross_margin'], $e['margin_per_ha'], $e['estimated_costs'], $e['budget_costs'], $e['budget_variance']]);
            }
            $t = $report['totals'];
            fputcsv($out, ['Total', '', '', '', $t['income'], $t['costs'], $t['gross_margin'], '', $t['estimated_costs'], $t['budget_costs'], '']);
            fclose($out);
        }, "farm-profitability-{$farm->code}-{$from}-{$to}.csv", ['Content-Type' => 'text/csv']);
    }

    public function storeTransaction(Request $request): RedirectResponse
    {
        $this->allow($request, R::MANAGE_FINANCE);
        $data = $request->validate([
            'farm_id' => ['required', 'string'],
            'kind' => ['required', Rule::in(['expense', 'sale'])],
            'enterprise' => ['nullable', 'string', 'max:80'],
            'field_id' => ['nullable', 'string'],
            'category' => ['required', 'string', 'max:20'],
            'transacted_on' => ['required', 'date', 'before_or_equal:today'],
            'description' => ['required', 'string', 'max:300'],
            'counterparty' => ['nullable', 'string', 'max:150'],
            'reference' => ['nullable', 'string', 'max:60'],
            'quantity' => ['nullable', 'numeric', 'gt:0'],
            'unit' => ['nullable', 'string', 'max:12'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'settled_by' => ['required', Rule::in(['bank', 'payable', 'receivable'])],
        ]);
        $farm = $this->workingFarm($request, $data['farm_id']);
        $cats = $data['kind'] === 'expense' ? FarmTransaction::EXPENSE_CATEGORIES : FarmTransaction::SALE_CATEGORIES;
        if (! in_array($data['category'], $cats, true)) {
            throw ValidationException::withMessages(['category' => 'Choose a category.']);
        }
        if ($data['kind'] === 'expense' && $data['settled_by'] === 'receivable' || $data['kind'] === 'sale' && $data['settled_by'] === 'payable') {
            throw ValidationException::withMessages(['settled_by' => $data['kind'] === 'expense' ? 'An expense is paid or owed, not receivable.' : 'A sale is received or receivable.']);
        }
        $enterprise = $this->enterprise($farm, $data['enterprise'] ?? null);
        $field = $this->fieldOn($farm, $data['field_id'] ?? null);

        try {
            DB::transaction(function () use ($farm, $data, $enterprise, $field, $request) {
                $t = FarmTransaction::create([
                    'company_id' => $farm->company_id, 'farm_id' => $farm->id, 'enterprise' => $enterprise ? AgriLedger::enterpriseKey($enterprise) : null,
                    'field_id' => $field?->id, 'recorded_by' => $request->user()->id,
                ] + collect($data)->except(['farm_id', 'enterprise', 'field_id'])->all());
                $dims = AgriLedger::dimensions($farm, $field, $enterprise, $data['category']);
                $journal = $data['kind'] === 'expense'
                    ? $this->ledger->expense($farm, (float) $data['amount'], $data['transacted_on'], $data['description'], $dims, $data['settled_by'], $this->expenseAccount($data['category']), "agri-txn-{$t->uuid}")
                    : $this->ledger->sale($farm, (float) $data['amount'], $data['transacted_on'], $data['description'], $dims, $data['category'] === 'livestock' ? 'livestock' : 'produce', $data['settled_by'], "agri-txn-{$t->uuid}");
                $t->update(['journal_id' => $journal->id]);
            });
        } catch (PostingException $e) {
            throw ValidationException::withMessages(['amount' => $e->getMessage()]);
        }

        return back()->with('success', ucfirst($data['kind']).' posted to the ledger.');
    }

    /**
     * A farm transaction entered in error is corrected by reversing its journal — the original and
     * its reversal both stay in the books — never by deleting it.
     */
    public function reverse(Request $request, FarmTransaction $transaction): RedirectResponse
    {
        $this->allow($request, R::MANAGE_FINANCE);
        $this->assertWritable($request, $transaction->farm_id);
        $data = $request->validate(['reason' => ['required', 'string', 'max:300']]);
        if ($transaction->reversed_at) {
            throw ValidationException::withMessages(['reason' => 'This transaction has already been reversed.']);
        }
        if ($transaction->stock_movement_id) {
            throw ValidationException::withMessages(['reason' => 'A sale from the farm store also moved stock; correct it in Finance with a credit note and stock return.']);
        }
        $journal = \App\Modules\Finance\Accounting\Models\FinJournal::query()->find($transaction->journal_id);
        try {
            DB::transaction(function () use ($transaction, $journal, $data, $request) {
                $reversal = $journal ? app(\App\Modules\Finance\Accounting\Services\Ledger::class)->reverse($journal, $data['reason']) : null;
                $transaction->update(['reversed_at' => now(), 'reversed_by' => $request->user()->id, 'reversal_reason' => $data['reason'],
                    'reversal_journal_id' => $reversal?->id]);
            });
        } catch (PostingException $e) {
            throw ValidationException::withMessages(['reason' => $e->getMessage()]);
        }

        return back()->with('success', 'Transaction reversed in the ledger.');
    }

    public function storeBudget(Request $request): RedirectResponse
    {
        $this->allow($request, R::MANAGE_FINANCE);
        $data = $request->validate([
            'farm_id' => ['required', 'string'],
            'season_id' => ['nullable', 'integer'],
            'enterprise' => ['nullable', 'string', 'max:80'],
            'category' => ['required', Rule::in(FarmBudgetLine::CATEGORIES)],
            'description' => ['nullable', 'string', 'max:200'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
        ]);
        $farm = $this->workingFarm($request, $data['farm_id']);
        $enterprise = $this->enterprise($farm, $data['enterprise'] ?? null);
        FarmBudgetLine::create(['company_id' => $farm->company_id, 'farm_id' => $farm->id, 'created_by' => $request->user()->id,
            'enterprise' => $enterprise ? AgriLedger::enterpriseKey($enterprise) : null] + collect($data)->except(['farm_id', 'enterprise'])->all());

        return back()->with('success', 'Budget line added.');
    }

    public function destroyBudget(Request $request, FarmBudgetLine $line): RedirectResponse
    {
        $this->allow($request, R::MANAGE_FINANCE);
        $this->assertWritable($request, $line->farm_id);
        $line->delete();

        return back()->with('success', 'Budget line removed.');
    }

    private function expenseAccount(string $category): string
    {
        return match ($category) {
            'inputs' => config('agri.accounts.inputs'),
            'animal_health', 'feed' => config('agri.accounts.animal_health'),
            'labour' => '6100',
            'fuel' => '6300',
            'repairs', 'machinery' => '6400',
            default => config('agri.accounts.direct_expense'),
        };
    }

    /** "crop_plan:<uuid>" or "livestock_group:<uuid>" from a form → the record, on this farm. */
    private function enterprise(Farm $farm, ?string $key): ?Model
    {
        if (blank($key)) {
            return null;
        }
        [$type, $uuid] = array_pad(explode(':', $key, 2), 2, null);
        $model = match ($type) {
            'crop_plan' => CropPlan::query()->where('uuid', $uuid)->where('farm_id', $farm->id)->first(),
            'livestock_group' => LivestockGroup::query()->where('uuid', $uuid)->where('farm_id', $farm->id)->first(),
            default => null,
        };
        if (! $model) {
            throw ValidationException::withMessages(['enterprise' => 'That crop or herd is not on this farm.']);
        }

        return $model;
    }

    private function enterprises(Farm $farm): array
    {
        return CropPlan::query()->where('farm_id', $farm->id)->whereNotIn('status', [CropPlan::CANCELLED])->with('cropType')->orderBy('code')->get()
            ->map(fn ($p) => ['value' => 'crop_plan:'.$p->uuid, 'title' => "Crop: {$p->code} · {$p->cropType?->name}"])
            ->merge(LivestockGroup::query()->where('farm_id', $farm->id)->orderBy('code')->get()
                ->map(fn ($g) => ['value' => 'livestock_group:'.$g->uuid, 'title' => "Livestock: {$g->code} · {$g->name}"]))
            ->values()->all();
    }

    private function period(Request $request): array
    {
        $from = $request->query('from', today()->startOfYear()->toDateString());
        $to = $request->query('to', today()->toDateString());

        return [preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) ? $from : today()->startOfYear()->toDateString(),
            preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) ? $to : today()->toDateString()];
    }
}
