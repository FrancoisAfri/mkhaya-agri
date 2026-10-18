<?php

namespace App\Modules\Finance\Accounting\Services;

use App\Modules\Finance\Accounting\Models\FinCollectionActivity;
use App\Modules\Finance\Accounting\Models\FinInvoice;
use App\Modules\Finance\Accounting\Models\FinParty;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * Debtors Manager core: the outstanding-receivables worklist (paginated, searchable, filterable and
 * prioritised) plus book-level KPIs, currency-grouped totals and the collections activity log.
 *
 * Correctness notes vs the old version:
 *  - Excludes credit notes (doc_type = 'invoice') so they no longer masquerade as debtors.
 *  - KPI cards are computed over the WHOLE receivable book, independent of the grid filters.
 *  - Totals are grouped by currency (no more ZAR+USD summed into one figure).
 *  - Filtering, ageing and prioritisation happen in SQL, and the grid is paginated — so it scales.
 *
 * Reminders/dunning live in DunningService; AR metrics (DSO, CEI …) in DebtorMetricsService; payment
 * posting reuses ArApService::recordPayment (proper double-entry).
 */
class DebtorsService
{
    private const BUCKETS = ['current', '1-30', '31-60', '61-90', '91-120', '120+'];

    /** Base query: open receivable invoices with a positive balance (credit notes excluded). */
    private function base(): Builder
    {
        return FinInvoice::query()
            ->where('direction', 'receivable')
            ->where('doc_type', 'invoice')
            ->whereIn('status', ['open', 'part_paid'])
            ->whereRaw('total - amount_paid > 0.001');
    }

    /** SQL fragment for whole days overdue (Postgres: date subtraction → int days). */
    private function daysOverdueSql(): string
    {
        return 'GREATEST(0, (CURRENT_DATE - due_date))';
    }

    /**
     * The debtors worklist for the grid, plus book-level KPIs and ageing distribution.
     *
     * Filters: party_id, assigned_to ('me'|id|'unassigned'), bucket, overdue_only, status
     * ('disputed'|'promised'|'broken'), search (invoice # or customer). Sort: due|outstanding|
     * days_overdue|priority|party (dir asc|desc). Paginated by `per_page`/`page`.
     */
    public function outstanding(array $filters = []): array
    {
        $today = Carbon::today();
        $overdueSql = $this->daysOverdueSql();

        $q = $this->base()->with(['party:id,name,email,phone,credit_limit,do_not_contact', 'collector.person']);

        if ($pid = ($filters['party_id'] ?? null)) {
            $q->where('party_id', $pid);
        }
        if (! empty($filters['overdue_only'])) {
            $q->whereDate('due_date', '<', $today);
        }
        $this->applyBucket($q, $filters['bucket'] ?? 'all');
        $this->applyAssigned($q, $filters['assigned_to'] ?? null);
        $this->applyStatusFilter($q, $filters['status'] ?? null, $today);

        if ($s = trim((string) ($filters['search'] ?? ''))) {
            $q->where(function ($w) use ($s) {
                $w->where('number', 'ilike', "%{$s}%")
                    ->orWhereHas('party', fn ($p) => $p->where('name', 'ilike', "%{$s}%"));
            });
        }

        $this->applySort($q, $filters['sort'] ?? 'priority', $filters['dir'] ?? 'desc', $overdueSql);

        $perPage = min(max((int) ($filters['per_page'] ?? 25), 5), 200);
        /** @var LengthAwarePaginator $page */
        $page = $q->paginate($perPage)->withQueryString();

        // Per-customer outstanding totals (for credit-limit exposure) — one aggregate query.
        $exposure = $this->base()
            ->selectRaw('party_id, SUM(total - amount_paid) AS bal')
            ->groupBy('party_id')->pluck('bal', 'party_id');

        // Latest activity + active promise per invoice on THIS page — one query.
        $ids = collect($page->items())->pluck('id');
        $acts = FinCollectionActivity::whereIn('invoice_id', $ids)->orderByDesc('id')->get()->groupBy('invoice_id');

        $rows = collect($page->items())->map(fn (FinInvoice $inv) => $this->row($inv, $today, $acts->get($inv->id) ?? collect(), (float) ($exposure[$inv->party_id] ?? 0)));

        return [
            'rows'       => $rows->all(),
            'pagination' => [
                'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(), 'total' => $page->total(), 'from' => $page->firstItem(), 'to' => $page->lastItem(),
            ],
            'kpis'    => $this->kpis($today),
            'ageing'  => $this->ageingDistribution($today),
            'filters' => $filters,
        ];
    }

