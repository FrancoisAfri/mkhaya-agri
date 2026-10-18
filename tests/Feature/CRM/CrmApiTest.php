<?php

namespace Tests\Feature\CRM;

use App\Core\User\Models\User;
use App\Modules\CRM\Models\CrmLead;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CrmApiTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        // The 'api' throttle limiter is registered at runtime; ensure it exists
        // for the test environment so throttle:api resolves.
        RateLimiter::for('api', fn () => Limit::none());
    }

    private function user(): User
    {
        /*
         * Created when there is none, rather than skipping.
         *
         * This leaned on a user already sitting in the shared test database — which a
         * RefreshDatabase test elsewhere in the suite truncates. It then skipped, silently, so the
         * test had not actually run in a full-suite pass for some time. A test that makes what it
         * needs cannot be switched off by its neighbours.
         */
        $user = User::query()->first();

        if (! $user) {
            $user = User::forceCreate([
                'uuid' => (string) \Illuminate\Support\Str::uuid(),
                'email' => 'crm+' . \Illuminate\Support\Str::random(8) . '@example.test',
                'password' => bcrypt('secret'), 'status' => 1,
            ]);
        }
        return $user;
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/crm/leads')->assertUnauthorized();
    }

    public function test_can_create_and_list_leads_via_api(): void
    {
        Sanctum::actingAs($this->user());

        $create = $this->postJson('/api/crm/leads', [
            'first_name' => 'Api', 'last_name' => 'Lead', 'company' => 'ApiCo', 'email' => 'api@lead.test',
        ]);

        $create->assertCreated()->assertJsonPath('data.first_name', 'Api');
        $id = $create->json('data.id');

        $this->assertDatabaseHas('crm_leads', ['id' => $id, 'email' => 'api@lead.test', 'status' => 'new']);

        $this->getJson('/api/crm/leads?q=ApiCo')
            ->assertOk()
            ->assertJsonPath('data.0.email', 'api@lead.test');
    }

    public function test_unknown_entity_returns_404(): void
    {
        Sanctum::actingAs($this->user());
        $this->getJson('/api/crm/widgets')->assertNotFound();
    }

    public function test_validation_error_returns_422(): void
    {
        Sanctum::actingAs($this->user());
        $this->postJson('/api/crm/accounts', [])->assertStatus(422); // name required
    }

    public function test_can_update_and_delete_via_api(): void
    {
        Sanctum::actingAs($this->user());

        $lead = CrmLead::create(['uuid' => \Illuminate\Support\Str::uuid(), 'first_name' => 'Old', 'status' => 'new']);

        $this->patchJson("/api/crm/leads/{$lead->id}", ['first_name' => 'Renamed'])
            ->assertOk()->assertJsonPath('data.first_name', 'Renamed');

        $this->deleteJson("/api/crm/leads/{$lead->id}")->assertOk()->assertJsonPath('deleted', true);
        $this->assertSoftDeleted('crm_leads', ['id' => $lead->id]);
    }
}
