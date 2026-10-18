<?php

namespace App\Modules\HR\Recruitment\Notifications;

use App\Modules\HR\Recruitment\Models\Requisition;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class RequisitionApprovedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly Requisition $requisition)
    {
        // Set via Queueable::onQueue() rather than redeclaring the trait's
        // untyped $queue property (which triggers a fatal composition error).
        $this->onQueue('notifications');
    }

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'type'             => 'requisition_approved',
            'requisition_id'   => $this->requisition->id,
            'requisition_uuid' => $this->requisition->uuid,
            'title'            => $this->requisition->title,
            'approved_at'      => $this->requisition->approved_at?->toIso8601String(),
            'message'          => "Your requisition \"{$this->requisition->title}\" has been approved.",
            'link'             => "/recruitment/requisitions/{$this->requisition->uuid}",
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Requisition Approved: {$this->requisition->title}")
            ->greeting("Hello {$notifiable->name},")
            ->line("Your requisition **\"{$this->requisition->title}\"** has been approved.")
            ->line("You can now proceed with the recruitment process.")
            ->action('View Requisition', url("/recruitment/requisitions/{$this->requisition->uuid}"))
            ->line('Thank you for using MkhayaHub HR.');
    }
}
