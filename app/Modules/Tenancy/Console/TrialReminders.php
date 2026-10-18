<?php

namespace App\Modules\Tenancy\Console;

use App\Core\User\Models\User;
use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\Notifications\TrialEndingSoon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Warns platform admins that a client's trial is about to run out, at each configured
 * threshold (edition.trial_alert_days, default 14/7/3/1/0 days out), plus once on the
 * day it actually lapses.
 *
 * This is the missing half of tenancy:expire-trials. That command suspends a lapsed
 * trial, and the first anyone at Mkhaya heard about it was the client phoning in
 * locked out — the decision to extend or convert was only ever made after the damage.
 *
 * Alerts are sent once per threshold: `trial_alert_stage` records the smallest
 * threshold already sent and `trial_alert_ends_at` the end date it refers to, so
 * extending a trial (a new end date) re-arms the whole ladder.
 */
class TrialReminders extends Command
{
    protected $signature = 'tenancy:trial-reminders {--dry-run : List what would be sent without sending or recording anything}';

    protected $description = 'Alert platform admins about trials that are about to end (or just have)';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        // Ascending, so `first(days <= threshold)` picks the TIGHTEST rung a company
        // qualifies for. Descending would match the widest (6 days left ≤ 14) and burn
        // the whole ladder on the first alert.
        $thresholds = collect(config('edition.trial_alert_days', [14, 7, 3, 1, 0]))
            ->map(fn ($d) => (int) $d)->filter(fn ($d) => $d >= 0)->unique()->sort()->values();

        if ($thresholds->isEmpty()) {
            $this->warn('No trial alert thresholds configured (edition.trial_alert_days) — nothing to do.');

            return self::SUCCESS;
        }

        $admins = User::withoutGlobalScopes()
            ->where(fn ($q) => $q->where('is_platform_admin', true)->orWhere('is_master_admin', true))
            ->whereNotNull('email')
            ->get();

        if ($admins->isEmpty()) {
            $this->warn('No platform admins to notify.');

            return self::SUCCESS;
        }

        $userCounts = DB::table('users')->select('company_id', DB::raw('count(*) c'))
            ->groupBy('company_id')->pluck('c', 'company_id');

        // Trials only, and only those already suspended for having lapsed — a company
        // suspended for non-payment is a billing matter, not a trial countdown.
        $companies = Company::query()
            ->whereNotNull('trial_ends_at')
            ->where(fn ($q) => $q->where('status', 'trial')
                ->orWhere(fn ($q2) => $q2->where('status', 'suspended')->where('suspended_reason', 'Trial expired')))
            ->orderBy('trial_ends_at')
            ->get();

        $sent = 0;

        foreach ($companies as $company) {
            $daysLeft = (int) now()->startOfDay()->diffInDays($company->trial_ends_at->copy()->startOfDay(), false);

            // Lapsed trials get one closing alert (stage -1), not a daily nag.
            $stage = $daysLeft < 0 ? -1 : $thresholds->first(fn ($t) => $daysLeft <= $t);

            if ($stage === null) {
                continue;   // Still further out than the widest threshold.
            }

            if ($this->alreadySent($company, $stage)) {
                continue;
            }

            $label = $daysLeft < 0 ? 'lapsed' : "{$daysLeft} day(s) left";
            $this->line(($dry ? '[dry-run] ' : '')."{$company->name} — {$label} (threshold {$stage})");

            if ($dry) {
                continue;
            }

            Notification::send($admins, new TrialEndingSoon(
                $company,
                $daysLeft,
                (int) ($userCounts[$company->id] ?? 0),
            ));

            // Recorded against the end date the alert described, so an extension re-arms it.
            $company->forceFill([
                'trial_alert_stage' => $stage,
                'trial_alert_ends_at' => $company->trial_ends_at->toDateString(),
            ])->saveQuietly();

            $sent++;
        }

        $this->info($dry
            ? 'Dry run complete — nothing sent.'
            : "Done. {$sent} trial alert(s) sent to {$admins->count()} platform admin(s).");

        return self::SUCCESS;
    }

    /**
     * True when this company has already been alerted at this threshold or a tighter one
     * for its current end date. A stale `trial_alert_ends_at` means the trial was
     * extended since, so the ladder starts again.
     */
    private function alreadySent(Company $company, int $stage): bool
    {
        if ($company->trial_alert_stage === null || $company->trial_alert_ends_at === null) {
            return false;
        }

        if (! $company->trial_alert_ends_at->isSameDay($company->trial_ends_at)) {
            return false;
        }

        // Thresholds descend (14 → 7 → … → 0 → -1 for lapsed), so an equal or tighter
        // stage on record means this rung, and everything above it, has already gone out.
        return $company->trial_alert_stage <= $stage;
    }
}
