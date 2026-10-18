<?php

namespace Tests\Feature\CRM;

use App\Modules\CRM\Jobs\SendCampaignJob;
use App\Modules\CRM\Models\CrmCampaign;
use App\Modules\CRM\Models\CrmCampaignRecipient;
use App\Modules\CRM\Models\CrmSetting;
use App\Modules\CRM\Models\CrmSuppression;
use App\Modules\CRM\Services\CrmWhatsAppService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class CrmSettingsSuppressionTest extends TestCase
{
    use DatabaseTransactions;

    public function test_secret_setting_is_encrypted_at_rest_and_readable(): void
    {
        CrmSetting::put('whatsapp_token', 'super-secret-token');

        // Raw DB value is NOT the plaintext.
        $raw = DB::table('crm_settings')->where('key', 'whatsapp_token')->value('value');
        $this->assertNotSame('super-secret-token', $raw);
        // But get() decrypts it.
        $this->assertSame('super-secret-token', CrmSetting::get('whatsapp_token'));
    }

    public function test_whatsapp_service_reads_credentials_from_db_settings(): void
    {
        config(['services.whatsapp.token' => '', 'services.whatsapp.phone_number_id' => '']);
        $this->assertFalse((new CrmWhatsAppService())->isConfigured());

        CrmSetting::put('whatsapp_token', 'tok');
        CrmSetting::put('whatsapp_phone_number_id', '123');

        $this->assertTrue((new CrmWhatsAppService())->isConfigured());
    }

    public function test_unsubscribe_suppresses_and_marks_recipient_when_confirmed(): void
    {
        $campaign = CrmCampaign::create(['uuid' => Str::uuid(), 'name' => 'C', 'channel' => 'email', 'status' => 'sent']);
        $r = CrmCampaignRecipient::create([
            'campaign_id' => $campaign->id, 'email' => 'Bye@X.test', 'token' => Str::random(40), 'status' => 'sent',
        ]);

        // A GET only asks now. Mail scanners fetch every URL in an inbound message before the
        // recipient sees it, and this one writes to the suppression list.
        $this->get(route('crm.unsubscribe', $r->token))->assertOk();
        $this->assertFalse(CrmSuppression::isSuppressed('email', 'bye@x.test'));

        $this->post(route('crm.unsubscribe', $r->token))->assertOk()->assertSee('unsubscribed', false);

        $this->assertTrue(CrmSuppression::isSuppressed('email', 'bye@x.test'));
        $this->assertSame('unsubscribed', $r->fresh()->status);
    }

    public function test_send_skips_suppressed_recipients(): void
    {
        Mail::fake();
        CrmSuppression::suppress('email', 'blocked@x.test');

        $campaign = CrmCampaign::create(['uuid' => Str::uuid(), 'name' => 'C', 'channel' => 'email', 'subject' => 'Hi', 'content' => '<p>hi</p>', 'status' => 'draft', 'total_recipients' => 2]);
        $ok = CrmCampaignRecipient::create(['campaign_id' => $campaign->id, 'email' => 'ok@x.test', 'token' => Str::random(40), 'status' => 'pending']);
        $blocked = CrmCampaignRecipient::create(['campaign_id' => $campaign->id, 'email' => 'blocked@x.test', 'token' => Str::random(40), 'status' => 'pending']);

        SendCampaignJob::dispatchSync($campaign->id);

        $this->assertSame('sent', $ok->fresh()->status);
        $this->assertSame('unsubscribed', $blocked->fresh()->status); // skipped
    }

    public function test_isSuppressed_is_case_insensitive(): void
    {
        CrmSuppression::suppress('email', 'Mixed@Case.Test');
        $this->assertTrue(CrmSuppression::isSuppressed('email', 'mixed@case.test'));
        $this->assertFalse(CrmSuppression::isSuppressed('email', 'other@x.test'));
    }
}
