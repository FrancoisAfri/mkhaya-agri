<?php

namespace Tests\Feature\CRM;

use App\Modules\CRM\Models\CrmIntegration;
use App\Modules\CRM\Services\CrmIntegrationCatalog;
use App\Modules\CRM\Services\CrmMailService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CrmIntegrationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_catalog_covers_every_category_with_providers(): void
    {
        $all = CrmIntegrationCatalog::all();

        foreach (['email', 'voip', 'whatsapp', 'sms'] as $category) {
            $this->assertArrayHasKey($category, $all);
            $this->assertNotEmpty($all[$category], "$category should list providers");
        }

        // A few well-known providers must be present.
        $this->assertArrayHasKey('gmail', $all['email']);
        $this->assertArrayHasKey('outlook', $all['email']);
        $this->assertArrayHasKey('sendgrid', $all['email']);
        $this->assertArrayHasKey('twilio_voice', $all['voip']);
        $this->assertArrayHasKey('sip', $all['voip']);
        $this->assertArrayHasKey('meta_whatsapp', $all['whatsapp']);
    }

    public function test_find_returns_provider_with_its_category(): void
    {
        $meta = CrmIntegrationCatalog::find('meta_whatsapp');
        $this->assertSame('whatsapp', $meta['category']);
        $this->assertSame('WhatsApp Cloud API (Meta)', $meta['label']);
        $this->assertNull(CrmIntegrationCatalog::find('nope'));
    }

    public function test_config_is_encrypted_at_rest_and_readable(): void
    {
        $i = new CrmIntegration(['provider' => 'gmail', 'category' => 'email']);
        $i->setConfigArray(['username' => 'me@gmail.com', 'password' => 'app-pass-123']);
        $i->save();

        $raw = DB::table('crm_integrations')->where('id', $i->id)->value('config');
        $this->assertStringNotContainsString('app-pass-123', (string) $raw);

        $this->assertSame('app-pass-123', CrmIntegration::configFor('gmail')['password']);
    }

    public function test_active_returns_only_the_active_integration_for_a_category(): void
    {
        CrmIntegration::create(['provider' => 'smtp', 'category' => 'email', 'active' => false]);
        $on = CrmIntegration::create(['provider' => 'gmail', 'category' => 'email', 'active' => true]);

        $this->assertSame($on->id, CrmIntegration::active('email')->id);
    }

    public function test_mail_service_builds_dynamic_mailer_from_active_provider(): void
    {
        $i = new CrmIntegration(['provider' => 'smtp', 'category' => 'email', 'active' => true]);
        $i->setConfigArray([
            'host' => 'mail.example.com', 'port' => '587', 'encryption' => 'tls',
            'username' => 'u', 'password' => 'p',
            'from_email' => 'sales@example.com', 'from_name' => 'Example Sales',
        ]);
        $i->save();

        $svc = new CrmMailService();
        $this->assertTrue($svc->hasProvider());
        $this->assertSame('sales@example.com', $svc->from()['address']);

        $svc->mailer(); // injects config without throwing
        $this->assertSame('mail.example.com', config('mail.mailers.crm_dynamic.host'));
        $this->assertSame('tls', config('mail.mailers.crm_dynamic.encryption'));
    }
}