    /** Shape one worklist row. */
    private function row(FinInvoice $inv, Carbon $today, Collection $acts, float $partyExposure): array
    {
        $due = Carbon::parse($inv->due_date);
        $daysOverdue = $due->lt($today) ? (int) $due->diffInDays($today) : 0;
        $outstanding = $inv->outstanding();
        $last = $acts->first();
        $promise = $acts->firstWhere(fn ($a) => $a->type === 'promise' && $a->promised_date);
        $activePromise = $promise && $promise->promised_date->gte($today->copy()->subDays(0)) && $promise->promised_date->gte($today) ? $promise : null;
        $brokenPromise = $promise && $promise->promised_date->lt($today) && $outstanding > 0.01;
        $limit = (float) ($inv->party->credit_limit ?? 0);

        return [
            'id' => $inv->id, 'number' => $inv->number, 'party' => $inv->party->name, 'party_id' => $inv->party_id,
            'has_email' => (bool) $inv->party->email, 'has_phone' => (bool) $inv->party->phone,
            'do_not_contact' => (bool) $inv->party->do_not_contact,
            'date' => $inv->invoice_date?->toDateString(), 'due' => $inv->due_date?->toDateString(),
            'currency' => $inv->currency, 'total' => (float) $inv->total, 'outstanding' => $outstanding,
            'days_overdue' => $daysOverdue, 'bucket' => $this->bucket($daysOverdue), 'status' => $inv->status,
            'disputed' => (bool) $inv->disputed, 'dispute_reason' => $inv->dispute_reason,
            'assigned_to' => $inv->assigned_to, 'assignee' => $this->personName($inv->collector),
            'dunning_step' => (int) $inv->dunning_step, 'last_reminded_at' => $inv->last_reminded_at?->toDateString(),
            'reminders' => $acts->where('type', 'reminder')->count(),
            'priority' => round($outstanding * (1 + $daysOverdue / 30), 2),
            'credit_limit' => $limit, 'party_exposure' => round($partyExposure, 2),
            'over_limit' => $limit > 0 && $partyExposure > $limit,
            'promise' => $activePromise ? ['date' => $activePromise->promised_date->toDateString(), 'amount' => (float) $activePromise->promised_amount] : null,
            'broken_promise' => $brokenPromise ? ['date' => $promise->promised_date->toDateString()] : null,
            'last_action' => $last ? ['type' => $last->type, 'body' => $last->body, 'date' => $last->created_at?->toDateString(), 'next_action_date' => $last->next_action_date?->toDateString()] : null,
            'pay_url' => $inv->pay_token ? route('finance.pay.show', $inv->pay_token) : null,
        ];
    }

