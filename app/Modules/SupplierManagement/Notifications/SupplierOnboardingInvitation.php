<?php

namespace App\Modules\SupplierManagement\Notifications;

use App\Modules\SupplierManagement\Models\SupplierDocumentType;
use App\Modules\SupplierManagement\Models\SupplierOnboarding;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * The invitation that makes onboarding a process rather than a database row.
 *
 * The token and its 24-hour window were being generated and then never sent anywhere, so a supplier
 * was "invited" in a sense nobody outside the building could observe. This is the message that was
 * missing, and it carries the two things a supplier needs to act: the link, and the list of
 * documents they will be asked for before they start gathering them.
 */
class SupplierOnboardingInvitation extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * The compulsory document names, resolved now rather than when the mail is sent.
     *
     * This matters because the notification is queued, and a queued job runs with no tenant bound —
     * the `BelongsToCompany` scope is inactive there, so a `SupplierDocumentType` query made inside
     * `toMail()` would return every tenant's document types and list them in one company's
     * invitation. Capturing the list here, inside the request that still knows which company it is,
     * keeps the tenancy boundary where it belongs.
     *
     * @var list<string>
     */
    public readonly array $requiredDocuments;

    public function __construct(public readonly SupplierOnboarding $onboarding)
    {
        $this->requiredDocuments = SupplierDocumentType::query()
            ->where('active', true)
            ->where('compulsory', true)
            ->orderBy('document_type_name')
            ->pluck('document_type_name')
            ->all();
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject(__('supplier_management.onboarding.mail_subject', [
                'company' => config('app.name'),
            ]))
            ->greeting(__('supplier_management.onboarding.mail_greeting', [
                'name' => $this->onboarding->contact_person ?: $this->onboarding->company_name,
            ]))
            ->line(__('supplier_management.onboarding.mail_intro', [
                'company' => config('app.name'),
            ]))
            // The token belongs in the link. Pointing at the buyer's own onboarding screen — which
            // is behind auth — gave the supplier a button they could not open and never used the
            // credential the invitation exists to deliver.
            ->action(
                __('supplier_management.onboarding.mail_action'),
                route('supplier.registration.show', $this->onboarding->token),
            )
            ->line(__('supplier_management.onboarding.mail_expiry', [
                'date' => $this->onboarding->expires_at?->format('d M Y, H:i') ?? '—',
            ]));

        foreach ($this->requiredDocuments as $document) {
            $message->line('• '.$document);
        }

        return $message->line(__('supplier_management.onboarding.mail_closing'));
    }

}
