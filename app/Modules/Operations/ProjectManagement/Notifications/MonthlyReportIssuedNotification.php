<?php

namespace App\Modules\Operations\ProjectManagement\Notifications;

use App\Modules\Operations\ProjectManagement\Models\ReportingPeriod;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The month's Project & Financial Report has been issued.
 *
 * Email and in-app, and nothing louder: this is a management report discussed at a meeting, not an
 * incident. A text message for a monthly report is how people stop reading text messages.
 *
 * The figures are in the mail body rather than only behind a link, because most of the people on
 * this list are executives who want the position rather than the spreadsheet — and a notification
 * that says only "a report is ready" makes everybody open the system to learn nothing.
 */
class MonthlyReportIssuedNotification extends Notification
{
    /** @param array<string, mixed> $summary */
    public function __construct(
        public readonly ReportingPeriod $period,
        public readonly array $summary,
        public readonly string $url,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject($this->period->report_name)
            ->greeting($this->period->label() . ' report')
            ->line('The Monthly Project & Financial Report for ' . $this->period->label() . ' has been issued.');

        if ($this->period->meeting_date) {
            $mail->line('It will be discussed on ' . $this->period->meeting_date->format('d F Y') . '.');
        }

        $mail->line('Projects on the report: ' . ($this->summary['projects'] ?? 0));

        if (($this->summary['average_progress'] ?? null) !== null) {
            $mail->line('Average progress: ' . $this->summary['average_progress'] . '%');
        }

        // Only where the figures were available to compute — the summary is withheld entirely from
        // anyone without cost visibility, and the notification must not become a way around that.
        if (isset($this->summary['outstanding'])) {
            $mail->line('Total outstanding: ' . $this->summary['outstanding']);
        }

        if ($this->period->general_comment) {
            $mail->line($this->period->general_comment);
        }

        return $mail
            ->action('Open the report', $this->url)
            ->line('Issued from Mkhaya Agri · Project Management.');
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type'    => 'pm.monthly_report.issued',
            'title'   => $this->period->report_name,
            'message' => 'The ' . $this->period->label() . ' Project & Financial Report has been issued.',
            'url'     => $this->url,
            'period'  => $this->period->uuid,
        ];
    }
}
