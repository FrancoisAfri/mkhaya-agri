<?php

namespace Database\Seeders;

use App\Modules\CRM\Models\CrmEmailTemplate;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Default system email templates the CRM sends automatically. Keyed templates
 * are upserted (content refreshed) without duplicating.
 */
class CrmEmailTemplateSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->templates() as $tpl) {
            CrmEmailTemplate::updateOrCreate(
                ['key' => $tpl['key']],
                array_merge($tpl, ['uuid' => (string) Str::uuid()]),
            );
        }
    }

    private function templates(): array
    {
        return [
            [
                'key'      => 'new_lead',
                'name'     => 'New lead — welcome',
                'category' => 'lead',
                'subject'  => 'Thanks for reaching out to {{company_name}}',
                'active'   => true,
                'body'     => <<<'HTML'
<p>Hi {{first_name}},</p>
<p>Thank you for contacting <strong>{{company_name}}</strong> — we’ve received your enquiry and a member of our team will be in touch shortly.</p>
<p>If it’s urgent, simply reply to this email and we’ll prioritise it.</p>
<p>Warm regards,<br/>{{rep_name}}<br/>{{company_name}}</p>
HTML,
            ],
            [
                'key'      => 'lead_follow_up',
                'name'     => 'Lead — follow up',
                'category' => 'lead',
                'subject'  => 'Following up on your interest, {{first_name}}',
                'active'   => true,
                'body'     => <<<'HTML'
<p>Hi {{first_name}},</p>
<p>I wanted to follow up on your recent enquiry with <strong>{{company_name}}</strong>. I'd love to learn a little more about what you're looking for so we can point you to the right solution.</p>
<p>Do you have 15 minutes this week for a quick call? Just reply with a time that suits you.</p>
<p>Best,<br/>{{rep_name}}<br/>{{company_name}}</p>
HTML,
            ],
            [
                'key'      => 'lead_nurture',
                'name'     => 'Lead — nurture / stay in touch',
                'category' => 'lead',
                'subject'  => 'A few ways {{company_name}} can help',
                'active'   => true,
                'body'     => <<<'HTML'
<p>Hi {{first_name}},</p>
<p>Thanks again for your interest. While you consider your options, here are a few ways teams like yours get value from <strong>{{company_name}}</strong>:</p>
<ul>
  <li>Save time on the things you do every day</li>
  <li>Get a clear view of what matters most</li>
  <li>Support from a team that actually picks up the phone</li>
</ul>
<p>Whenever you're ready, just reply and we'll take it from there.</p>
<p>Warm regards,<br/>{{rep_name}}</p>
HTML,
            ],
            [
                'key'      => 'lead_meeting_request',
                'name'     => 'Lead — request a meeting',
                'category' => 'lead',
                'subject'  => 'Quick chat about {{company}}?',
                'active'   => true,
                'body'     => <<<'HTML'
<p>Hi {{first_name}},</p>
<p>I think {{company_name}} could genuinely help {{company}}. Could we set up a short call to explore whether it's a fit?</p>
<p>Reply with a couple of times that work and I'll send an invite.</p>
<p>Thanks,<br/>{{rep_name}}<br/>{{company_name}}</p>
HTML,
            ],
            [
                'key'      => 'deal_proposal',
                'name'     => 'Deal — proposal sent',
                'category' => 'deal',
                'subject'  => 'Your proposal from {{company_name}}',
                'active'   => true,
                'body'     => <<<'HTML'
<p>Hi {{first_name}},</p>
<p>Thank you for the opportunity. Please find our proposal attached for your review.</p>
<p>I've tailored it to what we discussed. I'm happy to walk you through it or adjust anything that doesn't quite fit — just let me know.</p>
<p>Looking forward to your thoughts,<br/>{{rep_name}}<br/>{{company_name}}</p>
HTML,
            ],
            [
                'key'      => 'deal_follow_up',
                'name'     => 'Deal — follow up on proposal',
                'category' => 'deal',
                'subject'  => 'Any questions on the proposal, {{first_name}}?',
                'active'   => true,
                'body'     => <<<'HTML'
<p>Hi {{first_name}},</p>
<p>I wanted to check in on the proposal I sent through. Do you have any questions, or is there anything you'd like me to clarify?</p>
<p>I'm keen to help you move this forward — happy to jump on a quick call if that's easier.</p>
<p>Best,<br/>{{rep_name}}</p>
HTML,
            ],
            [
                'key'      => 'deal_won',
                'name'     => 'Deal — won / welcome aboard',
                'category' => 'deal',
                'subject'  => 'Welcome aboard — thank you from {{company_name}}',
                'active'   => true,
                'body'     => <<<'HTML'
<p>Hi {{first_name}},</p>
<p>Wonderful news — we're delighted to be working with you! Thank you for choosing <strong>{{company_name}}</strong>.</p>
<p>Your dedicated contact is {{rep_name}}, who will be in touch shortly to get everything set up. In the meantime, reply to this email any time with questions.</p>
<p>Here's to a great partnership,<br/>{{rep_name}}<br/>{{company_name}}</p>
HTML,
            ],
            [
                'key'      => 'deal_lost_followup',
                'name'     => 'Deal — staying in touch after a no',
                'category' => 'deal',
                'subject'  => 'Thanks for considering {{company_name}}',
                'active'   => true,
                'body'     => <<<'HTML'
<p>Hi {{first_name}},</p>
<p>Thank you for taking the time to consider {{company_name}}. I completely understand the timing wasn't right.</p>
<p>I'd love to stay in touch in case things change down the line. If anything comes up, you know where to find me.</p>
<p>All the best,<br/>{{rep_name}}</p>
HTML,
            ],
            [
                'key'      => 'complaint_ack',
                'name'     => 'Complaint — acknowledgement',
                'category' => 'complaint',
                'subject'  => 'We’ve received your complaint — reference logged',
                'active'   => true,
                'body'     => <<<'HTML'
<p>Hi {{first_name}},</p>
<p>We’re sorry to hear about your experience. Your complaint has been logged and assigned to the relevant team for review.</p>
<p>We take this seriously and will respond within our service commitment. You’ll hear from us soon.</p>
<p>Kind regards,<br/>{{company_name}} Customer Care</p>
HTML,
            ],
        ];
    }
}
