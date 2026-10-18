<?php

namespace Tests\Feature\CRM;

use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * CRM campaign sending: spacing sends out without parking a queue worker.
 */
class SharedMessagingTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;

    protected function setUp(): void
    {
        parent::setUp();

        $companyId = DB::table('companies')->min('id');
        if ($companyId === null) {
            $this->markTestSkipped('No company in the test database.');
        }
        $this->companyId = (int) $companyId;
        app(CurrentCompany::class)->set($this->companyId);
        DB::statement("SELECT set_config('app.company_id', ?, false)", [(string) $companyId]);
    }

    // ── Spacing without blocking ─────────────────────────────────────────────

    public function test_a_campaign_send_hands_the_worker_back_rather_than_sleeping_through_the_gap(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        \Illuminate\Support\Facades\Mail::fake();
        \App\Modules\CRM\Models\CrmSetting::put('sending_min_gap_ms', '1000');

        $campaign = \App\Modules\CRM\Models\CrmCampaign::create([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'name' => 'Big send', 'channel' => 'email', 'status' => 'sending',
            'content' => 'Hello', 'subject' => 'Hi',
        ]);

        // A one-second gap and twenty recipients is twenty seconds of sleeping under the old
        // shape, with a queue worker parked for all of it.
        foreach (range(1, 20) as $i) {
            \App\Modules\CRM\Models\CrmCampaignRecipient::create([
                'company_id' => $this->companyId, 'campaign_id' => $campaign->id,
                'email' => "person{$i}@example.test", 'status' => 'pending', 'token' => Str::random(40),
            ]);
        }

        $started = microtime(true);
        (new \App\Modules\CRM\Jobs\SendCampaignJob($campaign->id))
            ->handle(app(\App\Modules\CRM\Services\CrmWhatsAppService::class), app(\App\Modules\CRM\Services\CrmSendingGuard::class));
        $elapsed = microtime(true) - $started;

        // Well under the gap it would have slept for, and the rest comes back as a delayed job.
        $this->assertLessThan(10, $elapsed);
        \Illuminate\Support\Facades\Queue::assertPushed(\App\Modules\CRM\Jobs\SendCampaignJob::class);
        $this->assertGreaterThan(0, \App\Modules\CRM\Models\CrmCampaignRecipient::where('campaign_id', $campaign->id)
            ->where('status', 'pending')->count());
    }

    public function test_a_small_send_finishes_in_one_pass(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        \Illuminate\Support\Facades\Mail::fake();
        \App\Modules\CRM\Models\CrmSetting::put('sending_min_gap_ms', '0');

        $campaign = \App\Modules\CRM\Models\CrmCampaign::create([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'name' => 'Small send', 'channel' => 'email', 'status' => 'sending',
            'content' => 'Hello', 'subject' => 'Hi',
        ]);
        \App\Modules\CRM\Models\CrmCampaignRecipient::create([
            'company_id' => $this->companyId, 'campaign_id' => $campaign->id,
            'email' => 'one@example.test', 'status' => 'pending', 'token' => Str::random(40),
        ]);

        (new \App\Modules\CRM\Jobs\SendCampaignJob($campaign->id))
            ->handle(app(\App\Modules\CRM\Services\CrmWhatsAppService::class), app(\App\Modules\CRM\Services\CrmSendingGuard::class));

        // No gap configured means no slicing and no re-dispatch.
        \Illuminate\Support\Facades\Queue::assertNotPushed(\App\Modules\CRM\Jobs\SendCampaignJob::class);
        $this->assertSame('sent', $campaign->fresh()->status);
    }
}
