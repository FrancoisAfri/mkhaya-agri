<?php

namespace App\Modules\HR\Payroll\Services\Bureau;

use App\Modules\Finance\Sars\Models\SarsSubmission;
use App\Modules\HR\Payroll\Models\Bureau\BureauCompany;
use App\Modules\HR\Payroll\Models\Bureau\BureauDisbursement;
use App\Modules\HR\Payroll\Models\Bureau\BureauEmployee;
use App\Modules\HR\Payroll\Models\Bureau\BureauPayRun;
use App\Modules\HR\Payroll\Models\EmploymentMilestone;
use App\Modules\HR\Payroll\Models\EtiClaim;
use App\Modules\HR\Payroll\Support\PayPeriod;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What a bureau has to do today, across every client at once.
 *
 * The dashboard was a list of client cards, which answers "who are my clients" — a question nobody
 * has. The question a bureau actually opens the system with is "what is outstanding, and what is
 * about to go wrong", and until now that could only be answered by opening each client in turn and
 * remembering what you saw. With forty clients on three pay cycles, that is not a thing anybody does
 * reliably on the 24th.
 *
 * Every panel is a work queue: a count, and the clients behind it. Nothing here is informational.
 */
class BureauOperationsService
{
    /** How close to the twenty-four months counts as "about to run out". */
    private const ETI_WARNING_MONTHS = 3;

    public function __construct(private readonly PayrollValidationService $validation)
    {
    }

    /**
     * @param  Collection<int, BureauCompany>  $clients  the agent's clients
     */
    public function overview(Collection $clients, ?Carbon $asAt = null): array
    {
        $asAt = $asAt ? $asAt->copy()->startOfDay() : Carbon::now()->startOfDay();
        $ids = $clients->pluck('id')->all();

        if (empty($ids)) {
            return $this->empty();
        }

        $names = $clients->pluck('name', 'id');
        $uuids = $clients->pluck('uuid', 'id');

        return [
            'due'          => $this->payrollsDue($clients, $asAt),
            'awaiting'     => $this->awaitingApproval($ids, $names, $uuids),
            'blocked'      => $this->blocked($ids, $names, $uuids),
            'emp201'       => $this->emp201Outstanding($ids, $names, $uuids),
            'payments'     => $this->paymentsOutstanding($ids, $names, $uuids),
            'failed'       => $this->paymentsFailed($ids, $names, $uuids),
            'eti_ending'   => $this->etiEnding($ids, $names, $uuids),
            'milestones'   => $this->milestones($ids, $names, $uuids),
        ];
    }

    /**
     * Clients with no run covering today, per cycle.
     *
     * Read per frequency rather than per client, because a client running weekly wages beside
     * monthly salaries is due twice, and a single "has a run this month" check would call them done
     * while the wage staff go unpaid.
     *
     * "Covering today" rather than "covering the calendar month", because a fortnight has no
     * calendar anchor — a client's fortnights start wherever their first one did. The period we
     * suggest follows on from their last run, so it stays on their cycle rather than on ours.
     */
    private function payrollsDue(Collection $clients, Carbon $asAt): array
    {
        $rows = [];

        foreach ($clients as $client) {
            foreach ($this->frequenciesFor($client) as $frequency) {
                $covered = BureauPayRun::query()
                    ->where('bureau_company_id', $client->id)
                    ->where('run_type', 'regular')
                    ->where('frequency', $frequency)
                    ->whereDate('period_start', '<=', $asAt)
                    ->whereDate('period_end', '>=', $asAt)
                    ->exists();

                if ($covered) {
                    continue;
                }

                [$start, $end] = $this->nextPeriodFor($client, $frequency, $asAt);

                $rows[] = [
                    'client'    => $client->name,
                    'uuid'      => $client->uuid,
                    'frequency' => $frequency,
                    'period'    => PayPeriod::label($frequency, $start, $end),
                    'due_on'    => $end->toDateString(),
                ];
            }
        }

        return $this->panel($rows);
    }

    /**
     * The period this client is owed next on this cycle.
     *
     * Continued from their last run so the dates stay on their cycle. With no history to continue
     * from there is nothing to anchor a weekly or fortnightly cycle to, so it starts today; monthly
     * starts on the first, which is what almost every monthly client actually uses.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function nextPeriodFor(BureauCompany $client, string $frequency, Carbon $asAt): array
    {
        $last = BureauPayRun::query()
            ->where('bureau_company_id', $client->id)
            ->where('run_type', 'regular')
            ->where('frequency', $frequency)
            ->orderByDesc('period_end')
            ->first();

        $start = $last
            ? PayPeriod::nextStart($frequency, $last->period_start)
            : ($frequency === 'monthly' ? $asAt->copy()->startOfMonth() : $asAt->copy());

        return [$start, PayPeriod::endFor($frequency, $start)];
    }

    /**
     * The cycles a client actually pays on.
     *
     * Their default, plus any frequency an employee has been put on individually — otherwise the
     * three people moved to weekly wages last month are invisible until somebody notices they were
     * never paid.
     *
     * @return list<string>
     */
    private function frequenciesFor(BureauCompany $client): array
    {
        $default = PayrollTaxEngine::canonicalFrequency($client->pay_frequency) ?? 'monthly';

        $theirs = BureauEmployee::query()
            ->where('bureau_company_id', $client->id)
            ->where('status', BureauEmployee::STATUS_ACTIVE)
            ->whereNotNull('pay_frequency')
            ->distinct()
            ->pluck('pay_frequency')
            ->map(fn ($f) => PayrollTaxEngine::canonicalFrequency($f))
            ->filter()
            ->all();

        return array_values(array_unique([$default, ...$theirs]));
    }

