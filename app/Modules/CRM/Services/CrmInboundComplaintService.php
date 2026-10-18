<?php

namespace App\Modules\CRM\Services;

use App\Modules\CRM\Models\CrmMailbox;
use Illuminate\Support\Str;

/**
 * Turns an email captured from a complaint-capture mailbox into a logged
 * complaint — the full intake (AI grading, employee identification, routing,
 * acknowledgement) runs inside CrmComplaintService::create.
 */
class CrmInboundComplaintService
{
    public function __construct(private readonly CrmComplaintService $complaints) {}

    /**
     * @param  array{uid:int, from:string, from_name:string, subject:string, body:string, date:?string}  $email
     * @return array{action:string, reason:string, complaint_id?:int}
     */
    public function process(CrmMailbox $mailbox, array $email): array
    {
        $from = trim(mb_strtolower($email['from'] ?? ''));
        $body = trim($email['body'] ?? '');

        if ($from === '' || ! filter_var($from, FILTER_VALIDATE_EMAIL)) {
            return ['action' => 'skipped', 'reason' => 'No valid sender address.'];
        }
        if (Str::contains($from, ['mailer-daemon', 'no-reply', 'noreply', 'postmaster', 'do-not-reply'])) {
            return ['action' => 'skipped', 'reason' => 'Automated / system sender.'];
        }
        if (mb_strlen($body) < 5) {
            return ['action' => 'skipped', 'reason' => 'Empty message.'];
        }

        $complaint = $this->complaints->create([
            'subject'        => $email['subject'] ?: 'Customer feedback',
            'body'           => $body,
            'customer_name'  => trim($email['from_name'] ?? '') ?: Str::before($from, '@'),
            'customer_email' => $from,
        ], 'mailbox');

        return ['action' => 'complaint_created', 'reason' => 'Logged '.$complaint->reference, 'complaint_id' => $complaint->id];
    }
}
