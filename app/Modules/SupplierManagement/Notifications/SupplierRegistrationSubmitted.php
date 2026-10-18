<?php

namespace App\Modules\SupplierManagement\Notifications;

use App\Modules\SupplierManagement\Models\Supplier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tell the buyer that an invited company has completed its registration.
 *
 * Without this the invitation is a one-way message: the supplier fills the form in, the record
 * appears in a list nobody is watching, and it sits pending until somebody happens to look. The
 * person who issued the invitation is the one waiting for it, so they are the one told.
 *
 * The supplier id is carried rather than the model so the queued job stays small and cannot act on a
 * stale copy of a record the buyer may have edited in the meantime.
 */
class SupplierRegistrationSubmitted extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $supplierId,
        public readonly string $companyName,
    ) {}

    public static function for(Supplier $supplier): self
    {
        return new self((int) $supplier->id, (string) $supplier->company_name);
    }

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('supplier_management.registration.mail_subject', ['supplier' => $this->companyName]))
            ->greeting(__('supplier_management.kyc.mail_greeting'))
            ->line(__('supplier_management.registration.mail_body', ['supplier' => $this->companyName]))
            ->action(
                __('supplier_management.registration.mail_action'),
                url("/suppliers/{$this->supplierId}"),
            );
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'        => 'supplier_registration_submitted',
            'supplier_id' => $this->supplierId,
            'message'     => __('supplier_management.registration.mail_body', ['supplier' => $this->companyName]),
            'url'         => "/suppliers/{$this->supplierId}",
        ];
    }
}