    /** Processed but not finalised — somebody has to look at these before payday. */
    private function awaitingApproval(array $ids, Collection $names, Collection $uuids): array
    {
        $runs = BureauPayRun::query()
            ->whereIn('bureau_company_id', $ids)
            ->where('status', BureauPayRun::STATUS_REVIEW)
            ->orderBy('period_end')
            ->get();

        return $this->panel($runs->map(fn (BureauPayRun $r) => [
            'client'    => $names[$r->bureau_company_id] ?? '—',
            'uuid'      => $uuids[$r->bureau_company_id] ?? null,
            'run'       => $r->name,
            'run_uuid'  => $r->uuid,
            'frequency' => $r->frequency,
            'net'       => (float) $r->total_net,
            'due_on'    => $r->pay_date?->toDateString() ?? $r->period_end?->toDateString(),
        ])->all());
    }

    /**
     * Runs that cannot be finalised as they stand.
     *
     * The most expensive panel on the page — it validates every waiting run — and the only one that
     * says which clients will stall on the 25th. Limited to runs already in review, because a draft
     * nobody has calculated has nothing to be wrong with yet.
     */
    private function blocked(array $ids, Collection $names, Collection $uuids): array
    {
        $rows = [];

        $runs = BureauPayRun::query()
            ->whereIn('bureau_company_id', $ids)
            ->where('status', BureauPayRun::STATUS_REVIEW)
            ->with('company')
            ->get();

        foreach ($runs as $run) {
            $report = $this->validation->validate($run);

            if ($report['blockers'] === 0) {
                continue;
            }

            $rows[] = [
                'client'   => $names[$run->bureau_company_id] ?? '—',
                'uuid'     => $uuids[$run->bureau_company_id] ?? null,
                'run'      => $run->name,
                'run_uuid' => $run->uuid,
                'blockers' => $report['blockers'],
                'first'    => $report['findings'][0]['message'] ?? null,
            ];
        }

        return $this->panel($rows);
    }

    /**
     * Finalised months with no EMP201 filed against them.
     *
     * The return is due by the 7th and the penalty is a percentage of what was owed, so a month
     * that quietly went unfiled is the most expensive thing on this page.
     */
    private function emp201Outstanding(array $ids, Collection $names, Collection $uuids): array
    {
        $runs = BureauPayRun::query()
            ->whereIn('bureau_company_id', $ids)
            ->where('status', BureauPayRun::STATUS_FINALISED)
            ->whereDate('period_end', '>=', Carbon::now()->subMonths(6)->startOfMonth())
            ->get();

        if ($runs->isEmpty()) {
            return $this->panel([]);
        }

        $filed = SarsSubmission::query()
            ->where('tax_type', 'emp201')
            ->whereIn('bureau_company_id', $ids)
            ->get()
            ->map(fn ($s) => $s->bureau_company_id . '|' . $s->period)
            ->flip();

        $rows = [];

        foreach ($runs as $run) {
            $period = Carbon::parse($run->period_end)->format('Y-m');

            if ($filed->has($run->bureau_company_id . '|' . $period)) {
                continue;
            }

            $rows[$run->bureau_company_id . '|' . $period] = [
                'client'   => $names[$run->bureau_company_id] ?? '—',
                'uuid'     => $uuids[$run->bureau_company_id] ?? null,
                'period'   => $period,
                'run_uuid' => $run->uuid,
                'paye'     => (float) $run->total_paye,
                // The 7th of the month after the period, which is when SARS wants it.
                'due_on'   => Carbon::parse($run->period_end)->addMonthNoOverflow()->startOfMonth()->addDays(6)->toDateString(),
            ];
        }

        return $this->panel(array_values($rows));
    }

