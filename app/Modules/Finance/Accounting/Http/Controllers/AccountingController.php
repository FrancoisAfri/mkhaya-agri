<?php

namespace App\Modules\Finance\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Accounting\Contracts\FinancialEvent;
use App\Modules\Finance\Accounting\Exceptions\PostingException;
use App\Core\User\Models\User;
use App\Modules\Finance\Accounting\Models\FinAccount;
use App\Modules\Finance\Accounting\Models\FinExchangeRate;
use App\Modules\Finance\Accounting\Models\FinFiscalPeriod;
use App\Modules\Finance\Accounting\Models\FinJournal;
use App\Modules\Finance\Accounting\Models\FinPostingLimit;
use App\Modules\Finance\Accounting\Services\CoaImportService;
use App\Modules\Finance\Accounting\Services\FinanceReportService;
use App\Modules\Finance\Accounting\Services\Ledger;
use App\Modules\Finance\Accounting\Services\PeriodService;
use App\Modules\Finance\Accounting\Services\PostingAuthorizer;
use App\Modules\Finance\Accounting\Services\YearEndService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AccountingController extends Controller
{
    public function __construct(
        private Ledger $ledger,
        private FinanceReportService $reports,
        private PostingAuthorizer $authorizer,
        private PeriodService $periods,
    ) {
    }

    // ── Journals ────────────────────────────────────────────────────────────

    public function journals(Request $request): Response
    {
        // Tenant scope: the finance ledger tables use explicit company filtering (not RLS — the
        // Consolidation feature deliberately reads cross-company), so scope journals to the tenant.
        $journals = FinJournal::where('company_id', app(CurrentCompany::class)->id())
            ->with('lines.account')
            ->latest('journal_date')->latest('id')->limit(100)->get()
            ->map(fn (FinJournal $j) => [
                'id' => $j->id, 'journal_no' => $j->journal_no, 'date' => $j->journal_date?->toDateString(),
                'description' => $j->description, 'source_module' => $j->source_module, 'status' => $j->status,
                'amount' => (float) $j->lines->sum('debit'),
                'lines' => $j->lines->map(fn ($l) => [
                    'account' => $l->account?->code . ' · ' . $l->account?->name,
                    'debit' => (float) $l->debit, 'credit' => (float) $l->credit, 'narration' => $l->narration,
                ]),
            ]);

        return Inertia::render('Modules/Finance/Accounting/Journals', [
            'journals'  => $journals,
            'accounts'  => $this->postableAccounts(),
            'authority' => $this->authorizer->describe($request->user()),
        ]);
    }

    public function storeJournal(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'journal_date' => 'required|date',
            'description'  => 'nullable|string|max:500',
            'lines'        => 'required|array|min:2',
            'lines.*.account_id' => 'required|exists:fin_accounts,id',
            'lines.*.debit'      => 'nullable|numeric|min:0',
            'lines.*.credit'     => 'nullable|numeric|min:0',
            'lines.*.narration'  => 'nullable|string|max:500',
        ]);

        $debits = $credits = [];
        $total = 0.0;
        foreach ($data['lines'] as $line) {
            $debit  = round((float) ($line['debit'] ?? 0), 2);
            $credit = round((float) ($line['credit'] ?? 0), 2);
            if ($debit > 0)  { $debits[]  = ['account' => (int) $line['account_id'], 'amount' => $debit, 'narration' => $line['narration'] ?? null]; $total += $debit; }
            if ($credit > 0) $credits[] = ['account' => (int) $line['account_id'], 'amount' => $credit, 'narration' => $line['narration'] ?? null];
        }

        try {
            $this->authorizer->assertCanPost($request->user(), $total);

            $event = new FinancialEvent(
                sourceModule: 'manual', eventType: 'manual.journal',
                debits: $debits, credits: $credits,
                eventDate: $data['journal_date'], description: $data['description'] ?? '',
                idempotencyKey: 'manual:' . Str::uuid(),
            );
            $this->ledger->post($event);
        } catch (PostingException|\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['journal' => [$e->getMessage()]]);
        }

        return back()->with('success', 'Journal posted.');
    }

    public function reverse(Request $request, FinJournal $journal): RedirectResponse
    {
        $data = $request->validate(['reason' => 'required|string|max:300']);
        try {
            $this->ledger->reverse($journal, $data['reason']);
        } catch (PostingException $e) {
            throw ValidationException::withMessages(['journal' => [$e->getMessage()]]);
        }

        return back()->with('success', 'Journal reversed.');
    }

    // ── Chart of accounts ───────────────────────────────────────────────────

    public function accounts(): Response
    {
        return Inertia::render('Modules/Finance/Accounting/ChartOfAccounts', [
            'accounts' => FinAccount::orderBy('code')->get()
                ->map(fn (FinAccount $a) => [
                    'id' => $a->id, 'code' => $a->code, 'name' => $a->name, 'type' => $a->type,
                    'subtype' => $a->subtype, 'normal_balance' => $a->normal_balance,
                    'is_postable' => $a->is_postable, 'is_active' => $a->is_active, 'parent_id' => $a->parent_id,
                ]),
            'types' => FinAccount::TYPES,
        ]);
    }

    public function storeAccount(Request $request): RedirectResponse
    {
        FinAccount::create($this->accountRules($request));

        return back()->with('success', 'Account added.');
    }

    public function updateAccount(Request $request, FinAccount $account): RedirectResponse
    {
        $account->update($this->accountRules($request, $account->id));

        return back()->with('success', 'Account updated.');
    }

    public function importAccounts(Request $request, CoaImportService $importer): RedirectResponse
    {
        $request->validate(['file' => 'required|file|mimes:csv,txt,xlsx,xls|max:5120']);

        $summary = $importer->import($request->file('file'));

        return back()
            ->with('coaImport', $summary)
            ->with('success', "Chart imported: {$summary['imported']} added, {$summary['updated']} updated, " . count($summary['skipped']) . ' skipped.');
    }

    public function accountsTemplate(CoaImportService $importer): \Symfony\Component\HttpFoundation\Response
    {
        return response($importer->templateCsv(), 200, [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => 'attachment; filename="chart-of-accounts-template.csv"',
        ]);
    }

    // ── Reports ─────────────────────────────────────────────────────────────

    public function reportsPage(Request $request): Response
    {
        $asOf = $request->date('as_of')?->toDateString();
        $from = $request->date('from')?->toDateString() ?? now()->startOfYear()->toDateString();
        $cid = app(CurrentCompany::class)->id(); // scope financial statements to the tenant

        return Inertia::render('Modules/Finance/Accounting/Reports', [
            'as_of'           => $asOf ?? now()->toDateString(),
            'from'            => $from,
            'trialBalance'    => $this->reports->trialBalance($cid, $asOf),
            'incomeStatement' => $this->reports->incomeStatement($cid, $asOf),
            'balanceSheet'    => $this->reports->balanceSheet($cid, $asOf),
            'cashFlow'        => $this->reports->cashFlow($cid, $from, $asOf),
        ]);
    }

    // ── General Ledger (account drill-down) ─────────────────────────────────

    public function accountLedger(Request $request): Response
    {
        $accounts = FinAccount::postable()->orderBy('code')->get(['id', 'code', 'name']);
        $accountId = (int) ($request->integer('account_id') ?: $accounts->first()?->id);

        $from = $request->date('from')?->toDateString() ?? now()->startOfYear()->toDateString();
        $to   = $request->date('to')?->toDateString() ?? now()->toDateString();

        return Inertia::render('Modules/Finance/Accounting/AccountLedger', [
            'ledger'   => $accountId ? $this->reports->accountLedger($accountId, $from, $to, app(CurrentCompany::class)->id()) : null,
            'accounts' => $accounts->map(fn ($a) => ['id' => $a->id, 'label' => "{$a->code} · {$a->name}"]),
        ]);
    }

    // ── Period management ───────────────────────────────────────────────────
    // NB: periods() and updatePeriod() moved to FiscalController (tenant-scoped, dynamic fiscal
    // years). Only the legacy year-end-close alias remains here.

    public function yearEndClose(Request $request, YearEndService $service): RedirectResponse
    {
        $data = $request->validate(['year' => 'required|integer|min:2000|max:2100']);

        try {
            $r = $service->close((int) $data['year']);
        } catch (PostingException $e) {
            throw ValidationException::withMessages(['period' => [$e->getMessage()]]);
        }

        $msg = $r['already_closed']
            ? "Year {$data['year']} was already closed ({$r['journal_no']})."
            : ($r['journal_no']
                ? "Year {$data['year']} closed — net " . ($r['net_profit'] >= 0 ? 'profit' : 'loss') . ' ' . number_format($r['net_profit'], 2) . " to retained earnings (journal {$r['journal_no']})."
                : "Year {$data['year']} had no P&L to close.");

        return back()->with('success', $msg . " {$r['next_periods_created']} period(s) opened for " . ($data['year'] + 1) . '.');
    }

    // ── Exchange rates (0.3) ────────────────────────────────────────────────

    public function exchangeRates(): Response
    {
        return Inertia::render('Modules/Finance/Accounting/ExchangeRates', [
            'base_currency' => strtoupper((string) config('finance.base_currency', 'ZAR')),
            'rates' => FinExchangeRate::orderByDesc('effective_date')->orderBy('base_currency')->limit(200)->get()
                ->map(fn (FinExchangeRate $r) => [
                    'id' => $r->id, 'base_currency' => $r->base_currency, 'quote_currency' => $r->quote_currency,
                    'rate' => (float) $r->rate, 'rate_type' => $r->rate_type, 'source' => $r->source,
                    'effective_date' => $r->effective_date?->toDateString(),
                ]),
        ]);
    }

    public function storeExchangeRate(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'base_currency'  => 'required|string|size:3',
            'quote_currency' => 'required|string|size:3|different:base_currency',
            'rate'           => 'required|numeric|gt:0',
            'rate_type'      => 'nullable|in:spot,average,closing',
            'source'         => 'nullable|string|max:60',
            'effective_date' => 'required|date',
        ]);

        FinExchangeRate::updateOrCreate(
            [
                'base_currency'  => strtoupper($data['base_currency']),
                'quote_currency' => strtoupper($data['quote_currency']),
                'rate_type'      => $data['rate_type'] ?? 'spot',
                'effective_date' => $data['effective_date'],
            ],
            ['rate' => $data['rate'], 'source' => $data['source'] ?? null],
        );

        return back()->with('success', 'Exchange rate saved.');
    }

    // ── Posting limits (0.2 RBAC) ───────────────────────────────────────────

    public function postingLimits(): Response
    {
        $permitted = \Spatie\Permission\Models\Permission::where('name', PostingAuthorizer::PERMISSION)->exists()
            ? User::permission(PostingAuthorizer::PERMISSION)->get()
            : collect();
        $limits = FinPostingLimit::whereNull('company_id')->get()->keyBy('user_id');

        return Inertia::render('Modules/Finance/Accounting/PostingLimits', [
            'users' => $permitted->map(fn (User $u) => [
                'id'         => $u->id,
                'name'       => optional($u->person)->first_name
                    ? trim($u->person->first_name . ' ' . $u->person->surname)
                    : ($u->email ?? "User #{$u->id}"),
                'email'      => $u->email,
                'max_amount' => isset($limits[$u->id]) && $limits[$u->id]->max_amount !== null ? (float) $limits[$u->id]->max_amount : null,
                'can_post'   => $limits[$u->id]->can_post ?? true,
            ])->values(),
        ]);
    }

    public function storePostingLimit(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'user_id'    => 'required|exists:users,id',
            'max_amount' => 'nullable|numeric|min:0',
            'can_post'   => 'boolean',
        ]);

        FinPostingLimit::updateOrCreate(
            ['user_id' => $data['user_id'], 'company_id' => null],
            ['max_amount' => $data['max_amount'] ?? null, 'can_post' => $data['can_post'] ?? true],
        );

        return back()->with('success', 'Posting limit saved.');
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function postableAccounts()
    {
        return FinAccount::postable()->orderBy('code')->get(['id', 'code', 'name', 'type'])
            ->map(fn ($a) => ['id' => $a->id, 'label' => "{$a->code} · {$a->name}", 'type' => $a->type]);
    }

    private function accountRules(Request $request, ?int $ignore = null): array
    {
        return $request->validate([
            'code'        => 'required|string|max:40',
            'name'        => 'required|string|max:200',
            'type'        => 'required|in:asset,liability,equity,income,expense',
            'subtype'     => 'nullable|string|max:40',
            'parent_id'   => 'nullable|exists:fin_accounts,id',
            'is_postable' => 'boolean',
            'is_active'   => 'boolean',
        ]);
    }
}
