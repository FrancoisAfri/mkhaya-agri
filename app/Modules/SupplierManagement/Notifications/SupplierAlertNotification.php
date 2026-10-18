<?php

namespace App\Modules\SupplierManagement\Notifications;

use App\Modules\SupplierManagement\Models\Supplier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * A due-diligence alert, routed to whoever the severity says should hear it.
 *
 * Mail only for high and critical. A medium-severity alert that emails four people every time a
 * registered address changes is how a mailbox rule gets written, and after that the critical one
 * lands in the same folder — so the in-system notification carries everything and mail is reserved
 * for what genuinely cannot wait for somebody to open the inbox.
 */
class SupplierAlertNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Supplier $supplier,
        public readonly string $kind,
        public readonly string $severity,
        public readonly string $summary,
        public readonly array $context = [],
    ) {}

    public function via(object $notifiable): array
    {
        return in_array($this->severity, ['high', 'critical'], true)
            ? ['database', 'mail']
            : ['database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject(__('supplier_management.alerts.subject', [
                'severity' => Str::upper($this->severity),
                'supplier' => $this->supplier->company_name,
            ]))
            ->greeting(__('supplier_management.alerts.greeting'))
            ->line($this->summary)
            ->line(__('supplier_management.alerts.context', [
                'supplier' => $this->supplier->company_name,
                'band' => $this->supplier->risk_band ?: '—',
            ]))
            ->action(__('supplier_management.alerts.action'), url("/suppliers/{$this->supplier->id}/passport"));

        return $this->severity === 'critical' ? $mail->error() : $mail;
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'supplier_due_diligence_alert',
            'kind' => $this->kind,
            'severity' => $this->severity,
            'supplier_id' => $this->supplier->id,
            'supplier' => $this->supplier->company_name,
            'title' => __('supplier_management.alerts.db_title', [
                'supplier' => $this->supplier->company_name,
            ]),
            'message' => $this->summary,
            'context' => $this->context,
            // Straight to the one screen that answers "and what should I do about it".
            'url' => "/suppliers/{$this->supplier->id}/passport",
        ];
    }
}