    /** Finalised runs whose money has not gone anywhere yet. */
    private function paymentsOutstanding(array $ids, Collection $names, Collection $uuids): array
    {
        $paid = BureauDisbursement::query()
            ->whereIn('bureau_company_id', $ids)
            ->whereNotIn('status', ['failed'])
            ->pluck('bureau_pay_run_id')
            ->flip();

        $runs = BureauPayRun::query()
            ->whereIn('bureau_company_id', $ids)
            ->where('status', BureauPayRun::STATUS_FINALISED)
            ->whereDate('period_end', '>=', Carbon::now()->subMonths(3)->startOfMonth())
            ->orderBy('pay_date')
            ->get()
            ->reject(fn (BureauPayRun $r) => $paid->has($r->id));

        return $this->panel($runs->map(fn (BureauPayRun $r) => [
            'client'   => $names[$r->bureau_company_id] ?? '—',
            'uuid'     => $uuids[$r->bureau_company_id] ?? null,
            'run'      => $r->name,
            'run_uuid' => $r->uuid,
            'net'      => (float) $r->total_net,
            'due_on'   => $r->pay_date?->toDateString() ?? $r->period_end?->toDateString(),
        ])->values()->all());
    }

    /** Batches the provider rejected — nobody was paid, and nothing says so anywhere else. */
    private function paymentsFailed(array $ids, Collection $names, Collection $uuids): array
    {
        $failed = BureauDisbursement::query()
            ->whereIn('bureau_company_id', $ids)
            ->where('status', 'failed')
            ->whereDate('created_at', '>=', Carbon::now()->subMonths(3))
            ->latest('created_at')
            ->get();

        return $this->panel($failed->map(fn (BureauDisbursement $d) => [
            'client'    => $names[$d->bureau_company_id] ?? '—',
            'uuid'      => $uuids[$d->bureau_company_id] ?? null,
            'provider'  => $d->provider,
            'reference' => $d->reference,
            'amount'    => (float) $d->total_amount,
            'count'     => (int) $d->count,
            'due_on'    => $d->created_at?->toDateString(),
        ])->all());
    }

    /**
     * Employees within a few months of the end of their incentive, counted from the ledger.
     *
     * The claim is worth up to R1 500 a month, so a client with eight interns is being told about
     * real money — and the month after it ends is the month an over-claim starts.
     */
    private function etiEnding(array $ids, Collection $names, Collection $uuids): array
    {
        $counts = EtiClaim::query()
            ->where('employee_type', EtiClaim::BUREAU)
            ->where('eligible', true)
            ->whereIn('bureau_company_id', $ids)
            ->groupBy('employee_id', 'bureau_company_id')
            ->havingRaw('COUNT(*) >= ?', [EtiClaim::MAX_MONTHS - self::ETI_WARNING_MONTHS])
            ->select('employee_id', 'bureau_company_id', DB::raw('COUNT(*) as months'))
            ->get();

        if ($counts->isEmpty()) {
            return $this->panel([]);
        }

        $employees = BureauEmployee::query()
            ->whereIn('id', $counts->pluck('employee_id'))
            ->get(['id', 'first_name', 'last_name', 'employee_number'])
            ->keyBy('id');

        return $this->panel($counts->map(function ($row) use ($employees, $names, $uuids) {
            $employee = $employees[$row->employee_id] ?? null;

            return [
                'client'    => $names[$row->bureau_company_id] ?? '—',
                'uuid'      => $uuids[$row->bureau_company_id] ?? null,
                'employee'  => $employee?->fullName() ?? "Employee #{$row->employee_id}",
                'number'    => $employee?->employee_number,
                'claimed'   => (int) $row->months,
                'remaining' => max(0, EtiClaim::MAX_MONTHS - (int) $row->months),
            ];
        })->sortBy('remaining')->values()->all());
    }

    /** Probation reviews and anniversaries nobody has acted on yet. */
    private function milestones(array $ids, Collection $names, Collection $uuids): array
    {
        $open = EmploymentMilestone::query()
            ->open()
            ->whereIn('bureau_company_id', $ids)
            ->orderByDesc('due_on')
            ->limit(50)
            ->get();

        return $this->panel($open->map(fn (EmploymentMilestone $m) => [
            'client'   => $names[$m->bureau_company_id] ?? '—',
            'uuid'     => $uuids[$m->bureau_company_id] ?? null,
            'employee' => $m->employee_name,
            'number'   => $m->employee_number,
            'months'   => $m->months,
            'reason'   => $m->reason,
            'due_on'   => $m->due_on?->toDateString(),
        ])->all());
    }

    /**
     * A panel is a count and its rows.
     *
     * Both, always — a count with no rows behind it sends somebody hunting through forty clients for
     * the three that need them, which is the work the dashboard was supposed to remove.
     */
    private function panel(array $rows): array
    {
        return ['count' => count($rows), 'rows' => array_values($rows)];
    }

    private function empty(): array
    {
        $blank = ['count' => 0, 'rows' => []];

        return [
            'due' => $blank, 'awaiting' => $blank, 'blocked' => $blank, 'emp201' => $blank,
            'payments' => $blank, 'failed' => $blank, 'eti_ending' => $blank, 'milestones' => $blank,
        ];
    }
}
