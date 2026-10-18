<?php

namespace App\Modules\HR\LMS\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class LmsWspAtrDeadlineNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(protected array $summary) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $days      = $this->summary['days_left'];
        $deadline  = $this->summary['deadline'];
        $wsps      = $this->summary['pending_wsps'];
        $atrs      = $this->summary['pending_atrs'];
        $planYear  = $this->summary['plan_year'];

        $urgency = match(true) {
            $days <= 1  => '⚠️ FINAL NOTICE',
            $days <= 7  => '⚠️ URGENT',
            $days <= 14 => 'ACTION REQUIRED',
            default     => 'REMINDER',
        };

        $mail = (new MailMessage)
            ->subject("{$urgency}: WSP/ATR {$planYear} deadline — {$days} day(s) remaining (30 April {$planYear})")
            ->greeting("Hello {$notifiable->name},")
            ->line("The annual SETA **Workplace Skills Plan (WSP) and Annual Training Report (ATR)** submission deadline is **30 April {$planYear}** — {$days} day(s) from today.");

        if ($wsps > 0) {
            $mail->line("**{$wsps} WSP plan(s)** have not yet been submitted.");
        }
        if ($atrs > 0) {
            $mail->line("**{$atrs} ATR report(s)** have not yet been submitted.");
        }

        $mail->line('Missing the 30 April deadline forfeits your organisation\'s mandatory skills levy grant (20% of the 1% payroll levy).')
            ->action('Open LMS — WSP/ATR', url(route('lms.admin.qcto.wsp-atr')))
            ->line('Contact your Skills Development Facilitator (SDF) if you need assistance completing the submission.');

        return $mail;
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'         => 'wsp_atr_deadline',
            'days_left'    => $this->summary['days_left'],
            'deadline'     => $this->summary['deadline'],
            'plan_year'    => $this->summary['plan_year'],
            'pending_wsps' => $this->summary['pending_wsps'],
            'pending_atrs' => $this->summary['pending_atrs'],
            'message'      => "WSP/ATR 30 April deadline: {$this->summary['days_left']} day(s) remaining. {$this->summary['pending_wsps']} WSP(s) and {$this->summary['pending_atrs']} ATR(s) still pending.",
        ];
    }
}
