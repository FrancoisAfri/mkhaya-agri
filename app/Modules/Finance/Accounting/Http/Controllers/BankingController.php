<?php

namespace App\Modules\Finance\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Accounting\Exceptions\PostingException;
use App\Modules\Finance\Accounting\Models\FinAccount;
use App\Modules\Finance\Accounting\Models\FinBankAccount;
use App\Modules\Finance\Accounting\Models\FinBankRule;
use App\Modules\Finance\Accounting\Models\FinBankStatementLine;
use App\Modules\Finance\Accounting\Models\FinParty;
use App\Modules\Finance\Accounting\Services\BankService;
use App\Modules\Finance\Accounting\Services\CashFlowForecastService;
use App\Modules\Finance\Accounting\Services\ReconciliationService;
use App\Modules\Finance\Accounting\Services\StatementImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class BankingController extends Controller
{
    public function __construct(
        private ReconciliationService $recon,
        private StatementImportService $import,
        private BankService $banks,
        private CashFlowForecastService $forecaster,
    ) {
    }

    public function forecast(Request $request): Response
    {
        $weeks = (int) $request->integer('weeks', 13);

        return Inertia::render('Modules/Finance/Accounting/CashForecast', [
            'forecast' => $this->forecaster->forecast($weeks),
            'weeks'    => $weeks,
        ]);
    }

    public function index(): Response
    {
        $banks = FinBankAccount::with('account')->orderBy('name')->get()->map(fn (FinBankAccount $b) => [
            'id' => $b->id, 'name' => $b->name, 'type' => $b->type, 'currency' => $b->currency,
            'bank_name' => $b->bank_name, 'branch' => $b->branch, 'branch_code' => $b->branch_code,
            'account_number' => $b->account_number, 'is_active' => $b->is_active,
            'account_id' => $b->account_id, 'account' => $b->account ? "{$b->account->code} · {$b->account->name}" : null,
            'unmatched' => $b->statementLines()->where('status', 'unmatched')->count(),
        ]);

        return Inertia::render('Modules/Finance/Accounting/Banking', [
            'banks' => $banks,
            'types' => FinBankAccount::TYPES,
            'glAccounts' => FinAccount::postable()->whereIn('type', ['asset', 'liability'])->orderBy('code')
                ->get(['id', 'code', 'name'])->map(fn ($a) => ['id' => $a->id, 'label' => "{$a->code} · {$a->name}"]),
            'feeAccounts' => FinAccount::postable()->where('type', 'expense')->orderBy('code')
                ->get(['id', 'code', 'name'])->map(fn ($a) => ['code' => $a->code, 'label' => "{$a->code} · {$a->name}"]),
            'transfers' => \App\Modules\Finance\Accounting\Models\FinBankTransfer::with('fromBank:id,name', 'toBank:id,name')
                ->orderByDesc('id')->limit(25)->get()
                ->map(fn ($t) => [
                    'id' => $t->id, 'date' => $t->transfer_date?->toDateString(),
                    'from' => $t->fromBank?->name, 'to' => $t->toBank?->name,
                    'amount' => (float) $t->amount, 'fee' => (float) $t->fee,
                    'reference' => $t->reference, 'description' => $t->description,
                ]),
        ]);
    }

    public function transfer(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'from_bank_id'  => 'required|integer|exists:fin_bank_accounts,id',
            'to_bank_id'    => 'required|integer|different:from_bank_id|exists:fin_bank_accounts,id',
            'amount'        => 'required|numeric|min:0.01',
            'fee'           => 'nullable|numeric|min:0',
            'fee_account'   => 'nullable|string',
            'transfer_date' => 'nullable|date',
            'reference'     => 'nullable|string|max:80',
            'description'   => 'nullable|string|max:255',
        ]);

        try {
            $this->banks->transfer($data);
        } catch (PostingException $e) {
            throw ValidationException::withMessages(['transfer' => [$e->getMessage()]]);
        }

        return back()->with('success', 'Transfer posted.');
    }

    public function store(Request $request): RedirectResponse
    {
        $this->banks->create($this->rules($request));

        return back()->with('success', 'Bank account added.');
    }

    public function update(Request $request, FinBankAccount $bankAccount): RedirectResponse
    {
        $this->banks->update($bankAccount, $this->rules($request, true));

        return back()->with('success', 'Bank account updated.');
    }

    public function reconcile(FinBankAccount $bankAccount): Response
    {
        return Inertia::render('Modules/Finance/Accounting/BankReconcile', [
            'bank' => [
                'id' => $bankAccount->id, 'name' => $bankAccount->name, 'currency' => $bankAccount->currency,
                'account' => $bankAccount->account ? "{$bankAccount->account->code} · {$bankAccount->account->name}" : null,
            ],
            'statementLines' => $this->recon->statementLines($bankAccount, 'unmatched'),
            'bookEntries' => $this->recon->bookEntries($bankAccount),
            'summary' => $this->recon->summary($bankAccount),
            'suggestions' => $this->recon->suggestions($bankAccount),
            'contraAccounts' => FinAccount::postable()->orderBy('code')->get(['id', 'code', 'name'])
                ->map(fn ($a) => ['id' => $a->id, 'code' => $a->code, 'label' => "{$a->code} · {$a->name}"]),
            'customers' => FinParty::customers()->where('is_active', true)->orderBy('name')->get(['id', 'name'])
                ->map(fn ($p) => ['id' => $p->id, 'name' => $p->name]),
            'suppliers' => FinParty::suppliers()->where('is_active', true)->orderBy('name')->get(['id', 'name'])
                ->map(fn ($p) => ['id' => $p->id, 'name' => $p->name]),
            'rules' => $this->rulesPayload($bankAccount),
            'fileTypes' => collect(StatementImportService::FILE_TYPES)->map(fn ($label, $value) => compact('value', 'label'))->values(),
            'dateFormats' => collect(StatementImportService::DATE_FORMATS)->keys()->map(fn ($k) => ['value' => $k, 'label' => $k === 'auto' ? 'Auto' : $k])->values(),
        ]);
    }

    public function importStatement(Request $request, FinBankAccount $bankAccount): RedirectResponse
    {
        $data = $request->validate([
            'file'        => 'required|file|mimes:csv,txt,xls,xlsx,ofx,qfx,qif|max:10240',
            'file_type'   => 'nullable|string',
            'date_format' => 'nullable|string',
        ]);

        $result = $this->import->import($bankAccount, $request->file('file'), [
            'file_type' => $data['file_type'] ?? 'auto', 'date_format' => $data['date_format'] ?? 'auto',
        ]);

        // Suggest rule matches straight after import.
        $auto = $this->recon->applyRules($bankAccount);
        $msg = "Imported {$result['imported']} line(s), skipped {$result['skipped']} duplicate(s).";
        if ($auto) {
            $msg .= " Auto-allocated {$auto} by rules.";
        }
        if (! empty($result['errors'])) {
            $msg .= ' ' . count($result['errors']) . ' row(s) had problems.';
        }

        return back()->with('success', $msg)->with('importErrors', $result['errors']);
    }

    public function autoMatch(FinBankAccount $bankAccount): RedirectResponse
    {
        $matched = $this->recon->autoMatch($bankAccount);

        return back()->with('success', "Auto-matched {$matched} line(s).");
    }

    public function applyRules(FinBankAccount $bankAccount): RedirectResponse
    {
        $count = $this->recon->applyRules($bankAccount);

        return back()->with('success', "Allocated {$count} line(s) by rules.");
    }

    public function match(Request $request, FinBankAccount $bankAccount): RedirectResponse
    {
        $data = $request->validate(['statement_line_id' => 'required|integer', 'journal_line_id' => 'required|integer']);
        $line = $bankAccount->statementLines()->findOrFail($data['statement_line_id']);

        try {
            $this->recon->match($line, (int) $data['journal_line_id']);
        } catch (PostingException $e) {
            throw ValidationException::withMessages(['reconcile' => [$e->getMessage()]]);
        }

        return back()->with('success', 'Matched.');
    }

    public function unmatchLine(FinBankStatementLine $statementLine): RedirectResponse
    {
        foreach ($statementLine->matches as $match) {
            $this->recon->unmatch($match);
        }

        return back()->with('success', 'Unmatched.');
    }

    public function allocate(Request $request, FinBankStatementLine $statementLine): RedirectResponse
    {
        $data = $request->validate([
            'type'         => 'required|in:account,customer,supplier',
            'contra'       => 'required_if:type,account|nullable|string',
            'party_id'     => 'required_if:type,customer|required_if:type,supplier|nullable|integer',
            'description'  => 'nullable|string|max:255',
            'remember'     => 'boolean',
            'match_value'  => 'nullable|string|max:255',
        ]);

        $bank = FinBankAccount::findOrFail($statementLine->bank_account_id);

        try {
            $this->recon->allocate($statementLine, $bank, $data);
        } catch (PostingException $e) {
            throw ValidationException::withMessages(['reconcile' => [$e->getMessage()]]);
        }

        // "Remember" — turn this allocation into a rule so future statement lines that match
        // auto-allocate to the same account/party (applied on import + via Apply rules).
        $extra = '';
        if (! empty($data['remember'])) {
            $match = trim((string) ($data['match_value'] ?? $statementLine->description));
            if ($match !== '') {
                FinBankRule::create([
                    'bank_account_id'   => $bank->id,
                    'name'              => \Illuminate\Support\Str::limit('Auto: ' . $match, 118, ''),
                    'priority'          => 100,
                    'match_type'        => 'contains',
                    'match_value'       => $match,
                    'direction'         => ((float) $statementLine->amount) >= 0 ? 'in' : 'out',
                    'allocation_type'   => $data['type'],
                    'contra_account_id' => $data['type'] === 'account'
                        ? \App\Modules\Finance\Accounting\Models\FinAccount::where('code', $data['contra'])->value('id')
                        : null,
                    'party_id'          => in_array($data['type'], ['customer', 'supplier'], true) ? ($data['party_id'] ?? null) : null,
                    'is_active'         => true,
                    'created_by'        => Auth::id(),
                ]);
                $extra = ' Rule saved for next time.';
            }
        }

        return back()->with('success', 'Allocated and reconciled.' . $extra);
    }

    // ── Rules ────────────────────────────────────────────────────────────────
    public function storeRule(Request $request): RedirectResponse
    {
        FinBankRule::create($this->ruleRules($request) + ['created_by' => Auth::id()]);

        return back()->with('success', 'Rule saved.');
    }

    public function updateRule(Request $request, FinBankRule $rule): RedirectResponse
    {
        $rule->update($this->ruleRules($request));

        return back()->with('success', 'Rule updated.');
    }

    public function destroyRule(FinBankRule $rule): RedirectResponse
    {
        $rule->delete();

        return back()->with('success', 'Rule deleted.');
    }

    private function ruleRules(Request $request): array
    {
        return $request->validate([
            'name'             => 'required|string|max:120',
            'bank_account_id'  => 'nullable|integer|exists:fin_bank_accounts,id',
            'priority'         => 'nullable|integer',
            'match_type'       => 'required|in:contains,equals,starts,regex',
            'match_value'      => 'required|string|max:255',
            'direction'        => 'required|in:any,in,out',
            'allocation_type'  => 'required|in:account,customer,supplier',
            'contra_account_id' => 'required_if:allocation_type,account|nullable|integer|exists:fin_accounts,id',
            'party_id'         => 'required_if:allocation_type,customer|required_if:allocation_type,supplier|nullable|integer|exists:fin_parties,id',
            'is_active'        => 'boolean',
        ]);
    }

    private function rulesPayload(FinBankAccount $bank): \Illuminate\Support\Collection
    {
        return FinBankRule::with(['contraAccount', 'party'])
            ->where(fn ($q) => $q->whereNull('bank_account_id')->orWhere('bank_account_id', $bank->id))
            ->orderBy('priority')->get()
            ->map(fn (FinBankRule $r) => [
                'id' => $r->id, 'name' => $r->name, 'priority' => $r->priority, 'match_type' => $r->match_type,
                'match_value' => $r->match_value, 'direction' => $r->direction, 'allocation_type' => $r->allocation_type,
                'contra_account_id' => $r->contra_account_id, 'party_id' => $r->party_id, 'is_active' => $r->is_active,
                'bank_account_id' => $r->bank_account_id,
                'target' => $r->allocation_type === 'account'
                    ? ($r->contraAccount ? "{$r->contraAccount->code} · {$r->contraAccount->name}" : '—')
                    : ($r->party?->name ?? '—'),
            ]);
    }

    private function rules(Request $request, bool $editing = false): array
    {
        return $request->validate([
            'account_id'     => 'nullable|exists:fin_accounts,id', // blank → auto-created
            'name'           => 'required|string|max:120',
            'type'           => 'required|in:' . implode(',', FinBankAccount::TYPES),
            'currency'       => 'nullable|string|size:3',
            'bank_name'      => 'nullable|string|max:80',
            'branch'         => 'nullable|string|max:80',
            'branch_code'    => 'nullable|string|max:20',
            'account_number' => 'nullable|string|max:40',
            'is_active'      => 'boolean',
        ]);
    }
}
