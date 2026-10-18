<?php

namespace Tests\Feature\Sheq;

use App\Core\User\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The sync endpoints over HTTP (§8.3, SHEQ-API-1).
 *
 * SyncTest proves the engine. This proves the door: validation, the per-operation result contract a
 * device needs to clear its queue correctly, and the tenant boundary — which is the one that has to
 * hold even when a controller forgets, because "cross-tenant leak" is on the company-ending row of
 * the risk table.
 */
class SyncApiTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;

    private User $user;

    private int $siteId;

    protected function setUp(): void
    {
        parent::setUp();

        $companyId = DB::table('companies')->min('id');
        if ($companyId === null) {
            $this->markTestSkipped('No company in the test database.');
        }
        $this->companyId = (int) $companyId;

        $this->user = $this->userFor($this->companyId);

        $this->siteId = DB::table('sheq_sites')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'code' => 'A'.Str::random(6), 'name' => 'Plant', 'site_type' => 'SITE',
            'timezone' => 'Africa/Johannesburg', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->ensureModuleActive();
    }

    private function userFor(int $companyId): User
    {
        $id = DB::table('users')->insertGetId([
            'uuid' => (string) Str::uuid(), 'email' => 'api-'.Str::random(8).'@example.test',
            'password' => bcrypt('secret'), 'company_id' => $companyId, 'status' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return User::withoutGlobalScopes()->findOrFail($id);
    }

    /**
     * The routes sit behind `module.active`, which reads `security_modules.active` through a
     * five-minute cache. Entitlement itself needs no fixture: a company with no à-la-carte module
     * composition and no restrictive plan is allowed everything.
     */
    private function ensureModuleActive(): void
    {
        $module = DB::table('security_modules')->where('code_name', 'sheq')->first(['id', 'active']);

        if ($module === null) {
            $this->markTestSkipped('SHEQ module is not registered.');
        }

        if (! $module->active) {
            DB::table('security_modules')->where('id', $module->id)->update(['active' => 1]);
        }

        Cache::forget('module_active_sheq');
    }

    private function hazard(array $overrides = []): array
    {
        $uuid = (string) Str::uuid();

        $id = DB::table('sheq_hazards')->insertGetId(array_merge([
            'uuid' => $uuid, 'company_id' => $this->companyId,
            'reference' => 'HAZ-'.Str::random(6), 'site_id' => $this->siteId,
            'description' => 'Original description.', 'hazard_category' => 'STRUCTURAL',
            'status' => 'OPEN', 'reported_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ], $overrides));

        return ['id' => $id, 'uuid' => $uuid];
    }

    // ── Authentication and tenancy ──────────────────────────────────────────────────────────────

    public function test_an_unauthenticated_device_gets_nothing(): void
    {
        $this->getJson('/api/v1/sheq/sync/pull')->assertUnauthorized();
    }

    public function test_a_token_only_ever_sees_its_own_tenants_changes(): void
    {
        /**
         * The company-ending case. The tenant comes from the token's user and is never a parameter,
         * so there is nothing for a device to tamper with — this test is the proof of that.
         */
        $otherCompany = DB::table('companies')->insertGetId([
            'uuid' => (string) Str::uuid(), 'name' => 'Other Tenant',
            'slug' => 'other-api-'.Str::lower(Str::random(8)),
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);

        // Something happens in our company.
        $hazard = $this->hazard();
        Sanctum::actingAs($this->user);
        $this->postJson('/api/v1/sheq/sync/push', [
            'operations' => [[
                'client_id' => (string) Str::uuid(), 'entity_type' => 'HAZARD',
                'operation' => 'UPDATE', 'entity_uuid' => $hazard['uuid'],
                'base' => [], 'payload' => ['description' => 'ours'],
            ]],
        ])->assertOk();

        $ours = $this->getJson('/api/v1/sheq/sync/pull')->json('entities.HAZARD.changes');
        $this->assertNotEmpty($ours);

        // A device belonging to the other tenant sees none of it.
        Sanctum::actingAs($this->userFor($otherCompany));
        $response = $this->getJson('/api/v1/sheq/sync/pull');

        if ($response->status() === 403) {
            // Entitlement, not leakage — still a pass for this assertion's purpose.
            $this->assertTrue(true);

            return;
        }

        /*
         * Asserted on the whole body rather than on one key.
         *
         * An absent HAZARD entity and an empty changes array are both correct outcomes — which one
         * you get depends on whether the other tenant has any SHEQ data of its own, and a test
         * pinned to one shape fails on a change that is not a leak. What must never happen is our
         * hazard appearing anywhere in their payload, so that is what this checks.
         */
        $this->assertEmpty($response->json('entities.HAZARD.changes') ?? []);
        $this->assertStringNotContainsString($hazard['uuid'], (string) $response->getContent(),
            'A device belonging to another tenant received our hazard.');
    }

    // ── Push contract ───────────────────────────────────────────────────────────────────────────

    public function test_a_clean_push_returns_200_with_a_result_per_operation(): void
    {
        Sanctum::actingAs($this->user);
        $hazard = $this->hazard();

        $response = $this->postJson('/api/v1/sheq/sync/push', [
            'device_id' => 'pixel-4a-0091',
            'operations' => [[
                'client_id' => (string) Str::uuid(), 'entity_type' => 'HAZARD',
                'operation' => 'UPDATE', 'entity_uuid' => $hazard['uuid'],
                'base' => ['description' => 'Original description.'],
                'payload' => ['description' => 'Handrail is gone.'],
            ]],
        ]);

        $response->assertOk()
            ->assertJsonPath('applied', 1)
            ->assertJsonPath('rejected', 0)
            ->assertJsonPath('results.0.status', 'APPLIED');

        $this->assertSame('Handrail is gone.',
            DB::table('sheq_hazards')->find($hazard['id'])->description);
    }

    public function test_a_mixed_batch_returns_207_so_a_device_cannot_read_it_as_all_accepted(): void
    {
        /**
         * A flat 200 is how a rejected transition quietly disappears from a device's queue: the
         * client sees success, clears everything, and the user's work is gone with no error anywhere.
         */
        Sanctum::actingAs($this->user);
        $closed = $this->hazard(['status' => 'CLOSED']);
        $open = $this->hazard();

        $response = $this->postJson('/api/v1/sheq/sync/push', [
            'operations' => [
                [
                    'client_id' => (string) Str::uuid(), 'entity_type' => 'HAZARD',
                    'operation' => 'UPDATE', 'entity_uuid' => $open['uuid'],
                    'base' => [], 'payload' => ['description' => 'fine'],
                ],
                [
                    'client_id' => (string) Str::uuid(), 'entity_type' => 'HAZARD',
                    'operation' => 'TRANSITION', 'entity_uuid' => $closed['uuid'],
                    'payload' => ['to_status' => 'TRIAGED'],
                ],
            ],
        ]);

        $response->assertStatus(207);
        $this->assertSame(1, $response->json('applied'));
        $this->assertSame(1, $response->json('rejected'));

        // And the device is told which one, so it clears exactly the right operations.
        $statuses = array_column($response->json('results'), 'status');
        $this->assertEqualsCanonicalizing(['APPLIED', 'REJECTED'], $statuses);
    }

    public function test_a_conflicting_push_reports_207_and_carries_both_values_back(): void
    {
        Sanctum::actingAs($this->user);
        $hazard = $this->hazard(['status' => 'TRIAGED', 'description' => 'supervisor text']);

        $response = $this->postJson('/api/v1/sheq/sync/push', [
            'operations' => [[
                'client_id' => (string) Str::uuid(), 'entity_type' => 'HAZARD',
                'operation' => 'UPDATE', 'entity_uuid' => $hazard['uuid'],
                'base' => ['description' => 'Original description.'],
                'payload' => ['description' => 'worker text'],
            ]],
        ]);

        $response->assertStatus(207)->assertJsonPath('conflicts', 1);

        $conflicts = $this->getJson('/api/v1/sheq/sync/conflicts')->assertOk()->json('conflicts');
        $this->assertSame('worker text', $conflicts[0]['client_value']);
        $this->assertSame('supervisor text', $conflicts[0]['server_value']);
    }

    public function test_an_unknown_entity_type_is_refused_by_validation_not_by_the_engine(): void
    {
        // Cheaper and clearer than letting it reach the registry and come back as a rejected op.
        Sanctum::actingAs($this->user);

        $this->postJson('/api/v1/sheq/sync/push', [
            'operations' => [[
                'client_id' => (string) Str::uuid(), 'entity_type' => 'PAYROLL',
                'operation' => 'UPDATE', 'payload' => [],
            ]],
        ])->assertStatus(422)->assertJsonValidationErrors('operations.0.entity_type');
    }

    public function test_a_malformed_client_id_is_refused(): void
    {
        Sanctum::actingAs($this->user);

        $this->postJson('/api/v1/sheq/sync/push', [
            'operations' => [[
                'client_id' => 'not-a-uuid', 'entity_type' => 'HAZARD',
                'operation' => 'UPDATE', 'payload' => [],
            ]],
        ])->assertStatus(422)->assertJsonValidationErrors('operations.0.client_id');
    }

    public function test_an_oversized_batch_is_refused_rather_than_timing_out_a_worker(): void
    {
        Sanctum::actingAs($this->user);

        $operations = array_map(fn () => [
            'client_id' => (string) Str::uuid(), 'entity_type' => 'HAZARD',
            'operation' => 'UPDATE', 'payload' => [],
        ], range(1, 201));

        $this->postJson('/api/v1/sheq/sync/push', ['operations' => $operations])
            ->assertStatus(422)->assertJsonValidationErrors('operations');
    }

    // ── Pull contract ───────────────────────────────────────────────────────────────────────────

    public function test_a_pull_honours_a_per_entity_cursor(): void
    {
        Sanctum::actingAs($this->user);
        $hazard = $this->hazard();

        $this->postJson('/api/v1/sheq/sync/push', [
            'operations' => [[
                'client_id' => (string) Str::uuid(), 'entity_type' => 'HAZARD',
                'operation' => 'UPDATE', 'entity_uuid' => $hazard['uuid'],
                'base' => [], 'payload' => ['description' => 'first'],
            ]],
        ])->assertOk();

        $cursor = $this->getJson('/api/v1/sheq/sync/pull')->json('entities.HAZARD.cursor');

        $again = $this->getJson('/api/v1/sheq/sync/pull?'.http_build_query(['cursors' => ['HAZARD' => $cursor]]));

        $again->assertOk();
        $this->assertSame([], $again->json('entities.HAZARD.changes'),
            'A device that is up to date must not be handed the same rows again.');
    }

    public function test_an_unknown_cursor_key_is_ignored_rather_than_failing_the_pull(): void
    {
        // A device on an older build sends an entity this server no longer has. It should still
        // sync everything else rather than being stuck until the app updates.
        Sanctum::actingAs($this->user);

        $this->getJson('/api/v1/sheq/sync/pull?'.http_build_query([
            'cursors' => ['HAZARD' => 0, 'RETIRED_ENTITY' => 99],
        ]))->assertOk()->assertJsonStructure(['entities' => ['HAZARD'], 'cursor', 'server_time']);
    }

    public function test_the_status_endpoint_reports_the_cursor_and_open_conflicts(): void
    {
        // SHEQ-OFF-9: a device must be able to show something true rather than its own guess.
        Sanctum::actingAs($this->user);
        $contested = $this->hazard(['status' => 'TRIAGED', 'description' => 'supervisor text']);
        $clean = $this->hazard();

        /**
         * Two operations on purpose. A push that only conflicts changes nothing on the server, so
         * the cursor correctly stays put — there is nothing to tell other devices about. The clean
         * edit is what should move it.
         */
        $this->postJson('/api/v1/sheq/sync/push', [
            'operations' => [
                [
                    'client_id' => (string) Str::uuid(), 'entity_type' => 'HAZARD',
                    'operation' => 'UPDATE', 'entity_uuid' => $contested['uuid'],
                    'base' => ['description' => 'Original description.'],
                    'payload' => ['description' => 'worker text'],
                ],
                [
                    'client_id' => (string) Str::uuid(), 'entity_type' => 'HAZARD',
                    'operation' => 'UPDATE', 'entity_uuid' => $clean['uuid'],
                    'base' => ['description' => 'Original description.'],
                    'payload' => ['description' => 'uncontested edit'],
                ],
            ],
        ])->assertStatus(207);

        $status = $this->getJson('/api/v1/sheq/sync/status')->assertOk();

        $this->assertSame(1, $status->json('open_conflicts'));
        $this->assertGreaterThan(0, $status->json('cursor'));
        $this->assertContains('INCIDENT', $status->json('entities'));
    }

    // ── Conflict resolution ─────────────────────────────────────────────────────────────────────

    public function test_resolving_a_conflict_over_the_api_writes_the_chosen_value(): void
    {
        Sanctum::actingAs($this->user);
        $hazard = $this->hazard(['status' => 'TRIAGED', 'description' => 'supervisor text']);

        $this->postJson('/api/v1/sheq/sync/push', [
            'operations' => [[
                'client_id' => (string) Str::uuid(), 'entity_type' => 'HAZARD',
                'operation' => 'UPDATE', 'entity_uuid' => $hazard['uuid'],
                'base' => ['description' => 'Original description.'],
                'payload' => ['description' => 'worker text'],
            ]],
        ])->assertStatus(207);

        $uuid = $this->getJson('/api/v1/sheq/sync/conflicts')->json('conflicts.0.uuid');

        $this->postJson("/api/v1/sheq/sync/conflicts/{$uuid}/resolve", [
            'resolution' => 'TOOK_CLIENT',
            'notes' => 'The worker was standing in front of it.',
        ])->assertOk()->assertJsonPath('resolution', 'TOOK_CLIENT');

        $this->assertSame('worker text', DB::table('sheq_hazards')->find($hazard['id'])->description);
        $this->assertSame(0, $this->getJson('/api/v1/sheq/sync/status')->json('open_conflicts'));
    }

    public function test_an_invalid_resolution_is_refused(): void
    {
        Sanctum::actingAs($this->user);

        $this->postJson('/api/v1/sheq/sync/conflicts/'.Str::uuid().'/resolve', [
            'resolution' => 'JUST_PICK_ONE',
        ])->assertStatus(422)->assertJsonValidationErrors('resolution');
    }
}
