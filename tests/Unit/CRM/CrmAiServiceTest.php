<?php

namespace Tests\Unit\CRM;

use App\Modules\CRM\Services\CrmAiService;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class CrmAiServiceTest extends TestCase
{
    private function configured(): CrmAiService
    {
        config([
            'ai.audit.enabled'             => false,
            'ai.providers.crm'             => null,
            'ai.providers.openai.api_key'  => 'sk-test',
            'ai.providers.openai.base_url' => 'https://api.openai.test/v1',
            'ai.providers.openai.model'    => 'gpt-4o',
        ]);

        return new CrmAiService();
    }

    /**
     * The answer the model is to give.
     *
     * This used to fake an HTTP response from api.openai.test, which nothing calls any more:
     * CrmAiService routes every request through AIChatProviderChain, the free-first provider
     * chain, so the faked endpoint was never hit and the service reported "AI request failed"
     * while the tests read the resulting array as a success.
     */
    private function fake(array $json): void
    {
        $chain = Mockery::mock(\App\Core\AI\Services\AIChatProviderChain::class);
        $chain->shouldReceive('text')->andReturn(json_encode($json));

        $this->app->instance(\App\Core\AI\Services\AIChatProviderChain::class, $chain);
    }

    public function test_unconfigured_degrades_gracefully(): void
    {
        config(['ai.audit.enabled' => false, 'ai.providers.crm' => null, 'ai.providers.openai.api_key' => '']);
        $svc = new CrmAiService();

        $this->assertFalse($svc->isConfigured());
        $this->assertFalse($svc->scoreLead([])['available']);
        $this->assertFalse($svc->assessDealRisk([])['available']);
    }

    public function test_score_lead(): void
    {
        $this->fake(['score' => 82, 'priority' => 'high', 'reason' => 'strong intent']);

        $r = $this->configured()->scoreLead(['name' => 'Acme', 'source' => 'demo request']);

        $this->assertTrue($r['available']);
        $this->assertSame(82, $r['score']);
        $this->assertSame('high', $r['priority']);
    }

    public function test_assess_deal_risk(): void
    {
        $this->fake(['risk_level' => 'high', 'reason' => 'stalled 30 days']);

        $r = $this->configured()->assessDealRisk(['title' => 'Big deal', 'days_in_stage' => 30]);

        $this->assertTrue($r['available']);
        $this->assertSame('high', $r['risk_level']);
    }

    public function test_summarise_conversation(): void
    {
        $this->fake(['summary' => 'Customer wants pricing', 'sentiment' => 'positive', 'action_items' => ['Send quote']]);

        $r = $this->configured()->summariseConversation('long call transcript...');

        $this->assertTrue($r['available']);
        $this->assertSame('positive', $r['sentiment']);
        $this->assertContains('Send quote', $r['action_items']);
    }

    public function test_http_failure_propagates_error(): void
    {
        Http::fake(['https://api.openai.test/*' => Http::response('err', 500)]);

        $r = $this->configured()->scoreLead(['name' => 'X']);

        $this->assertTrue($r['available']);
        $this->assertArrayHasKey('error', $r);
    }
}
