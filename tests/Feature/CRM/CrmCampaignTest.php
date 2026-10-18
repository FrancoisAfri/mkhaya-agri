<?php

namespace Tests\Feature\CRM;

use App\Modules\CRM\Jobs\SendCampaignJob;
use App\Modules\CRM\Models\CrmCampaign;
use App\Modules\CRM\Models\CrmCampaignRecipient;
use App\Modules\CRM\Services\CrmWhatsAppService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class CrmCampaignTest extends TestCase
{
    use DatabaseTransactions;

    private function emailCampaign(string $html = '<html><body><a href="https://example.com">Hi</a></body></html>'): CrmCampaign
    {
        return CrmCampaign::create([
            'uuid' => Str::uuid(), 'name' => 'Promo', 'channel' => 'email',
            'subject' => 'Hello', 'content' => $html, 'status' => 'draft', 'total_recipients' => 1,
        ]);
    }

    public function test_email_send_job_delivers_and_marks_recipient(): void
    {
        Mail::fake();
        $campaign = $this->emailCampaign();
        $r = CrmCampaignRecipient::create([
            'campaign_id' => $campaign->id, 'email' => 'lead@x.test', 'token' => Str::random(40), 'status' => 'pending',
        ]);

        SendCampaignJob::dispatchSync($campaign->id);

        // Faked mail doesn't throw, so the recipient is marked sent.
        $this->assertSame('sent', $r->fresh()->status);
        $this->assertSame('sent', $campaign->fresh()->status);
    }

    public function test_open_pixel_marks_opened_and_increments_campaign(): void
    {
        $campaign = $this->emailCampaign();
        $r = CrmCampaignRecipient::create([
            'campaign_id' => $campaign->id, 'email' => 'a@x.test', 'token' => Str::random(40), 'status' => 'sent',
        ]);

        $res = $this->get(route('crm.track.open', $r->token));
        $res->assertOk();
        $this->assertSame('image/gif', $res->headers->get('Content-Type'));

        $this->assertNotNull($r->fresh()->opened_at);
        $this->assertSame('opened', $r->fresh()->status);
        $this->assertSame(1, $campaign->fresh()->opened_count);
    }

    public function test_click_redirects_and_marks_clicked(): void
    {
        $campaign = $this->emailCampaign();
        $r = CrmCampaignRecipient::create([
            'campaign_id' => $campaign->id, 'email' => 'b@x.test', 'token' => Str::random(40), 'status' => 'sent',
        ]);

        $this->get(route('crm.track.click', $r->token) . '?u=' . urlencode('https://example.com/landing'))
            ->assertRedirect('https://example.com/landing');

        $fresh = $r->fresh();
        $this->assertSame('clicked', $fresh->status);
        $this->assertSame(1, $fresh->click_count);
        // A click implies an open.
        $this->assertNotNull($fresh->opened_at);
        $this->assertSame(1, $campaign->fresh()->clicked_count);
    }

    public function test_whatsapp_service_sends_via_cloud_api(): void
    {
        config([
            'services.whatsapp.token' => 'tok', 'services.whatsapp.phone_number_id' => '123',
            'services.whatsapp.base_url' => 'https://graph.facebook.test/v18.0',
        ]);
        Http::fake(['https://graph.facebook.test/*' => Http::response(['messages' => [['id' => 'wamid.X']]], 200)]);

        $res = (new CrmWhatsAppService())->sendText('+27 10 534 8787', 'hi there');

        $this->assertTrue($res['available']);
        $this->assertSame('wamid.X', $res['id']);
        Http::assertSent(fn ($req) => $req['to'] === '27105348787' && $req['type'] === 'text');
    }

    public function test_whatsapp_unconfigured_degrades(): void
    {
        config(['services.whatsapp.token' => '', 'services.whatsapp.phone_number_id' => '']);
        $res = (new CrmWhatsAppService())->sendText('27105348787', 'hi');
        $this->assertFalse($res['available']);
    }

    public function test_run_campaigns_dispatches_due_scheduled(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        $campaign = $this->emailCampaign();
        $campaign->update(['status' => 'scheduled', 'scheduled_at' => now()->subMinute()]);

        $this->artisan('crm:run-campaigns')->assertSuccessful();

        \Illuminate\Support\Facades\Queue::assertPushed(SendCampaignJob::class);
        $this->assertSame('sending', $campaign->fresh()->status);
    }
}
