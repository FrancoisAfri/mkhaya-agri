<?php

namespace App\Modules\Finance\Accounting\Services;

use App\Modules\Finance\Accounting\Exceptions\PostingException;
use App\Modules\Finance\Accounting\Models\FinFiscalPeriod;
use App\Modules\Finance\Accounting\Models\FinInvoice;
use App\Modules\Finance\Accounting\Models\FinJournal;
use App\Modules\Finance\Accounting\Models\FinPosting;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Spatie\Activitylog\Models\Activity;

/**
 * Period lifecycle: open → soft_close (no new postings, corrections allowed) →
 * hard_close (corrections only) → locked (nothing). Reopening is audited.
 */
class PeriodService
{
    /**
     * Pre-close checks for a period. Errors block a hard close; warnings inform.
     *
     * @return array<int, array{level: string, message: string}>
     */
    public function preCloseChecks(FinFiscalPeriod $period): array
    {
        $issues = [];

        $drafts = FinJournal::where('period_id', $period->id)->where('status', 'draft')->count();
        if ($drafts > 0) {
            $issues[] = ['level' => 'error', 'message' => "{$drafts} unposted (draft) journal(s) in this period."];
        }

        $stuck = FinPosting::whereIn('status', ['pending', 'failed'])->count();
        if ($stuck > 0) {
            $issues[] = ['level' => 'warning', 'message' => "{$stuck} unprocessed posting(s) in the outbox — run finance:process-postings."];
        }

        // NB: trial-balance / AR / AP checks are heavier (full-GL) — they live in checklist() so
        // they run on-demand (View / closing wizard), not on every page-load row + summary.

        return $issues;
    }

    /**
     * The full pre-close checklist for the closing wizard / "View": computed checks (evaluated live)
     * plus manual tick items stored on the period. Each: {key, label, type, status, message}.
     * status ∈ pass | fail | warn | done | todo.
     */
    public function checklist(FinFiscalPeriod $period): array
    {
        $reports = app(FinanceReportService::class);
        $items = [];

        // ── computed ──
        $drafts = FinJournal::where('period_id', $period->id)->where('status', 'draft')->count();
        $items[] = ['key' => 'no_drafts', 'label' => 'No unposted journals', 'type' => 'computed',
            'status' => $drafts === 0 ? 'pass' : 'fail', 'message' => $drafts ? "{$drafts} draft journal(s)" : null];

        $stuck = FinPosting::whereIn('status', ['pending', 'failed'])->count();
        $items[] = ['key' => 'postings_clear', 'label' => 'Posting outbox clear', 'type' => 'computed',
            'status' => $stuck === 0 ? 'pass' : 'warn', 'message' => $stuck ? "{$stuck} unprocessed" : null];

        $tb = $reports->trialBalance($period->company_id, $period->end_date?->toDateString());
        $items[] = ['key' => 'trial_balance', 'label' => 'Trial balance balanced', 'type' => 'computed',
            'status' => ($tb['balanced'] ?? true) ? 'pass' : 'fail', 'message' => null];

        $openAr = FinInvoice::where('direction', 'receivable')->where('doc_type', 'invoice')
            ->whereIn('status', ['open', 'part_paid'])->whereDate('invoice_date', '<=', $period->end_date)->count();
        $items[] = ['key' => 'debtors', 'label' => 'Debtors reviewed', 'type' => 'computed',
            'status' => $openAr === 0 ? 'pass' : 'warn', 'message' => $openAr ? "{$openAr} open receivable(s)" : null];

        $openAp = FinInvoice::where('direction', 'payable')->where('doc_type', 'invoice')
            ->whereIn('status', ['open', 'part_paid'])->whereDate('invoice_date', '<=', $period->end_date)->count();
        $items[] = ['key' => 'creditors', 'label' => 'Creditors reviewed', 'type' => 'computed',
            'status' => $openAp === 0 ? 'pass' : 'warn', 'message' => $openAp ? "{$openAp} open payable(s)" : null];

        // ── manual (ticked in the wizard, stored on the period) ──
        $checked = $period->checklist ?? [];
        $labels = ['bank_reconciled' => 'Bank reconciled', 'vat_submitted' => 'VAT submitted',
            'inventory_count' => 'Inventory count', 'payroll_posted' => 'Payroll posted'];
        foreach (FinFiscalPeriod::CHECKLIST_ITEMS as $key) {
            $done = (bool) ($checked[$key]['done'] ?? false);
            $items[] = ['key' => $key, 'label' => $labels[$key], 'type' => 'manual',
                'status' => $done ? 'done' : 'todo', 'message' => $done ? ('by ' . ($checked[$key]['by'] ?? '—')) : null];
        }

        return $items;
    }

