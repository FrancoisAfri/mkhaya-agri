<?php

namespace App\Console\Commands\Finance;

use App\Modules\Finance\Accounting\Models\FinPosting;
use App\Modules\Finance\Accounting\Models\FinEntity;
use App\Modules\Finance\Accounting\Services\AgeingService;
use App\Modules\Finance\Accounting\Services\CurrentEntity;
use App\Modules\Finance\Accounting\Services\FinanceReportService;
use App\Modules\HR\Payroll\Models\PayrollRun;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Subledger ↔ GL integrity monitor, one set of books at a time.
 *
 * It asserts that each ledger is internally consistent and that each posted subledger ties to its
 * GL journals. Any drift, or a stuck outbox row, is surfaced — schedule daily; alert on failure.
 *
 * **Why it runs per company.** It used to sum the whole estate: one trial balance, one payroll
 * sweep, one AR/AP tie-out across every tenant at once. Debits and credits are signed, so two
 * tenants out of balance in opposite directions net to zero and the check reports OK while both
 * sets of books are wrong. Even when it did fail it named no company, so the alert told an operator
 * that something, somewhere, had drifted. A consolidated tie-out is a real report — see
 * ConsolidationService — but it is not an integrity check.
 *
 * **Why the books are read off the data rather than off the company list.** Rows that predate
 * Finance being tenanted carry no company_id at all, as does everything in a single-tenant
 * self-hosted install. Iterating active companies would have walked straight past them: on a
 * database whose ledger is entirely untenanted, the check would examine nothing and pass. So the
 * set of books is whatever company_id values actually appear in the ledger, with the company-less
 * rows treated as a set of books in their own right rather than quietly skipped.
 */
class FinanceParityCheck extends Command
{
    protected $signature = 'finance:parity
                            {--company= : Check one company id, or "unassigned" for company-less books}';

    protected $description = 'Verify GL balances and subledger↔GL parity (payroll, …), per company';

    public function handle(TenantContext $tenants, FinanceReportService $reports, AgeingService $ageing): int
    {
        $entities = app(CurrentEntity::class);
        $books = $this->books();

        if ($books === []) {
            $this->components->info('No financial data to check.');

            return self::SUCCESS;
        }

        $drifted = [];
        $crashed = [];

        foreach ($books as $book) {
            $label = $this->label($book);

            try {
                /*
                 * The unassigned pass deliberately runs with no tenant applied: applying one would
                 * make row-level security hide the very rows it exists to look at.
                 */
                if ($book === FinanceReportService::UNASSIGNED) {
                    $ok = $this->check($book, $label, $reports, $ageing);

                    if (! $ok) {
                        $drifted[] = $label;
                    }

                    continue;
                }

                /*
                 * And once per business inside the company, for the reason this command runs per
                 * company at all: debits and credits are signed, so Mkhaya MK 20,000 debit-heavy
                 * and Mkhaya Move 20,000 credit-heavy cancel exactly when summed, and a check that
                 * looked at the company as a whole would report OK on two sets of books that
                 * neither of them balances.
                 *
                 * A company with one business (every company, until somebody adds a second) gets
                 * one pass with no entity pinned, which is exactly what it did before.
                 */
                foreach ($this->businesses((int) $book) as [$entityId, $suffix]) {
                    $passLabel = $label.$suffix;

                    $ok = $tenants->run(
                        (int) $book,
                        fn () => $entities->run($entityId, fn () => $this->check($book, $passLabel, $reports, $ageing))
                    );

                    if (! $ok) {
                        $drifted[] = $passLabel;
                    }
                }
            } catch (Throwable $e) {
                // One unreadable set of books must not stop the others being checked — that is how
                // a daily integrity check silently stops covering most of the estate.
                $crashed[] = $label;
                report($e);
                $this->components->twoColumnDetail($label, '<fg=red>check failed</> — '.$e->getMessage());
            }
        }

        $this->newLine();

        if ($drifted === [] && $crashed === []) {
            $this->components->info(sprintf('Parity OK across %d set(s) of books.', count($books)));

            return self::SUCCESS;
        }

        if ($drifted !== []) {
            $this->components->error('Parity FAILED — drift detected in: '.implode(', ', $drifted));
        }

        if ($crashed !== []) {
            $this->components->error('Could not be checked at all: '.implode(', ', $crashed));
        }

        return self::FAILURE;
    }

