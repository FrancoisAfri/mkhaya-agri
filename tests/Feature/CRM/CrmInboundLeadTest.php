<?php

namespace Tests\Feature\CRM;

use App\Core\User\Models\User;
use App\Modules\CRM\Models\CrmLead;
use App\Modules\CRM\Models\CrmMailbox;
use App\Modules\CRM\Models\CrmNotification;
use App\Modules\CRM\Services\CrmInboundLeadService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class CrmInboundLeadTest extends TestCase
{
    use DatabaseTransactions;

    private function mailbox(): CrmMailbox
    {
        $owner = User::query()->first();
        if (! $owner) $this->markTestSkipped('Need at least one user.');

        return CrmMailbox::create([
            'name' => 'Sales', 'email' => 'sales@mkhayamk.co.za',
            'host' => 'imap.test', 'port' => 993, 'encryption' => 'ssl',
            'username' => 'sales@mkhayamk.co.za', 'password' => 'secret',
            'folder' => 'INBOX', 'active' => true, 'ai_filter' => false,
            'default_owner_id' => $owner->id, 'created_by' => $owner->id,
        ]);
    }

    private function service(): CrmInboundLeadService
    {
        // AI disabled at the mailbox level → heuristics path, no HTTP calls.
        config(['ai.providers.openai.api_key' => '']);
        return app(CrmInboundLeadService::class);
    }

    public function test_legitimate_email_creates_a_lead_and_notifies_owner(): void
    {
        $mailbox = $this->mailbox();

        $result = $this->service()->process($mailbox, [
            'uid' => 1, 'from' => 'jane.doe@acme.com', 'from_name' => 'Jane Doe',
            'subject' => 'Interested in your services', 'body' => 'Please send a quote for 50 units.', 'date' => null,
        ]);

        $this->assertSame('lead_created', $result['action']);

        $lead = CrmLead::find($result['lead_id']);
        $this->assertSame('jane.doe@acme.com', $lead->email);
        $this->assertSame('Jane', $lead->first_name);
        $this->assertStringContainsString('mailbox:sales@mkhayamk.co.za', $lead->source);

        $this->assertTrue(
            CrmNotification::where('user_id', $mailbox->default_owner_id)->where('entity_id', $lead->id)->exists()
        );
    }

    public function test_spam_email_is_filtered_out(): void
    {
        $result = $this->service()->process($this->mailbox(), [
            'uid' => 2, 'from' => 'scammer@spam.com', 'from_name' => '',
            'subject' => 'You have won the lottery!', 'body' => 'Claim your bitcoin doubler prize now.', 'date' => null,
        ]);

        $this->assertSame('spam', $result['action']);
    }

    public function test_automated_sender_is_skipped(): void
    {
        $result = $this->service()->process($this->mailbox(), [
            'uid' => 3, 'from' => 'no-reply@notifications.com', 'from_name' => 'System',
            'subject' => 'Receipt', 'body' => 'Your order shipped.', 'date' => null,
        ]);

        $this->assertSame('skipped', $result['action']);
    }

    public function test_duplicate_open_lead_is_not_recreated(): void
    {
        $mailbox = $this->mailbox();
        $email = [
            'uid' => 4, 'from' => 'repeat@acme.com', 'from_name' => 'Repeat Buyer',
            'subject' => 'Quote please', 'body' => 'Need pricing.', 'date' => null,
        ];

        $first  = $this->service()->process($mailbox, $email);
        $second = $this->service()->process($mailbox, $email);

        $this->assertSame('lead_created', $first['action']);
        $this->assertSame('duplicate', $second['action']);
        $this->assertSame($first['lead_id'], $second['lead_id']);
    }
}
