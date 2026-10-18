<?php

namespace App\Modules\HR\Payroll\Notifications;

use App\Modules\HR\Payroll\Models\EmploymentMilestone;
use App\Modules\HR\Payroll\Models\EtiClaim;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "Review this employee before the next run."
 *
 * States the position rather than describing it — employee, number, start date, how long they have
 * been there, why they are being raised, and where the incentive stands. An administrator reading it
 * on a phone at seven in the morning should not have to open the system to know whether it matters.
 */
class EmploymentMilestoneReached extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly EmploymentMilestone $milestone,
        private readonly bool $withEmail = false,
    ) {
    }

    public function via(object $notifiable): array
    {
        // In-app always: the notification centre is where these are worked through. Email only when
        // the rule asks for it, because a payroll of four hundred people crosses a milestone most
        // weeks and a mailbox full of them is a mailbox nobody reads.
        return $this->withEmail ? ['database', 'mail'] : ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $m = $this->milestone;

        return [
            'type'            => 'employment_milestone',
            'title'           => $this->title(),
            'message'         => $m->reason,
            'milestone_uuid'  => $m->uuid,
            'employee_name'   => $m->employee_name,
            'employee_number' => $m->employee_number,
            'start_date'      => $m->start_date?->toDateString(),
            'months'          => $m->months,
            'eti_tracked'     => $m->eti_tracked,
            'eti_claimed'     => $m->eti_months_claimed,
            'eti_remaining'   => $m->eti_months_remaining,
            'url'             => route('payroll.milestones.index'),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $m = $this->milestone;

        $mail = (new MailMessage)
            ->subject($this->title())
            ->line("**Employee:** {$m->employee_name} ({$m->employee_number})")
            ->line('**Employment start date:** ' . ($m->start_date?->format('d F Y') ?? 'not recorded'))
            ->line("**Employment duration:** {$m->months} months")
            ->line("**Reason:** {$m->reason}");

        if ($m->eti_tracked) {
            $mail->line(sprintf(
                '**Incentive period used:** %d of %d months (%d remaining)',
                (int) $m->eti_months_claimed,
                EtiClaim::MAX_MONTHS,
                (int) $m->eti_months_remaining,
            ));
        }

        return $mail
            ->action('Review before processing payroll', route('payroll.milestones.index'))
            ->line('This notice was raised once and will not be repeated.');
    }

    private function title(): string
    {
        return $this->milestone->eti_tracked
            ? 'ETI review required'
            : 'Employment milestone reached';
    }
}
