<?php

namespace Tests\Feature\CRM;

use App\Modules\CRM\Models\CrmCampaign;
use App\Modules\CRM\Models\CrmCampaignRecipient;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The CRM's own copy of the unsubscribe link.
 *
 * It is a parallel implementation of the outreach tracking — its own tokens, its own click and open
 * counting, its own unsubscribe — and it had the same defect: unsubscribing on a GET, while
 * corporate mail security fetches every URL in an inbound message before the recipient sees it.
 *
 * It was also already sending List-Unsubscribe-Post headers, which tell a mail client to POST. The
 * route only accepted GET, so every genuine one-click unsubscribe was answered with a 405.
 */
class CampaignUnsubscribeTest extends TestCase
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

    private function recipient(string $email = 'person@example.test'): CrmCampaignRecipient
    {
        $campaign = CrmCampaign::create([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'name' => 'Winter push', 'channel' => 'email', 'status' => 'sent',
        ]);

        return CrmCampaignRecipient::create([
            'company_id' => $this->companyId, 'campaign_id' => $campaign->id,
            'email' => $email, 'status' => 'sent', 'token' => Str::random(40),
        ]);
    }

    public function test_a_scanner_fetching_the_link_does_not_unsubscribe_anybody(): void
    {
        $recipient = $this->recipient('scanned@example.test');

        $this->get(route('crm.unsubscribe', $recipient->token))
            ->assertOk()
            ->assertSee('Yes, unsubscribe me', false);

        $this->assertNotSame('unsubscribed', $recipient->fresh()->status);
    }

    public function test_posting_the_confirmation_unsubscribes(): void
    {
        $recipient = $this->recipient('leaving@example.test');

        $this->post(route('crm.unsubscribe', $recipient->token))
            ->assertOk()
            ->assertSee('You have been unsubscribed', false);

        $this->assertSame('unsubscribed', $recipient->fresh()->status);
    }

    public function test_a_mail_clients_one_click_post_is_accepted(): void
    {
        $recipient = $this->recipient('oneclick@example.test');

        // The CRM was already advertising List-Unsubscribe-Post while the route accepted GET only,
        // so a client honouring RFC 8058 got a 405 and the person stayed subscribed.
        $this->post(route('crm.unsubscribe', $recipient->token))->assertOk();

        $this->assertSame('unsubscribed', $recipient->fresh()->status);
    }

    public function test_an_unknown_token_still_answers_rather_than_erroring(): void
    {
        $this->get(route('crm.unsubscribe', Str::random(40)))->assertOk();
        $this->post(route('crm.unsubscribe', Str::random(40)))->assertOk();
    }
}
