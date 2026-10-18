<?php

namespace App\Modules\SupplierManagement\Notifications;

use App\Modules\SupplierManagement\Models\Supplier;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Tell a supplier what was decided about them.
 *
 * Three outcomes, one message, because they are the same event from the supplier's side: someone
 * looked at your application and it either passed, failed, or needs more from you. Splitting them
 * into three classes would triple the translation surface for no difference the recipient can see.
 *
 * A rejection carries its reason. A supplier told only "declined" writes back to ask why, and the
 * buyer answers the same question by hand every time.
 */
class SupplierDecisionNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /** @param  'approved'|'refused'|'information_requested'  $decision */
    public function __construct(
        public readonly Supplier $supplier,
        public readonly string $decision,
        public readonly ?string $comment = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $key = "supplier_management.decisions.{$this->decision}";

        $message = (new MailMessage)
            ->subject(__("{$key}.mail_subject", ['company' => config('app.name')]))
            ->greeting(__('supplier_management.decisions.greeting', [
                'name' => $this->supplier->company_name,
            ]))
            ->line(__("{$key}.mail_body", ['company' => config('app.name')]));

        if (filled($this->comment)) {
            $message->line(__('supplier_management.decisions.comment_label'))
                ->line($this->comment);
        }

        // Only an outcome the supplier can act on gets a link. Sending someone to a portal to read
        // that they were declined is worse than the plain sentence.
        if ($this->decision !== 'refused' && $this->supplier->portal_token) {
            $message->action(
                __('supplier_management.decisions.mail_action'),
                route('supplier.portal.show', $this->supplier->portal_token),
            );
        }

        return $message->line(__('supplier_management.decisions.mail_closing'));
    }
}