    /** Tick / untick a manual checklist item on a period. */
    public function toggleChecklistItem(FinFiscalPeriod $period, string $item, bool $done): FinFiscalPeriod
    {
        if (! in_array($item, FinFiscalPeriod::CHECKLIST_ITEMS, true)) {
            throw new PostingException('Unknown checklist item.');
        }
        $checklist = $period->checklist ?? [];
        $checklist[$item] = $done
            ? ['done' => true, 'by' => optional(Auth::user())->email ?? 'system', 'at' => now()->toDateTimeString()]
            : ['done' => false];
        $period->update(['checklist' => $checklist]);

        return $period;
    }

    /** Audit trail for a period (opened / closed / reopened …), newest first. */
    public function history(FinFiscalPeriod $period): array
    {
        return Activity::where('subject_type', $period->getMorphClass())
            ->where('subject_id', $period->id)->latest()->limit(100)->get()
            ->map(fn ($a) => [
                'description' => $a->description,
                'from' => $a->properties['from'] ?? null,
                'to' => $a->properties['to'] ?? null,
                'reason' => $a->properties['reason'] ?? null,
                'by' => optional($a->causer)->email ?? 'system',
                'at' => $a->created_at?->toDateTimeString(),
            ])->all();
    }

    /** open → soft_close. Blocks new postings; corrections still allowed. */
    public function softClose(FinFiscalPeriod $period, ?string $reason = null): FinFiscalPeriod
    {
        return $this->transition($period, ['open'], 'soft_close', $reason);
    }

    /** open|soft_close → hard_close. Blocked if there are unposted drafts. */
    public function hardClose(FinFiscalPeriod $period, ?string $reason = null): FinFiscalPeriod
    {
        $errors = collect($this->preCloseChecks($period))->where('level', 'error');
        if ($errors->isNotEmpty()) {
            throw new PostingException($errors->first()['message']);
        }

        return $this->transition($period, ['open', 'soft_close'], 'hard_close', $reason);
    }

    /** hard_close → locked. Terminal — nothing may post or reverse after this. */
    public function lock(FinFiscalPeriod $period, ?string $reason = null): FinFiscalPeriod
    {
        return $this->transition($period, ['hard_close'], 'locked', $reason);
    }

    /** soft_close|hard_close → open. Audited; a locked period cannot be reopened. */
    public function reopen(FinFiscalPeriod $period, string $reason): FinFiscalPeriod
    {
        return $this->transition($period, ['soft_close', 'hard_close'], 'open', $reason);
    }

    /** locked → hard_close. The only way out of a locked period; SUPERUSER-only (gated in controller). */
    public function unlock(FinFiscalPeriod $period, string $reason): FinFiscalPeriod
    {
        return $this->transition($period, ['locked'], 'hard_close', $reason);
    }

    private function transition(FinFiscalPeriod $period, array $allowedFrom, string $to, ?string $reason): FinFiscalPeriod
    {
        if (! in_array($period->status, $allowedFrom, true)) {
            throw new PostingException("Cannot move period from {$period->status} to {$to}.");
        }

        $from = $period->status;
        $period->update(['status' => $to]);

        // Audit the change (best-effort — never block the transition on logging).
        try {
            activity('finance')
                ->performedOn($period)
                ->causedBy(Auth::user())
                ->withProperties(['from' => $from, 'to' => $to, 'reason' => $reason])
                ->log("Fiscal period {$period->fiscal_year}/{$period->period_no}: {$from} → {$to}");
        } catch (\Throwable $e) {
            Log::info('finance: period transition', ['period' => $period->id, 'from' => $from, 'to' => $to, 'reason' => $reason]);
        }

        return $period;
    }
}
