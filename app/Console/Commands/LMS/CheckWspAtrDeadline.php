<?php

namespace App\Console\Commands\LMS;

use App\Core\User\Models\User;
use App\Modules\HR\LMS\Models\Qcto\LmsAtrReport;
use App\Modules\HR\LMS\Models\Qcto\LmsWspPlan;
use App\Modules\HR\LMS\Notifications\LmsWspAtrDeadlineNotification;
use App\Modules\Tenancy\Services\TenantContext;
use App\Support\Console\RunsPerTenant;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class CheckWspAtrDeadline extends Command
{
    use RunsPerTenant;

    protected $signature   = 'lms:check-wsp-atr-deadline';
    protected $description = 'Alert SDFs and LMS admins when WSP/ATR 30 April submission deadlines are approaching';

    private const WINDOWS = [60, 30, 14, 7, 1];

    public function handle(): int
    {
        $today    = now()->startOfDay();
        $deadline = Carbon::createFromDate($today->year, 4, 30)->startOfDay();

        // If today is past 30 April, target next year's deadline
        if ($today->isAfter($deadline)) {
            $deadline = Carbon::createFromDate($today->year + 1, 4, 30)->startOfDay();
        }

        $daysLeft = (int) $today->diffInDays($deadline, false);

        if (!in_array($daysLeft, self::WINDOWS)) {
            $this->info("30 April deadline is {$daysLeft} days away — outside alert windows. Nothing to send.");
            return self::SUCCESS;
        }

        // Identify un-submitted WSP plans for the current or upcoming plan year
        $planYear = $deadline->year;

        $this->eachTenant(app(TenantContext::class), function () use ($daysLeft, $deadline, $planYear) {
        $pendingWsps   = LmsWspPlan::whereYear('due_date', $planYear)
            ->whereNotIn('status', ['submitted', 'approved'])
            ->with(['sdf.user', 'seta'])
            ->get();

        $pendingAtrs   = LmsAtrReport::whereYear('period_end', $planYear - 1)
            ->whereNotIn('status', ['submitted', 'accepted'])
            ->with(['sdf.user', 'seta'])
            ->get();

        if ($pendingWsps->isEmpty() && $pendingAtrs->isEmpty()) {
            $this->info("All WSP/ATR submissions for {$planYear} are already submitted or approved.");
            return;
        }

        $recipients = collect();

        // SDF users linked to pending plans
        foreach ($pendingWsps as $wsp) {
            if ($wsp->sdf?->user) {
                $recipients->push($wsp->sdf->user);
            }
        }
        foreach ($pendingAtrs as $atr) {
            if ($atr->sdf?->user) {
                $recipients->push($atr->sdf->user);
            }
        }

        // All LMS admin users
        $admins = User::whereIn('role', ['super admin', 'administrator', 'hr', 'lms_admin'])->get();
        $recipients = $recipients->merge($admins)->unique('id');

        $summary = [
            'days_left'    => $daysLeft,
            'deadline'     => $deadline->toDateString(),
            'plan_year'    => $planYear,
            'pending_wsps' => $pendingWsps->count(),
            'pending_atrs' => $pendingAtrs->count(),
        ];

        $this->info("Sending 30 April deadline alert ({$daysLeft} days left) to {$recipients->count()} recipient(s).");

        foreach ($recipients as $user) {
            try {
                $user->notify(new LmsWspAtrDeadlineNotification($summary));
            } catch (\Throwable $e) {
                Log::error("WSP/ATR deadline notification failed for user #{$user->id}: " . $e->getMessage());
            }
        }

        activity()
            ->withProperties($summary)
            ->log("WSP/ATR 30 April deadline alert sent: {$daysLeft} days remaining, {$pendingWsps->count()} WSP(s) and {$pendingAtrs->count()} ATR(s) pending.");

        $this->table(
            ['Metric', 'Value'],
            [
                ['Deadline',      $deadline->toDateString()],
                ['Days left',     $daysLeft],
                ['Pending WSPs',  $pendingWsps->count()],
                ['Pending ATRs',  $pendingAtrs->count()],
                ['Notified',      $recipients->count()],
            ]
        );
        });

        return self::SUCCESS;
    }
}
