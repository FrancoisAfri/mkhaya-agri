<?php

namespace Tests\Feature\CRM;

use App\Core\User\Models\User;
use App\Modules\CRM\Models\CrmAccount;
use App\Modules\CRM\Models\CrmActivity;
use App\Modules\CRM\Models\CrmContact;
use App\Modules\CRM\Models\CrmDeal;
use App\Modules\CRM\Models\CrmPipeline;
use App\Modules\CRM\Models\CrmStage;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The account 360 page can raise a deal, a contact and an activity against the account
 * you're looking at, without going to the pipeline / contacts / activities pages first.
 */
class CrmAccountQuickAddTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->activateCrm();
    }

    /** CRM on in the module registry, so the route group's module.active gate lets us through. */
    private function activateCrm(): void
    {
        if (! DB::table('security_modules')->where('code_name', 'crm')->where('active', 1)->exists()) {
            $categoryId = DB::table('security_categories')->value('id')
                ?? DB::table('security_categories')->insertGetId(['name' => 'Test', 'created_at' => now(), 'updated_at' => now()]);

            DB::table('security_modules')->updateOrInsert(
                ['code_name' => 'crm'],
                ['uuid' => (string) Str::uuid(), 'name' => 'CRM', 'path' => 'crm', 'category_id' => $categoryId,
                    'active' => 1, 'created_at' => now(), 'updated_at' => now()],
            );
        }
        // Flush rather than forget two keys: the module-active and hub-tier lookups are cached
        // for minutes/hours, and an earlier test class rolling back its own module rows leaves
        // stale entries that decide this class's requests before it ever reaches the controller.
        Cache::flush();
    }

    /** A platform admin, so the hub tier gate doesn't need per-user module access rows. */
    private function user(): User
    {
        return User::forceCreate([
            'uuid'              => (string) Str::uuid(),
            'email'             => 'quickadd+' . Str::random(8) . '@example.test',
            'password'          => bcrypt('secret'),
            'status'            => 1,   // 0 = locked, per User::isLocked()
            'is_platform_admin' => true,
        ]);
    }

    /** The seeded default pipeline, else a throwaway one with a single stage. */
    private function pipeline(): CrmPipeline
    {
        $pipeline = CrmPipeline::where('is_active', true)->with('stages')->get()
            ->first(fn ($p) => $p->stages->isNotEmpty());
        if ($pipeline) {
            return $pipeline;
        }

        $pipeline = CrmPipeline::forceCreate(['uuid' => (string) Str::uuid(), 'name' => 'Test Pipeline']);
        CrmStage::forceCreate(['pipeline_id' => $pipeline->id, 'name' => 'Qualify']);

        return $pipeline->load('stages');
    }

    private function account(): CrmAccount
    {
        return CrmAccount::create(['uuid' => (string) Str::uuid(), 'name' => 'Quick Add ' . Str::random(6)]);
    }

    public function test_the_account_page_ships_what_the_add_dialogs_need(): void
    {
        $account = $this->account();

        $this->actingAs($this->user())
            ->get(route('crm.accounts.show', $account->uuid))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Modules/CRM/AccountDetail')
                ->has('pipelines')
                ->has('activityTypes'));
    }

    public function test_a_deal_can_be_raised_against_the_account(): void
    {
        $account = $this->account();
        $pipeline = $this->pipeline();

        $this->actingAs($this->user())
            ->post(route('crm.deals.store'), [
                'title'       => 'Renewal',
                'pipeline_id' => $pipeline->id,
                'stage_id'    => $pipeline->stages->first()->id,
                'account_id'  => $account->id,
                'amount'      => 25000,
            ])->assertRedirect();

        $deal = CrmDeal::where('account_id', $account->id)->first();
        $this->assertNotNull($deal);
        $this->assertSame('Renewal', $deal->title);
        $this->assertSame('open', $deal->status);
    }

    public function test_a_contact_and_an_activity_land_on_the_account(): void
    {
        $account = $this->account();
        $user = $this->user();

        $this->actingAs($user)->post(route('crm.contacts.store'), [
            'account_id' => $account->id, 'first_name' => 'Thabo', 'last_name' => 'M', 'email' => 'thabo@example.co.za',
        ])->assertRedirect();

        $this->actingAs($user)->post(route('crm.activities.store'), [
            'type' => 'call', 'subject' => 'Intro call',
            'related_type' => 'account', 'related_id' => $account->id,
        ])->assertRedirect();

        $this->assertSame(1, CrmContact::where('account_id', $account->id)->count());
        $this->assertSame(1, CrmActivity::where('related_type', 'account')->where('related_id', $account->id)->count());
    }
}
