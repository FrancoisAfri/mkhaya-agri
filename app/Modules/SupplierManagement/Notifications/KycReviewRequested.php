<?php

namespace App\Modules\SupplierManagement\Notifications;

use App\Modules\SupplierManagement\Models\SupplierKycApproval;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * A supplier is waiting on a KYC decision, and someone other than the requester has to make it.
 *
 * Sent to the tenant's approvers rather than to the person who raised the supplier — that separation
 * is the control, and a notification addressed back to the requester would quietly undo it.
 */
class KycReviewRequested extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly SupplierKycApproval $approval) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('supplier_management.kyc.mail_subject', [
                'supplier' => $this->approval->supplier_name ?: $this->approval->supplier?->company_name,
            ]))
            ->greeting(__('supplier_management.kyc.mail_greeting'))
            ->line(__('supplier_management.kyc.mail_body', [
                'supplier' => $this->approval->supplier_name ?: $this->approval->supplier?->company_name,
            ]))
            ->action(__('supplier_management.kyc.mail_action'), route('suppliers.kyc.index'));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'        => 'supplier_kyc_review',
            'supplier_id' => $this->approval->supplier_id,
            'approval_id' => $this->approval->id,
            'message'     => __('supplier_management.kyc.mail_body', [
                'supplier' => $this->approval->supplier_name ?: $this->approval->supplier?->company_name,
            ]),
            'url'         => '/suppliers/kyc',
        ];
    }
}
