<?php

namespace App\Console\Commands\PerformanceAppraisal;

use App\Core\User\Models\User;
use App\Modules\PerformanceAppraisal\Models\PaCheckin;
use App\Modules\PerformanceAppraisal\Models\PaCoachingSession;
use App\Modules\PerformanceAppraisal\Notifications\PaCheckinNotification;
use App\Modules\PerformanceAppraisal\Notifications\PaCoachingSessionNotification;
use App\Modules\Tenancy\Services\TenantContext;
use App\Support\Console\RunsPerTenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SendPaRemindersCommand extends Command
{
    use RunsPerTenant;

    protected $signature   = 'pa:send-reminders';
    protected $description = 'Send day-before and day-of reminders for PA check-ins and coaching sessions.';

    public function handle(): int
    {
        $this->eachTenant(app(TenantContext::class), function () {
            $this->sendCheckinReminders();
            $this->sendCoachingReminders();
        });

        return Command::SUCCESS;
    }

    private function sendCheckinReminders(): void
    {
        $tomorrow = now()->addDay()->toDateString();
        $today    = now()->toDateString();

        // Day-before reminders
        PaCheckin::where('status', 'scheduled')
            ->whereDate('scheduled_at', $tomorrow)
            ->with('employee')
            ->get()
            ->each(function (PaCheckin $checkin) {
                $this->notifyUser($checkin->employee_id, new PaCheckinNotification($checkin, 'reminder_day_before'));
                $this->line("  check-in #{$checkin->id} — day-before reminder → user {$checkin->employee_id}");
            });

        // Day-of reminders
        PaCheckin::where('status', 'scheduled')
            ->whereDate('scheduled_at', $today)
            ->with('employee')
            ->get()
            ->each(function (PaCheckin $checkin) {
                $this->notifyUser($checkin->employee_id, new PaCheckinNotification($checkin, 'reminder_day_of'));
                $this->line("  check-in #{$checkin->id} — day-of reminder → user {$checkin->employee_id}");
            });
    }

    private function sendCoachingReminders(): void
    {
        $tomorrow = now()->addDay()->toDateString();
        $today    = now()->toDateString();

        // Day-before reminders
        PaCoachingSession::where('status', 'scheduled')
            ->whereDate('session_date', $tomorrow)
            ->get()
            ->each(function (PaCoachingSession $session) {
                $this->notifyUser($session->employee_id, new PaCoachingSessionNotification($session, 'reminder_day_before', 'employee'));
                $this->line("  coaching #{$session->id} — day-before reminder → user {$session->employee_id}");
            });

        // Day-of reminders
        PaCoachingSession::where('status', 'scheduled')
            ->whereDate('session_date', $today)
            ->get()
            ->each(function (PaCoachingSession $session) {
                $this->notifyUser($session->employee_id, new PaCoachingSessionNotification($session, 'reminder_day_of', 'employee'));
                $this->line("  coaching #{$session->id} — day-of reminder → user {$session->employee_id}");
            });
    }

    private function notifyUser(int $userId, mixed $notification): void
    {
        try {
            $user = User::find($userId);
            $user?->notify($notification);
        } catch (\Throwable $e) {
            Log::warning('PA reminder notification failed.', ['user_id' => $userId, 'error' => $e->getMessage()]);
        }
    }
}