    /**
     * Every set of books that has anything in it.
     *
     * Unioned across the tables the checks below actually read, not taken from the companies table:
     * a company with no ledger has nothing to verify, and a ledger belonging to a company that has
     * since been deactivated still has to balance.
     *
     * @return list<int|string>
     */
    private function books(): array
    {
        $only = $this->option('company');

        if ($only !== null && $only !== '') {
            return [$only === FinanceReportService::UNASSIGNED ? FinanceReportService::UNASSIGNED : (int) $only];
        }

        $ids = collect();
        $unassigned = false;

        foreach (['fin_journals', 'fin_postings', 'fin_invoices', 'payroll_runs'] as $table) {
            $values = DB::table($table)->distinct()->pluck('company_id');

            $unassigned = $unassigned || $values->contains(null);
            $ids = $ids->merge($values->filter());
        }

        $books = $ids->unique()->sort()->map(fn ($id) => (int) $id)->values()->all();

        // Listed last, so a normal multi-tenant run reads company by company and the leftovers
        // appear at the end rather than heading the report.
        return $unassigned ? [...$books, FinanceReportService::UNASSIGNED] : $books;
    }

    /**
     * The businesses inside one company, as [entity id => label suffix].
     *
     * One business, or none, means one pass with nothing pinned — identical to the behaviour
     * before entities existed, and it keeps the output unchanged for every tenant that has not
     * added a second business.
     *
     * A list of pairs, not a map keyed by entity id: PHP turns a null array key into the empty
     * string, so `[null => '']` would hand the no-entity pass a `''` where an `?int` is required.
     *
     * @return list<array{0: int|null, 1: string}>
     */
    private function businesses(int $companyId): array
    {
        $entities = FinEntity::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->orderBy('id')
            ->pluck('name', 'id');

        if ($entities->count() < 2) {
            return [[null, '']];
        }

        return $entities->map(fn (string $name, int $id) => [$id, " — {$name}"])->values()->all();
    }

    private function label(int|string $book): string
    {
        if ($book === FinanceReportService::UNASSIGNED) {
            return 'Unassigned books (no company)';
        }

        $name = DB::table('companies')->where('id', $book)->value('name');

        return $name ? "{$name} (#{$book})" : "Company #{$book}";
    }

    /** Run all four checks for one set of books. Returns false if anything drifted. */
    private function check(
        int|string $book,
        string $label,
        FinanceReportService $reports,
        AgeingService $ageing,
    ): bool {
        $ok = true;
        $this->components->twoColumnDetail("<options=bold>{$label}</>", '');

        // 1) The ledger itself must balance.
        $tb = $reports->trialBalance($book);
        $balanced = $tb['balanced'];
        $ok = $ok && $balanced;
        $this->components->twoColumnDetail(
            '  Trial balance',
            sprintf('debit %.2f / credit %.2f — %s', $tb['total_debit'], $tb['total_credit'], $this->verdict($balanced)),
        );

        // 2) Stuck outbox rows. A pending posting is money the ledger has not heard about yet, so
        //    it invalidates every tie-out below rather than being a separate concern.
        $stuck = FinanceReportService::scopeToCompany(
            FinPosting::whereIn('status', ['pending', 'failed']),
            'company_id',
            $book,
        )->count();
        $ok = $ok && $stuck === 0;
        $this->components->twoColumnDetail('  Outbox pending/failed', $stuck.' — '.$this->verdict($stuck === 0));

        // 3) Payroll subledger ↔ GL: every released run should have a posted journal whose gross
        //    equals the run's total_gross.
        $drift = 0;
        $runs = FinanceReportService::scopeToCompany(
            PayrollRun::withoutGlobalScopes()->where('status', 'released'),
            'company_id',
            $book,
        )->get();

        foreach ($runs as $run) {
            $journalId = FinPosting::where('idempotency_key', 'payroll_run:'.$run->id)
                ->where('status', 'posted')->value('journal_id');

            if (! $journalId) {
                $this->warn("    Payroll run #{$run->id} released but not posted to GL.");
                $drift++;

                continue;
            }

            $glGross = (float) DB::table('fin_journal_lines')->where('journal_id', $journalId)->sum('debit');

            if (abs($glGross - (float) $run->total_gross) > 0.01) {
                $this->warn(sprintf('    Payroll run #%d: GL gross %.2f != run gross %.2f', $run->id, $glGross, (float) $run->total_gross));
                $drift++;
            }
        }

        $ok = $ok && $drift === 0;
        $this->components->twoColumnDetail(
            '  Payroll subledger↔GL',
            ($drift ? "{$drift} discrepancy(ies)" : $runs->count().' run(s)').' — '.$this->verdict($drift === 0),
        );

        // 4) AR/AP subledger ↔ GL: open invoices must equal the control account.
        foreach (['receivable', 'payable'] as $direction) {
            $age = $ageing->ageing($direction, null, $book);
            $ok = $ok && $age['ties'];
            $this->components->twoColumnDetail(
                '  '.ucfirst($direction)." ageing↔GL ({$age['gl_control_code']})",
                sprintf('open %.2f / control %.2f — %s', $age['totals']['total'], $age['gl_balance'], $this->verdict($age['ties'])),
            );
        }

        return $ok;
    }

    private function verdict(bool $ok): string
    {
        return $ok ? '<fg=green>OK</>' : '<fg=red>DRIFT</>';
    }
}
