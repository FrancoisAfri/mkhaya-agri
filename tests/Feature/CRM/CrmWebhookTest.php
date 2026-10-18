<?php

namespace Tests\Feature\CRM;

use App\Modules\CRM\Models\CrmLead;
use App\Modules\CRM\Models\CrmWebhook;
use App\Modules\CRM\Services\CrmWebhookDispatcher;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class CrmWebhookTest extends TestCase
{
    use DatabaseTransactions;

    public function test_dispatch_posts_signed_payload_to_subscribed_endpoint(): void
    {
        Http::fake(['https://hooks.test/*' => Http::response('ok', 200)]);

        $secret = 'test-secret-123';
        $hook = CrmWebhook::create([
            'uuid' => Str::uuid(), 'name' => 'Test', 'url' => 'https://hooks.test/crm',
            'events' => ['lead.created'], 'secret' => $secret, 'is_active' => true,
        ]);

        $lead = CrmLead::create(['uuid' => Str::uuid(), 'first_name' => 'Web', 'email' => 'w@x.test', 'status' => 'new']);

        app(CrmWebhookDispatcher::class)->dispatch('lead.created', $lead);

        Http::assertSent(function ($request) use ($secret) {
            $expected = 'sha256=' . hash_hmac('sha256', $request->body(), $secret);
            return $request->url() === 'https://hooks.test/crm'
                && $request->hasHeader('X-Crm-Signature', $expected)
                && $request['event'] === 'lead.created'
                && $request['entity'] === 'lead';
        });

        $this->assertDatabaseHas('crm_webhook_deliveries', [
            'webhook_id' => $hook->id, 'event' => 'lead.created', 'success' => true, 'status_code' => 200,
        ]);
    }

    public function test_unsubscribed_event_is_not_delivered(): void
    {
        Http::fake(['https://hooks.test/*' => Http::response('ok', 200)]);

        CrmWebhook::create([
            'uuid' => Str::uuid(), 'name' => 'Deals only', 'url' => 'https://hooks.test/crm',
            'events' => ['deal.won'], 'secret' => 'x', 'is_active' => true,
        ]);

        $lead = CrmLead::create(['uuid' => Str::uuid(), 'first_name' => 'No', 'status' => 'new']);
        app(CrmWebhookDispatcher::class)->dispatch('lead.created', $lead);

        Http::assertNothingSent();
    }

    public function test_failed_delivery_is_logged_not_thrown(): void
    {
        Http::fake(['https://hooks.test/*' => Http::response('boom', 500)]);

        $hook = CrmWebhook::create([
            'uuid' => Str::uuid(), 'name' => 'Failing', 'url' => 'https://hooks.test/crm',
            'events' => ['lead.created'], 'secret' => 'x', 'is_active' => true,
        ]);

        $lead = CrmLead::create(['uuid' => Str::uuid(), 'first_name' => 'F', 'status' => 'new']);
        app(CrmWebhookDispatcher::class)->dispatch('lead.created', $lead); // must not throw

        $this->assertDatabaseHas('crm_webhook_deliveries', [
            'webhook_id' => $hook->id, 'success' => false, 'status_code' => 500,
        ]);
    }
}
