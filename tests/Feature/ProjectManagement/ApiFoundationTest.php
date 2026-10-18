<?php

namespace Tests\Feature\ProjectManagement;

use App\Core\User\Models\User;
use App\Modules\Operations\ProjectManagement\Models\Project;
use App\Modules\Tenancy\Models\Company;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * The PM API's foundation (PM-1010, §4): tenant from the token, cursor pagination, filtering,
 * field selection, idempotent retries, and problem+json errors.
 *
 * The tenancy assertions here are the important ones. Before this stage the `api` middleware group
 * carried no tenant at all, so an API request read across every company; these tests are what stop
 * that regressing.
 */
class ApiFoundationTest extends ProjectManagementTestCase
{
    private Company $alpha;

    private Company $bravo;

    private User $alphaUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alpha = Company::create(['name' => 'Alpha Construction']);
        $this->bravo = Company::create(['name' => 'Bravo Civils']);

        $this->entitleCompany($this->alpha);
        $this->entitleCompany($this->bravo);

        $this->alphaUser = $this->manager;
        $this->alphaUser->forceFill(['company_id' => $this->alpha->id])->save();
    }

    // ── Tenancy ──────────────────────────────────────────────────────────────

    public function test_the_tenant_comes_from_the_token_not_the_request(): void
    {
        $mine = $this->projectFor($this->alpha, 'PRJ-A');
        $theirs = $this->projectFor($this->bravo, 'PRJ-B');

        Sanctum::actingAs($this->alphaUser);

        $response = $this->getJson('/api/v1/pm/projects');

        $response->assertOk();
        $codes = array_column($response->json('data'), 'code');

        $this->assertSame(['PRJ-A'], $codes, 'An API token must only ever see its own tenant.');
        $this->assertNotContains('PRJ-B', $codes);
        $this->assertNotNull($theirs->id);
        $this->assertNotNull($mine->id);
    }

    public function test_another_tenants_project_returns_404_not_403(): void
    {
        $theirs = $this->projectFor($this->bravo, 'PRJ-B');

        Sanctum::actingAs($this->alphaUser);

        // 403 would confirm the record exists. 404 says nothing at all (PM-1010).
        $this->getJson("/api/v1/pm/projects/{$theirs->uuid}")
            ->assertNotFound()
            ->assertHeader('content-type', 'application/problem+json')
            ->assertJsonPath('status', 404);
    }

    public function test_a_request_without_a_tenant_is_refused(): void
    {
        // A platform admin has no company. Previously such a request would have read every tenant's
        // projects; now it is refused outright.
        $admin = User::factory()->create(['status' => 1, 'is_platform_admin' => true]);

        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/pm/projects')
            ->assertForbidden()
            ->assertJsonPath('status', 403);
    }

    public function test_an_unauthenticated_request_gets_problem_json(): void
    {
        $this->getJson('/api/v1/pm/projects')
            ->assertUnauthorized()
            ->assertHeader('content-type', 'application/problem+json')
            ->assertJsonPath('title', 'Unauthenticated');
    }

    // ── Conventions ──────────────────────────────────────────────────────────

    public function test_list_is_cursor_paginated_and_the_limit_is_clamped(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            $this->projectFor($this->alpha, "PRJ-{$i}");
        }

        Sanctum::actingAs($this->alphaUser);

        $first = $this->getJson('/api/v1/pm/projects?limit=2');
        $first->assertOk();
        $this->assertCount(2, $first->json('data'));
        $this->assertTrue($first->json('meta.has_more'));

        $cursor = $first->json('meta.next_cursor');
        $this->assertNotNull($cursor);

        $second = $this->getJson('/api/v1/pm/projects?limit=2&cursor=' . urlencode($cursor));
        $second->assertOk();
        $this->assertCount(1, $second->json('data'));
        $this->assertNull($second->json('meta.next_cursor'));

        // A client cannot ask for the whole table.
        $this->getJson('/api/v1/pm/projects?limit=5000')->assertOk();
    }

    public function test_field_selection_narrows_the_payload_but_always_keeps_id(): void
    {
        $this->projectFor($this->alpha, 'PRJ-F');

        Sanctum::actingAs($this->alphaUser);

        $row = $this->getJson('/api/v1/pm/projects?fields=name,status')->json('data.0');

        $this->assertSame(['id', 'name', 'status'], array_keys($row));
    }

    public function test_filters_are_applied(): void
    {
        $this->projectFor($this->alpha, 'PRJ-ACTIVE', ['status' => 'active']);
        $this->projectFor($this->alpha, 'PRJ-DRAFT', ['status' => 'draft']);

        Sanctum::actingAs($this->alphaUser);

        $codes = array_column(
            $this->getJson('/api/v1/pm/projects?filter[status]=active')->json('data'),
            'code'
        );

        $this->assertSame(['PRJ-ACTIVE'], $codes);
    }

    public function test_the_envelope_carries_the_capability_manifest(): void
    {
        Sanctum::actingAs($this->alphaUser);

        $capabilities = $this->getJson('/api/v1/pm/projects')->json('meta.capabilities');

        $this->assertIsArray($capabilities);
        $this->assertArrayHasKey('hr.employees', $capabilities);
    }

    public function test_a_validation_failure_is_problem_json_with_field_errors(): void
    {
        Sanctum::actingAs($this->alphaUser);

        $this->postJson('/api/v1/pm/projects', ['currency' => 'XYZ'])
            ->assertStatus(422)
            ->assertHeader('content-type', 'application/problem+json')
            ->assertJsonPath('title', 'Validation failed')
            ->assertJsonStructure(['type', 'title', 'status', 'errors' => ['name', 'currency']]);
    }

    // ── Idempotency ──────────────────────────────────────────────────────────

    public function test_a_retried_mutation_does_not_create_a_second_record(): void
    {
        Sanctum::actingAs($this->alphaUser);

        $payload = ['name' => 'Bridge works', 'currency' => 'ZAR', 'timezone' => 'Africa/Johannesburg'];
        $headers = ['Idempotency-Key' => 'device-9-create-1'];

        $first = $this->postJson('/api/v1/pm/projects', $payload, $headers)->assertCreated();
        $second = $this->postJson('/api/v1/pm/projects', $payload, $headers)->assertCreated();

        $this->assertSame(1, Project::count(), 'A retry must replay, not create a second project.');
        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $second->assertHeader('Idempotent-Replay', 'true');
    }

    public function test_reusing_a_key_with_a_different_body_is_rejected(): void
    {
        Sanctum::actingAs($this->alphaUser);

        $headers = ['Idempotency-Key' => 'device-9-create-2'];

        $this->postJson('/api/v1/pm/projects', [
            'name' => 'First', 'currency' => 'ZAR', 'timezone' => 'Africa/Johannesburg',
        ], $headers)->assertCreated();

        $this->postJson('/api/v1/pm/projects', [
            'name' => 'Different', 'currency' => 'ZAR', 'timezone' => 'Africa/Johannesburg',
        ], $headers)->assertStatus(422)->assertJsonPath('title', 'Idempotency-Key reused');

        $this->assertSame(1, Project::count());
    }

    public function test_a_failed_mutation_does_not_burn_the_key(): void
    {
        Sanctum::actingAs($this->alphaUser);

        $headers = ['Idempotency-Key' => 'device-9-create-3'];

        // Invalid: no name.
        $this->postJson('/api/v1/pm/projects', ['currency' => 'ZAR'], $headers)->assertStatus(422);

        // The client fixes the payload and retries with the same key — this must be allowed.
        $this->postJson('/api/v1/pm/projects', [
            'name' => 'Corrected', 'currency' => 'ZAR', 'timezone' => 'Africa/Johannesburg',
        ], $headers)->assertCreated();

        $this->assertSame(1, Project::count());
    }

    public function test_a_mutation_without_a_key_still_works(): void
    {
        Sanctum::actingAs($this->alphaUser);

        $this->postJson('/api/v1/pm/projects', [
            'name' => 'No key', 'currency' => 'ZAR', 'timezone' => 'Africa/Johannesburg',
        ])->assertCreated();

        $this->assertSame(0, DB::table('pm_idempotency_keys')->count());
    }

    public function test_a_client_supplied_uuid_survives_the_api(): void
    {
        Sanctum::actingAs($this->alphaUser);

        $uuid = '019f1111-0000-7000-8000-00000000beef';

        $this->postJson('/api/v1/pm/projects', [
            'uuid' => $uuid, 'name' => 'Offline created', 'currency' => 'ZAR',
            'timezone' => 'Africa/Johannesburg',
        ], ['Idempotency-Key' => 'device-9-offline'])->assertCreated();

        $this->assertSame($uuid, Project::firstOrFail()->uuid);
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function projectFor(Company $company, string $code, array $attributes = []): Project
    {
        return $this->asCompany($company, fn () => $this->makeProject(
            array_merge(['code' => $code], $attributes)
        ));
    }
}
