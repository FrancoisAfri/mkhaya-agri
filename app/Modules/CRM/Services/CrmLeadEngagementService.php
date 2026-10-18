<?php

namespace App\Modules\CRM\Services;

use App\Modules\CRM\Models\CrmActivity;
use App\Modules\CRM\Models\CrmEmailTemplate;
use App\Modules\CRM\Models\CrmLead;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Automatically reaches out to a freshly-captured lead — an email built from the
 * "new_lead" template and, when a phone number is present, a WhatsApp message.
 * Best-effort: failures are logged and never block lead capture.
 */
class CrmLeadEngagementService
{
    public function __construct(
        private CrmMailService $mail,
        private CrmWhatsAppService $whatsapp,
    ) {}

    public function engageNewLead(CrmLead $lead): void
    {
        $data = $this->mergeData($lead);

        $this->sendEmail($lead, $data);
        $this->sendWhatsApp($lead, $data);
    }

    private function sendEmail(CrmLead $lead, array $data): void
    {
        if (! $lead->email) {
            return;
        }

        $template = CrmEmailTemplate::byKey('new_lead');
        if (! $template) {
            return;
        }

        try {
            $rendered = $template->render($data);
            $this->mail->sendHtml($lead->email, $rendered['subject'], $rendered['body']);
            $this->logActivity($lead, 'email', 'Sent welcome email: '.$rendered['subject']);
        } catch (\Throwable $e) {
            Log::warning('New-lead email failed', ['lead' => $lead->id, 'error' => $e->getMessage()]);
        }
    }

    private function sendWhatsApp(CrmLead $lead, array $data): void
    {
        if (! $lead->phone || ! $this->whatsapp->isConfigured()) {
            return;
        }

        $body = "Hi {$data['first_name']}, thanks for contacting {$data['company_name']}! "
            . 'We’ve received your enquiry and a member of our team will be in touch shortly.';

        try {
            $result = $this->whatsapp->sendText($lead->phone, $body);
            if (($result['available'] ?? false) && ! isset($result['error'])) {
                $this->logActivity($lead, 'whatsapp', 'Sent welcome WhatsApp message');
            }
        } catch (\Throwable $e) {
            Log::warning('New-lead WhatsApp failed', ['lead' => $lead->id, 'error' => $e->getMessage()]);
        }
    }

    private function mergeData(CrmLead $lead): array
    {
        $repName = $lead->owner?->person
            ? trim($lead->owner->person->first_name.' '.$lead->owner->person->surname)
            : 'Our team';

        return [
            'first_name'   => $lead->first_name ?: 'there',
            'last_name'    => $lead->last_name ?: '',
            'full_name'    => trim($lead->first_name.' '.$lead->last_name),
            'company'      => $lead->company ?: '',
            'email'        => $lead->email ?: '',
            'phone'        => $lead->phone ?: '',
            'rep_name'     => $repName,
            'company_name' => config('app.name', 'our company'),
        ];
    }

    private function logActivity(CrmLead $lead, string $type, string $subject): void
    {
        CrmActivity::create([
            'uuid'         => (string) Str::uuid(),
            'type'         => $type,
            'subject'      => $subject,
            'description'  => 'Automated new-lead outreach.',
            'related_type' => 'lead',
            'related_id'   => $lead->id,
            'status'       => 'done',
            'completed_at' => now(),
            'owner_id'     => $lead->owner_id,
            'created_by'   => $lead->created_by,
        ]);
    }
}