    /** Book-level KPIs over the whole receivable ledger (currency-grouped). */
    public function kpis(Carbon $today): array
    {
        $byCurrency = $this->base()
            ->selectRaw("currency,
                SUM(total - amount_paid) AS outstanding,
                SUM(CASE WHEN due_date < CURRENT_DATE THEN total - amount_paid ELSE 0 END) AS overdue,
                SUM(CASE WHEN due_date >= CURRENT_DATE THEN total - amount_paid ELSE 0 END) AS current,
                COUNT(*) AS cnt")
            ->groupBy('currency')->get()
            ->map(fn ($r) => [
                'currency' => $r->currency,
                'outstanding' => round((float) $r->outstanding, 2),
                'overdue' => round((float) $r->overdue, 2),
                'current' => round((float) $r->current, 2),
                'count' => (int) $r->cnt,
            ])->values();

        $disputed = $this->base()->where('disputed', true)
            ->selectRaw('COUNT(*) AS cnt, SUM(total - amount_paid) AS amt')->first();

        return [
            'by_currency'         => $byCurrency->all(),
            'total_outstanding'   => round($byCurrency->sum('outstanding'), 2),
            'overdue_outstanding' => round($byCurrency->sum('overdue'), 2),
            'current_outstanding' => round($byCurrency->sum('current'), 2),
            'count'               => (int) $byCurrency->sum('count'),
            'multi_currency'      => $byCurrency->count() > 1,
            'disputed_count'      => (int) ($disputed->cnt ?? 0),
            'disputed_amount'     => round((float) ($disputed->amt ?? 0), 2),
        ];
    }

    /** Count + value per ageing bucket over the whole book (for the ageing bar). */
    public function ageingDistribution(Carbon $today): array
    {
        $rows = $this->base()
            ->selectRaw("
                CASE
                    WHEN due_date >= CURRENT_DATE THEN 'current'
                    WHEN CURRENT_DATE - due_date <= 30 THEN '1-30'
                    WHEN CURRENT_DATE - due_date <= 60 THEN '31-60'
                    WHEN CURRENT_DATE - due_date <= 90 THEN '61-90'
                    WHEN CURRENT_DATE - due_date <= 120 THEN '91-120'
                    ELSE '120+'
                END AS bucket,
                COUNT(*) AS cnt, SUM(total - amount_paid) AS value")
            ->groupBy('bucket')->get()->keyBy('bucket');

        return collect(self::BUCKETS)->map(fn ($b) => [
            'bucket' => $b,
            'count'  => (int) ($rows[$b]->cnt ?? 0),
            'value'  => round((float) ($rows[$b]->value ?? 0), 2),
        ])->all();
    }

    private function applyBucket(Builder $q, string $bucket): void
    {
        if ($bucket === 'all' || $bucket === '') {
            return;
        }
        $ranges = ['1-30' => [1, 30], '31-60' => [31, 60], '61-90' => [61, 90], '91-120' => [91, 120]];
        if ($bucket === 'current') {
            $q->whereRaw('due_date >= CURRENT_DATE');
        } elseif ($bucket === '120+') {
            $q->whereRaw('CURRENT_DATE - due_date > 120');
        } elseif (isset($ranges[$bucket])) {
            [$lo, $hi] = $ranges[$bucket];
            $q->whereRaw('CURRENT_DATE - due_date BETWEEN ? AND ?', [$lo, $hi]);
        }
    }

    private function applyAssigned(Builder $q, $assigned): void
    {
        if ($assigned === null || $assigned === '' || $assigned === 'all') {
            return;
        }
        if ($assigned === 'me') {
            $q->where('assigned_to', Auth::id());
        } elseif ($assigned === 'unassigned') {
            $q->whereNull('assigned_to');
        } elseif (is_numeric($assigned)) {
            $q->where('assigned_to', (int) $assigned);
        }
    }

    private function applyStatusFilter(Builder $q, ?string $status, Carbon $today): void
    {
        if ($status === 'disputed') {
            $q->where('disputed', true);
        } elseif ($status === 'promised') {
            $q->whereHas('collectionActivities', fn ($a) => $a->where('type', 'promise')->whereDate('promised_date', '>=', $today));
        } elseif ($status === 'broken') {
            $q->whereHas('collectionActivities', fn ($a) => $a->where('type', 'promise')->whereDate('promised_date', '<', $today));
        }
    }

    private function applySort(Builder $q, string $sort, string $dir, string $overdueSql): void
    {
        $dir = strtolower($dir) === 'asc' ? 'asc' : 'desc';
        match ($sort) {
            'outstanding'  => $q->orderByRaw("(total - amount_paid) {$dir}"),
            'days_overdue' => $q->orderByRaw("due_date " . ($dir === 'asc' ? 'desc' : 'asc')),
            'due'          => $q->orderBy('due_date', $dir),
            'party'        => $q->orderBy(FinParty::select('name')->whereColumn('fin_parties.id', 'fin_invoices.party_id'), $dir),
            default        => $q->orderByRaw("(total - amount_paid) * (1 + {$overdueSql}::numeric / 30) {$dir}"),
        };
    }

    private function bucket(int $daysOverdue): string
    {
        if ($daysOverdue <= 0) {
            return 'current';
        }
        foreach ([30 => '1-30', 60 => '31-60', 90 => '61-90', 120 => '91-120'] as $max => $label) {
            if ($daysOverdue <= $max) {
                return $label;
            }
        }

        return '120+';
    }

    private function personName($user): ?string
    {
        if (! $user) {
            return null;
        }
        $p = $user->person ?? null;

        return $p && $p->first_name ? trim($p->first_name . ' ' . $p->surname) : ($user->email ?? null);
    }

    // ── Activity log ──────────────────────────────────────────────────────────

    public function logActivity(FinInvoice $invoice, array $data): FinCollectionActivity
    {
        return FinCollectionActivity::create([
            'invoice_id' => $invoice->id, 'party_id' => $invoice->party_id,
            'type' => $data['type'], 'body' => $data['body'] ?? null,
            'promised_date' => $data['promised_date'] ?? null, 'promised_amount' => $data['promised_amount'] ?? null,
            'reason_code' => $data['reason_code'] ?? null, 'next_action_date' => $data['next_action_date'] ?? null,
            'channel' => $data['channel'] ?? null, 'meta' => $data['meta'] ?? null, 'created_by' => Auth::id(),
        ]);
    }

    public function timeline(FinInvoice $invoice): array
    {
        return FinCollectionActivity::with('author.person')->where('invoice_id', $invoice->id)
            ->orderByDesc('id')->get()
            ->map(fn ($a) => [
                'type' => $a->type, 'body' => $a->body, 'channel' => $a->channel,
                'promised_date' => $a->promised_date?->toDateString(), 'promised_amount' => $a->promised_amount ? (float) $a->promised_amount : null,
                'reason_code' => $a->reason_code, 'next_action_date' => $a->next_action_date?->toDateString(),
                'by' => $this->personName($a->author) ?? 'System', 'at' => $a->created_at?->toDateTimeString(),
            ])->all();
    }

    // ── Collector assignment & disputes ───────────────────────────────────────

    public function assign(FinInvoice $invoice, ?int $userId): void
    {
        $invoice->update(['assigned_to' => $userId]);
        $this->logActivity($invoice, ['type' => 'assignment', 'body' => $userId ? 'Assigned to collector.' : 'Unassigned.']);
    }

    public function setDispute(FinInvoice $invoice, bool $disputed, ?string $reason = null): void
    {
        $invoice->update([
            'disputed' => $disputed,
            'dispute_reason' => $disputed ? $reason : null,
            'disputed_at' => $disputed ? now() : null,
            'disputed_by' => $disputed ? Auth::id() : null,
        ]);
        $this->logActivity($invoice, [
            'type' => $disputed ? 'dispute' : 'dispute_resolved',
            'body' => $disputed ? ('Disputed: ' . ($reason ?: '—')) : 'Dispute resolved.',
        ]);
    }
}
