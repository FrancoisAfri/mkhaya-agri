<?php

namespace Tests\Feature\Sheq;

use App\Core\User\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The public API contract (§8.3).
 *
 * These test the promises an integrator builds against and cannot easily recover from if broken:
 * one error shape everywhere, paging that does not skip rows, a tenant boundary with no parameter
 * to tamper with, and a signing secret nobody can read back.
 */
class PublicApiTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;

    private User $user;

    private int $siteId;

    protected function setUp(): void
    {
        parent::setUp();

        // These tests read what a page hands its component, not the compiled front end. Without
        // this the suite depends on `public/build/manifest.json`, so an unrelated `vite build`
        // running in another checkout turns every page assertion below into a 500.
        $this->withoutVite();

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
            'uuid' => (string) Str::uuid(), 'email' => 'papi-'.Str::random(8).'@example.test',
            'password' => bcrypt('secret'), 'company_id' => $companyId, 'status' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $user = User::withoutGlobalScopes()->findOrFail($id);

        // The read surface is gated on the module's existing read permission. Granting it directly
        // rather than through a role keeps the fixture about the API, not about role composition.
        foreach (['sheq.report.view', 'sheq.configure'] as $permission) {
            if (Permission::where('name', $permission)
                ->where('guard_name', 'web')->exists()) {
                $user->givePermissionTo($permission);
            }
        }

        $user->forgetCachedPermissions();
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        return $user;
    }

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

    private function hazard(array $overrides = []): string
    {
        $uuid = (string) Str::uuid();

        DB::table('sheq_hazards')->insert(array_merge([
            'uuid' => $uuid, 'company_id' => $this->companyId,
            'reference' => 'HAZ-'.Str::random(6), 'site_id' => $this->siteId,
            'description' => 'A guard is missing.', 'hazard_category' => 'MECHANICAL',
            'status' => 'OPEN', 'reported_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ], $overrides));

        return $uuid;
    }

    // ── SHEQ-API-4: one error shape, everywhere ───────────────────────────────────────────────

    public function test_an_unauthenticated_call_returns_problem_details(): void
    {
        $response = $this->getJson('/api/v1/sheq/hazards');

        $response->assertStatus(401);

        // The failure most likely to break the contract: it comes from middleware, not a
        // controller, so it never passes through any handler a controller-level fix would touch.
        $this->assertStringContainsString('application/problem+json',
            $response->headers->get('Content-Type'));
        $response->assertJsonStructure(['type', 'title', 'status', 'detail']);
        $this->assertSame(401, $response->json('status'));
    }

    public function test_a_missing_record_returns_problem_details(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->getJson('/api/v1/sheq/hazards/'.Str::uuid());

        $response->assertStatus(404);
        $this->assertStringContainsString('problem+json', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('not-found', $response->json('type'));
    }

    public function test_a_validation_failure_keeps_the_problem_shape_and_adds_fields(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->getJson('/api/v1/sheq/hazards?limit=9999');

        $response->assertStatus(422);
        // Problem details for anyone parsing generically, `errors` for anyone who wants specifics.
        $response->assertJsonStructure(['type', 'title', 'status', 'detail', 'errors']);
        $this->assertStringContainsString('validation-failed', $response->json('type'));
    }

    public function test_the_type_uri_is_stable_and_distinguishes_failure_kinds(): void
    {
        Sanctum::actingAs($this->user);

        $notFound = $this->getJson('/api/v1/sheq/hazards/'.Str::uuid())->json('type');
        $unknown = $this->getJson('/api/v1/sheq/not-a-resource')->json('type');

        // A client branching on `type` needs these to differ; branching on `detail` would break
        // the first time somebody improved the wording.
        $this->assertNotSame($notFound, $unknown);
        $this->assertStringContainsString('unknown-resource', $unknown);
    }

    // ── Tenancy ───────────────────────────────────────────────────────────────────────────────

    public function test_a_token_cannot_read_another_tenants_records(): void
    {
        // Created rather than borrowed from the seed data. This is the company-ending test, and a
        // freshly seeded database with a single tenant would silently skip precisely the assertion
        // that must never stop running.
        $otherCompany = (int) DB::table('companies')->insertGetId([
            'uuid' => (string) Str::uuid(), 'slug' => 'rival-'.Str::random(8),
            'name' => 'Rival Mining', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $foreign = (string) Str::uuid();

        DB::table('sheq_hazards')->insert([
            'uuid' => $foreign, 'company_id' => $otherCompany,
            'reference' => 'HAZ-'.Str::random(6), 'description' => 'Someone else’s hazard.',
            'hazard_category' => 'MECHANICAL', 'status' => 'OPEN', 'reported_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        Sanctum::actingAs($this->user);

        // 404 rather than 403: telling a caller that a record exists but is not theirs turns the
        // endpoint into an oracle for probing another company's identifiers.
        $this->getJson("/api/v1/sheq/hazards/{$foreign}")->assertStatus(404);

        $uuids = collect($this->getJson('/api/v1/sheq/hazards')->json('data'))->pluck('uuid');
        $this->assertNotContains($foreign, $uuids);
    }

    // ── Paging ────────────────────────────────────────────────────────────────────────────────

    public function test_paging_walks_every_record_exactly_once(): void
    {
        Sanctum::actingAs($this->user);

        $created = collect(range(1, 7))->map(fn () => $this->hazard())->all();

        $seen = [];
        $cursor = null;
        $guard = 0;

        do {
            $url = '/api/v1/sheq/hazards?limit=2'.($cursor === null ? '' : "&cursor={$cursor}");
            $page = $this->getJson($url)->assertOk()->json();

            foreach ($page['data'] as $row) {
                $seen[] = $row['uuid'];
            }

            $cursor = $page['pagination']['next_cursor'];
        } while ($cursor !== null && ++$guard < 50);

        // The property that matters for a consumer building a mirror: no duplicates and no gaps.
        // Offset paging on an append-heavy table breaks exactly this, and breaks it invisibly.
        $this->assertSame(count($seen), count(array_unique($seen)), 'A record was returned twice.');

        foreach ($created as $uuid) {
            $this->assertContains($uuid, $seen, 'Paging skipped a record.');
        }
    }

    public function test_the_last_page_reports_no_more(): void
    {
        Sanctum::actingAs($this->user);
        $this->hazard();

        $page = $this->getJson('/api/v1/sheq/hazards?limit=200')->assertOk()->json();

        $this->assertFalse($page['pagination']['has_more']);
        $this->assertNull($page['pagination']['next_cursor'],
            'next_cursor must be null when exhausted so a client can loop until null.');
    }

    public function test_only_declared_fields_are_published(): void
    {
        Sanctum::actingAs($this->user);
        $this->hazard();

        $row = $this->getJson('/api/v1/sheq/hazards?limit=1')->json('data.0');

        // SELECT * would silently publish every column added later as public API, and a consumer
        // would start depending on one.
        $this->assertArrayNotHasKey('id', $row, 'Internal ids must not leak into the contract.');
        $this->assertArrayNotHasKey('company_id', $row);
        $this->assertArrayHasKey('uuid', $row);
    }

    // ── Routing ───────────────────────────────────────────────────────────────────────────────

    public function test_the_resource_wildcard_does_not_swallow_the_named_routes(): void
    {
        Sanctum::actingAs($this->user);

        // `/{resource}` is greedy enough to answer "no such resource" to a perfectly good webhooks
        // call if the constraint or the ordering is ever changed.
        $this->getJson('/api/v1/sheq/webhooks')->assertOk();
        $this->getJson('/api/v1/sheq/events')->assertOk();
        $this->getJson('/api/v1/sheq/catalogue')->assertOk();
    }

    // ── Webhooks (SHEQ-API-5) ─────────────────────────────────────────────────────────────────

    public function test_the_signing_secret_is_returned_once_and_never_again(): void
    {
        Sanctum::actingAs($this->user);

        $created = $this->postJson('/api/v1/sheq/webhooks', [
            'name' => 'Ops bridge', 'url' => 'https://example.test/hook',
            'event_types' => ['sheq.incident.reported'],
        ])->assertStatus(201)->json();

        $secret = $created['data']['secret'];
        $this->assertStringStartsWith('whsec_', $secret);

        $listed = $this->getJson('/api/v1/sheq/webhooks')->assertOk()->json('data');
        $row = collect($listed)->firstWhere('uuid', $created['data']['uuid']);

        // A secret anyone with the token can read back proves nothing about who sent a payload,
        // which defeats the entire point of signing.
        $this->assertArrayNotHasKey('secret', $row);
        $this->assertNotSame($secret, $row['secret_hint'] ?? null);
        $this->assertStringContainsString('…', $row['secret_hint']);
    }

    public function test_the_stored_secret_is_not_readable_as_plaintext(): void
    {
        Sanctum::actingAs($this->user);

        $created = $this->postJson('/api/v1/sheq/webhooks', [
            'name' => 'Bridge', 'url' => 'https://example.test/hook',
            'event_types' => ['sheq.incident.reported'],
        ])->assertStatus(201)->json();

        $stored = DB::table('sheq_webhooks')->where('uuid', $created['data']['uuid'])
            ->value('secret');

        $this->assertNotSame($created['data']['secret'], $stored,
            'A database read must not yield a working signing secret.');
    }

    public function test_a_plaintext_url_is_refused(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->postJson('/api/v1/sheq/webhooks', [
            'name' => 'Insecure', 'url' => 'http://example.test/hook',
            'event_types' => ['sheq.incident.reported'],
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('insecure-webhook-url', $response->json('type'));
    }

    public function test_subscribing_to_an_event_that_does_not_exist_fails_loudly(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->postJson('/api/v1/sheq/webhooks', [
            'name' => 'Typo', 'url' => 'https://example.test/hook',
            'event_types' => ['sheq.incident.reportd'],
        ]);

        // Accepting it would present weeks later as "the webhook is broken", with nothing in any
        // log to explain why it never fired.
        $response->assertStatus(422);
        $this->assertStringContainsString('unknown-event-type', $response->json('type'));
        $this->assertContains('sheq.incident.reportd', $response->json('unknown'));
        $this->assertNotEmpty($response->json('available'));
    }

    public function test_rotating_replaces_the_secret(): void
    {
        Sanctum::actingAs($this->user);

        $created = $this->postJson('/api/v1/sheq/webhooks', [
            'name' => 'Bridge', 'url' => 'https://example.test/hook',
            'event_types' => ['sheq.incident.reported'],
        ])->json();

        $rotated = $this->postJson("/api/v1/sheq/webhooks/{$created['data']['uuid']}/rotate")
            ->assertOk()->json();

        $this->assertNotSame($created['data']['secret'], $rotated['data']['secret']);
        $this->assertStringContainsString('stopped working immediately', $rotated['warning']);
    }

    public function test_re_enabling_clears_the_failure_count(): void
    {
        Sanctum::actingAs($this->user);

        $created = $this->postJson('/api/v1/sheq/webhooks', [
            'name' => 'Flaky', 'url' => 'https://example.test/hook',
            'event_types' => ['sheq.incident.reported'],
        ])->json();

        DB::table('sheq_webhooks')->where('uuid', $created['data']['uuid'])->update([
            'is_active' => false, 'consecutive_failures' => 9,
            'disabled_at' => now(), 'disabled_reason' => 'Too many failures',
        ]);

        $this->patchJson("/api/v1/sheq/webhooks/{$created['data']['uuid']}", ['is_active' => true])
            ->assertOk();

        $row = DB::table('sheq_webhooks')->where('uuid', $created['data']['uuid'])
            ->first(['is_active', 'consecutive_failures', 'disabled_at']);

        // Otherwise the next single failure trips the threshold again and the subscriber never
        // gets a fair run after fixing their endpoint.
        $this->assertTrue((bool) $row->is_active);
        $this->assertSame(0, (int) $row->consecutive_failures);
        $this->assertNull($row->disabled_at);
    }

    // ── Confidentiality ───────────────────────────────────────────────────────────────────────

    public function test_confidential_incidents_are_not_published_to_ordinary_read_access(): void
    {
        $confidential = (string) Str::uuid();

        DB::table('sheq_incidents')->insert([
            'uuid' => $confidential, 'company_id' => $this->companyId,
            'reference' => 'INC-'.Str::random(6), 'site_id' => $this->siteId,
            'occurred_at' => now(), 'reported_at' => now(),
            'incident_type' => 'HARASSMENT', 'description' => 'A protected disclosure.',
            'status' => 'OPEN', 'is_confidential' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        Sanctum::actingAs($this->user);

        $uuids = collect($this->getJson('/api/v1/sheq/incidents?limit=200')->assertOk()->json('data'))
            ->pluck('uuid');

        // An integration pulling the register wholesale would otherwise export harassment reports
        // into a BI tool where nobody is checking who can open the dashboard.
        $this->assertNotContains($confidential, $uuids);

        // And hidden from the list is not enough on its own — identifiers travel.
        $this->getJson("/api/v1/sheq/incidents/{$confidential}")->assertStatus(404);
    }

    // ── Scanning (§6.2) ───────────────────────────────────────────────────────────────────────

    public function test_a_scanned_ppe_barcode_returns_the_item_and_its_stock(): void
    {
        Sanctum::actingAs($this->user);

        $barcode = 'PPE-'.Str::random(8);

        $itemId = DB::table('sheq_ppe_items')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'code' => 'BOOT-'.Str::random(4), 'name' => 'Safety boots',
            'lifespan_months' => 12, 'requires_size' => true, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('sheq_ppe_stock')->insert([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'ppe_item_id' => $itemId, 'size' => '9', 'quantity_on_hand' => 2,
            'reorder_level' => 5, 'barcode' => $barcode,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->getJson("/api/v1/sheq/mobile/scan/{$barcode}")->assertOk();

        $this->assertSame('PPE', $response->json('data.type'));
        $this->assertSame($itemId, $response->json('data.ppe_item_id'));
        // Told before issuing, not after the register refuses them.
        $this->assertTrue($response->json('data.low_stock'));
        $this->assertFalse($response->json('data.out_of_stock'));
    }

    public function test_a_scan_will_not_cross_a_tenant_boundary(): void
    {
        Sanctum::actingAs($this->user);

        $otherCompany = (int) DB::table('companies')->insertGetId([
            'uuid' => (string) Str::uuid(), 'slug' => 'rival-'.Str::random(8),
            'name' => 'Rival Mining', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $barcode = 'PPE-'.Str::random(8);

        $foreignItem = DB::table('sheq_ppe_items')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $otherCompany,
            'code' => 'X-'.Str::random(4), 'name' => 'Their boots', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('sheq_ppe_stock')->insert([
            'uuid' => (string) Str::uuid(), 'company_id' => $otherCompany,
            'ppe_item_id' => $foreignItem, 'quantity_on_hand' => 50, 'barcode' => $barcode,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // Barcodes are printed by whoever owns the shelf and will collide across customers.
        $this->getJson("/api/v1/sheq/mobile/scan/{$barcode}")->assertStatus(404);
    }

    public function test_an_unknown_code_says_what_it_looked_for(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->getJson('/api/v1/sheq/mobile/scan/not-a-real-code')
            ->assertStatus(404);

        // A guard holding a phone needs to know the scan worked and the code is simply not
        // registered — not to wonder whether the camera read it.
        $this->assertStringContainsString('PPE stock', $response->json('detail'));
    }

    public function test_issuing_from_a_scan_applies_the_same_rules_as_the_web_form(): void
    {
        Sanctum::actingAs($this->user);

        $itemId = DB::table('sheq_ppe_items')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'code' => 'HAT-'.Str::random(4), 'name' => 'Hard hat',
            'lifespan_months' => 60, 'requires_size' => false, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('sheq_ppe_stock')->insert([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'ppe_item_id' => $itemId, 'quantity_on_hand' => 1, 'reorder_level' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $personId = DB::table('hr_people')->insertGetId([
            'company_id' => $this->companyId, 'first_name' => 'Scan',
            'surname' => 'Recipient'.Str::random(4),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->postJson('/api/v1/sheq/mobile/ppe/issue', [
            'ppe_item_id' => $itemId, 'hr_person_id' => $personId,
        ])->assertStatus(201);

        // Stock decremented and the replacement date computed by the same service — the rules
        // live there rather than in either screen.
        $this->assertSame(0, (int) DB::table('sheq_ppe_stock')
            ->where('ppe_item_id', $itemId)->value('quantity_on_hand'));

        $this->postJson('/api/v1/sheq/mobile/ppe/issue', [
            'ppe_item_id' => $itemId, 'hr_person_id' => $personId,
        ])->assertStatus(422);
    }

    public function test_a_refused_business_rule_is_a_422_not_a_server_error(): void
    {
        Sanctum::actingAs($this->user);

        // A refusal reported as 500 makes a client retry and sends support looking for an outage
        // that never happened.
        $response = $this->postJson('/api/v1/sheq/mobile/ppe/issue', [
            'ppe_item_id' => 999999, 'hr_person_id' => 1,
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('rule-refused', $response->json('type'));
        // The message is written for a person, so it is safe and useful to show.
        $this->assertNotEmpty($response->json('detail'));
    }

    // ── OpenAPI ───────────────────────────────────────────────────────────────────────────────

    public function test_the_openapi_description_covers_every_published_resource(): void
    {
        Sanctum::actingAs($this->user);

        $spec = $this->getJson('/api/v1/sheq/openapi.json')->assertOk()->json();

        $this->assertSame('3.1.0', $spec['openapi']);

        $catalogue = $this->getJson('/api/v1/sheq/catalogue')->json('data');

        // Generated from the same declarations the controller reads, so this can only fail if the
        // two ever stop sharing a source — which is the drift the generation exists to prevent.
        foreach ($catalogue as $resource) {
            $this->assertArrayHasKey('/'.$resource['resource'], $spec['paths'],
                "{$resource['resource']} is served but undocumented.");
        }
    }

    public function test_the_openapi_description_lists_the_published_events(): void
    {
        Sanctum::actingAs($this->user);

        $spec = $this->getJson('/api/v1/sheq/openapi.json')->assertOk()->json();
        $types = array_column($spec['x-events'], 'type');

        $this->assertContains('sheq.incident.reported', $types);
        $this->assertContains('sheq.permit.expired', $types);

        foreach ($spec['x-events'] as $event) {
            $this->assertNotEmpty($event['payload_keys'],
                "{$event['type']} is published with no documented payload keys.");
        }
    }

    public function test_the_error_contract_is_documented(): void
    {
        Sanctum::actingAs($this->user);

        $spec = $this->getJson('/api/v1/sheq/openapi.json')->assertOk()->json();

        $this->assertArrayHasKey('Problem', $spec['components']['schemas']);
        $this->assertContains('type', $spec['components']['schemas']['Problem']['required']);
        // Collapse the wrapping before matching — the phrase spans a line break in the source.
        $description = preg_replace('/\s+/', ' ', $spec['info']['description']);

        $this->assertStringContainsString('raw request body', $description,
            'The signature footgun must be documented where an integrator will read it.');
    }
}
