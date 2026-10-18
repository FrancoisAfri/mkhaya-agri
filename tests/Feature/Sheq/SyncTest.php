<?php

namespace Tests\Feature\Sheq;

use App\Modules\Operations\Sheq\Sync\SyncService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Offline sync, server side (§6.1).
 *
 * The acceptance criterion in the delivery plan is blunt: "a worker reports an incident with three
 * photos in aeroplane mode; it lands correctly on reconnect; zero data loss". These tests cover the
 * record half of that — idempotent replay, delta cursors, field-level merge through the real
 * database, server-side transition re-validation, and the conflict queue.
 */
class SyncTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;

    private int $userId;

    private int $siteId;

    protected function setUp(): void
    {
        parent::setUp();

        $companyId = DB::table('companies')->min('id');
        if ($companyId === null) {
            $this->markTestSkipped('No company in the test database.');
        }
        $this->companyId = (int) $companyId;

        $this->userId = DB::table('users')->insertGetId([
            'uuid' => (string) Str::uuid(), 'email' => 'sync-'.Str::random(8).'@example.test',
            'password' => bcrypt('secret'), 'company_id' => $this->companyId, 'status' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->siteId = DB::table('sheq_sites')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'code' => 'S'.Str::random(6), 'name' => 'Shaft 4', 'site_type' => 'SITE',
            'timezone' => 'Africa/Johannesburg', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function service(): SyncService
    {
        return app(SyncService::class);
    }

    /** UUIDv7-ish: time-ordered, which is what makes a device's queue replay in the right order. */
    private function clientId(int $offset = 0): string
    {
        return sprintf('0192%04x-%04x-7000-8000-%012x',
            $offset, mt_rand(0, 0xFFFF), mt_rand(0, 0xFFFFFFFFFFFF));
    }

    private function hazard(array $overrides = []): array
    {
        $uuid = (string) Str::uuid();

        $id = DB::table('sheq_hazards')->insertGetId(array_merge([
            'uuid' => $uuid, 'company_id' => $this->companyId,
            'reference' => 'HAZ-'.Str::random(6), 'site_id' => $this->siteId,
            'description' => 'Loose handrail on the walkway.',
            'hazard_category' => 'STRUCTURAL', 'status' => 'OPEN',
            'reported_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ], $overrides));

        return ['id' => $id, 'uuid' => $uuid];
    }

    // ── Idempotency (SHEQ-OFF-2) ────────────────────────────────────────────────────────────────

    public function test_replaying_an_operation_is_a_no_op_returning_the_original_result(): void
    {
        // A device that loses signal mid-push retries its whole queue. The second attempt must
        // change nothing at all.
        $hazard = $this->hazard();
        $clientId = $this->clientId();

        $operation = [
            'client_id' => $clientId, 'entity_type' => 'HAZARD', 'operation' => 'UPDATE',
            'entity_uuid' => $hazard['uuid'],
            'base' => ['description' => 'Loose handrail on the walkway.'],
            'payload' => ['description' => 'Handrail has come away completely.'],
        ];

        $first = $this->service()->push($this->companyId, [$operation], $this->userId);
        $second = $this->service()->push($this->companyId, [$operation], $this->userId);

        $this->assertSame('APPLIED', $first['results'][0]['status']);
        $this->assertTrue($second['results'][0]['replayed']);

        $this->assertSame(1, DB::table('sheq_sync_operations')
            ->where('company_id', $this->companyId)->where('client_id', $clientId)->count());

        // And exactly one change entry — a replay must not move the cursor for everyone else.
        $this->assertSame(1, DB::table('sheq_sync_changes')
            ->where('company_id', $this->companyId)
            ->where('entity_uuid', $hazard['uuid'])->count());
    }

    public function test_an_operation_without_a_client_id_is_refused(): void
    {
        $result = $this->service()->push($this->companyId, [
            ['entity_type' => 'HAZARD', 'operation' => 'UPDATE', 'payload' => []],
        ], $this->userId);

        $this->assertSame('REJECTED', $result['results'][0]['status']);
        $this->assertStringContainsString('idempotency key', $result['results'][0]['reason']);
    }

    public function test_a_create_from_the_field_goes_through_the_domain_service(): void
    {
        /**
         * The record must come out with a reference, because the domain service generated one. A
         * raw insert from the sync endpoint would produce a hazard with no reference — and for an
         * incident, no statutory classification and no notification deadline.
         *
         * The device supplies its own id and adopts the server's uuid from the response.
         */
        $deviceUuid = (string) Str::uuid();

        $result = $this->service()->push($this->companyId, [[
            'client_id' => $this->clientId(), 'entity_type' => 'HAZARD', 'operation' => 'CREATE',
            'entity_uuid' => $deviceUuid,
            'payload' => [
                'description' => 'Reported from the pit with no signal.',
                'hazard_category' => 'HOUSEKEEPING', 'site_id' => $this->siteId,
            ],
        ]], $this->userId);

        $this->assertSame('APPLIED', $result['results'][0]['status']);
        $this->assertTrue($result['results'][0]['created']);
        $this->assertNotEmpty($result['results'][0]['reference'],
            'The worker must get back the reference the office will use.');

        $this->assertDatabaseHas('sheq_hazards', [
            'uuid' => $result['results'][0]['entity_uuid'],
            'company_id' => $this->companyId,
            'description' => 'Reported from the pit with no signal.',
        ]);
    }

    public function test_a_retried_create_does_not_file_the_hazard_twice(): void
    {
        // The device pushed, lost signal before the response, and pushed again.
        $deviceUuid = (string) Str::uuid();

        $operation = [
            'client_id' => $this->clientId(), 'entity_type' => 'HAZARD', 'operation' => 'CREATE',
            'entity_uuid' => $deviceUuid,
            'payload' => [
                'description' => 'Duplicate guard.', 'hazard_category' => 'HOUSEKEEPING',
                'site_id' => $this->siteId,
            ],
        ];

        $this->service()->push($this->companyId, [$operation], $this->userId);
        $this->service()->push($this->companyId, [$operation], $this->userId);

        $this->assertSame(1, DB::table('sheq_hazards')
            ->where('company_id', $this->companyId)
            ->where('description', 'Duplicate guard.')->count());
    }

    // ── Merge through the real database (SHEQ-OFF-4) ────────────────────────────────────────────

    public function test_two_devices_editing_different_fields_both_win(): void
    {
        // The everyday case that record-level merge gets wrong.
        $hazard = $this->hazard(['status' => 'TRIAGED']);
        $base = ['description' => 'Loose handrail on the walkway.', 'location_text' => null];

        DB::table('sheq_hazards')->where('id', $hazard['id'])
            ->update(['location_text' => 'Level 3 walkway']);

        $result = $this->service()->push($this->companyId, [[
            'client_id' => $this->clientId(), 'entity_type' => 'HAZARD', 'operation' => 'UPDATE',
            'entity_uuid' => $hazard['uuid'], 'base' => $base,
            'payload' => ['description' => 'Handrail detached at the bracket.'],
        ]], $this->userId);

        $this->assertSame('APPLIED', $result['results'][0]['status']);

        $row = DB::table('sheq_hazards')->find($hazard['id']);
        $this->assertSame('Handrail detached at the bracket.', $row->description);
        $this->assertSame('Level 3 walkway', $row->location_text, "The server's edit survived too.");
    }

    public function test_a_contested_field_beyond_draft_queues_a_conflict_holding_both_values(): void
    {
        $hazard = $this->hazard(['status' => 'TRIAGED', 'description' => 'Supervisor wording.']);

        $result = $this->service()->push($this->companyId, [[
            'client_id' => $this->clientId(), 'entity_type' => 'HAZARD', 'operation' => 'UPDATE',
            'entity_uuid' => $hazard['uuid'],
            'base' => ['description' => 'Loose handrail on the walkway.'],
            'payload' => ['description' => 'What the worker actually typed.'],
        ]], $this->userId);

        $this->assertSame('CONFLICT', $result['results'][0]['status']);

        $conflicts = $this->service()->conflicts($this->companyId);
        $this->assertCount(1, $conflicts);
        $this->assertSame('description', $conflicts[0]['field']);
        $this->assertSame('Supervisor wording.', $conflicts[0]['server_value']);
        $this->assertSame('What the worker actually typed.', $conflicts[0]['client_value']);

        // Server value stands until someone decides.
        $this->assertSame('Supervisor wording.',
            DB::table('sheq_hazards')->find($hazard['id'])->description);
    }

    public function test_resolving_a_conflict_in_the_clients_favour_writes_their_value_through(): void
    {
        $hazard = $this->hazard(['status' => 'TRIAGED', 'description' => 'Supervisor wording.']);

        $this->service()->push($this->companyId, [[
            'client_id' => $this->clientId(), 'entity_type' => 'HAZARD', 'operation' => 'UPDATE',
            'entity_uuid' => $hazard['uuid'],
            'base' => ['description' => 'Loose handrail on the walkway.'],
            'payload' => ['description' => 'What the worker actually typed.'],
        ]], $this->userId);

        $conflict = $this->service()->conflicts($this->companyId)[0];

        $this->service()->resolveConflict($this->companyId, $conflict['uuid'], 'TOOK_CLIENT',
            $this->userId, 'The worker was on site; the supervisor was not.');

        $this->assertSame('What the worker actually typed.',
            DB::table('sheq_hazards')->find($hazard['id'])->description);
        $this->assertSame([], $this->service()->conflicts($this->companyId));
    }

    public function test_a_resolved_conflict_keeps_both_values_on_record(): void
    {
        // The audit question is not just what won, but what was discarded.
        $hazard = $this->hazard(['status' => 'TRIAGED', 'description' => 'Supervisor wording.']);

        $this->service()->push($this->companyId, [[
            'client_id' => $this->clientId(), 'entity_type' => 'HAZARD', 'operation' => 'UPDATE',
            'entity_uuid' => $hazard['uuid'],
            'base' => ['description' => 'Loose handrail on the walkway.'],
            'payload' => ['description' => 'What the worker actually typed.'],
        ]], $this->userId);

        $conflict = $this->service()->conflicts($this->companyId)[0];
        $this->service()->resolveConflict($this->companyId, $conflict['uuid'], 'TOOK_SERVER', $this->userId);

        $row = DB::table('sheq_sync_conflicts')->where('uuid', $conflict['uuid'])->first();
        $this->assertSame('RESOLVED', $row->status);
        $this->assertSame('TOOK_SERVER', $row->resolution);
        $this->assertSame('"What the worker actually typed."', $row->client_value,
            'The discarded value must remain readable after resolution.');
    }

    public function test_editing_a_record_the_server_deleted_resurrects_it_as_a_conflict(): void
    {
        $result = $this->service()->push($this->companyId, [[
            'client_id' => $this->clientId(), 'entity_type' => 'HAZARD', 'operation' => 'UPDATE',
            'entity_uuid' => (string) Str::uuid(),          // never existed / already purged
            'base' => ['description' => 'original'],
            'payload' => ['description' => 'edited while offline'],
        ]], $this->userId);

        $this->assertSame('CONFLICT', $result['results'][0]['status']);
        $this->assertTrue($result['results'][0]['resurrected']);
        $this->assertSame('edited while offline',
            $this->service()->conflicts($this->companyId)[0]['client_value']);
    }

    // ── Transition re-validation (SHEQ-OFF-6) ───────────────────────────────────────────────────

    public function test_a_valid_transition_is_applied(): void
    {
        $hazard = $this->hazard(['status' => 'OPEN']);

        $result = $this->service()->push($this->companyId, [[
            'client_id' => $this->clientId(), 'entity_type' => 'HAZARD', 'operation' => 'TRANSITION',
            'entity_uuid' => $hazard['uuid'], 'payload' => ['to_status' => 'TRIAGED'],
        ]], $this->userId);

        $this->assertSame('APPLIED', $result['results'][0]['status']);
        $this->assertSame('TRIAGED', DB::table('sheq_hazards')->find($hazard['id'])->status);
    }

    public function test_an_invalid_transition_is_refused_and_the_clients_data_is_retained(): void
    {
        /**
         * The record moved on while the device was offline. The push is refused — but the payload
         * comes back with the explanation, because rejecting a transition must not also throw away
         * what the user typed.
         */
        $hazard = $this->hazard(['status' => 'CLOSED']);

        $result = $this->service()->push($this->companyId, [[
            'client_id' => $this->clientId(), 'entity_type' => 'HAZARD', 'operation' => 'TRANSITION',
            'entity_uuid' => $hazard['uuid'],
            'payload' => ['to_status' => 'TRIAGED', 'notes' => 'Fixed it myself.'],
        ]], $this->userId);

        $this->assertSame('REJECTED', $result['results'][0]['status']);
        $this->assertStringContainsString('cannot move from CLOSED', $result['results'][0]['reason']);
        $this->assertSame('CLOSED', $result['results'][0]['server_status']);
        $this->assertSame('Fixed it myself.',
            $result['results'][0]['retained_payload']['notes'],
            'A rejected transition must hand the user their data back.');

        $this->assertSame('CLOSED', DB::table('sheq_hazards')->find($hazard['id'])->status);
    }

    public function test_a_client_cannot_move_status_by_pushing_it_as_a_field(): void
    {
        // Otherwise SHEQ-OFF-6's validation is trivially bypassed.
        $hazard = $this->hazard(['status' => 'OPEN']);

        $this->service()->push($this->companyId, [[
            'client_id' => $this->clientId(), 'entity_type' => 'HAZARD', 'operation' => 'UPDATE',
            'entity_uuid' => $hazard['uuid'],
            'base' => ['status' => 'OPEN'],
            'payload' => ['status' => 'CLOSED', 'description' => 'and closed it'],
        ]], $this->userId);

        $this->assertSame('OPEN', DB::table('sheq_hazards')->find($hazard['id'])->status);
    }

    public function test_a_client_cannot_write_a_field_that_is_not_syncable(): void
    {
        /**
         * `statutory_category` is the pack engine's conclusion. A device that could overwrite it
         * could rewrite the tenant's legal position from a phone with no signal.
         */
        $hazard = $this->hazard(['status' => 'OPEN']);

        $this->service()->push($this->companyId, [[
            'client_id' => $this->clientId(), 'entity_type' => 'HAZARD', 'operation' => 'UPDATE',
            'entity_uuid' => $hazard['uuid'],
            'base' => [], 'payload' => ['reference' => 'HAZ-FORGED', 'description' => 'legitimate edit'],
        ]], $this->userId);

        $row = DB::table('sheq_hazards')->find($hazard['id']);
        $this->assertNotSame('HAZ-FORGED', $row->reference);
        $this->assertSame('legitimate edit', $row->description);
    }

    // ── Delta pull (SHEQ-OFF-3) ─────────────────────────────────────────────────────────────────

    public function test_a_pull_returns_only_changes_after_the_clients_cursor(): void
    {
        $first = $this->hazard();
        $this->service()->push($this->companyId, [[
            'client_id' => $this->clientId(1), 'entity_type' => 'HAZARD', 'operation' => 'UPDATE',
            'entity_uuid' => $first['uuid'], 'base' => [], 'payload' => ['description' => 'one'],
        ]], $this->userId);

        $afterFirst = $this->service()->pull($this->companyId);
        $cursor = $afterFirst['entities']['HAZARD']['cursor'];
        $this->assertNotEmpty($afterFirst['entities']['HAZARD']['changes']);

        $second = $this->hazard();
        $this->service()->push($this->companyId, [[
            'client_id' => $this->clientId(2), 'entity_type' => 'HAZARD', 'operation' => 'UPDATE',
            'entity_uuid' => $second['uuid'], 'base' => [], 'payload' => ['description' => 'two'],
        ]], $this->userId);

        $delta = $this->service()->pull($this->companyId, ['HAZARD' => $cursor]);

        $uuids = array_column($delta['entities']['HAZARD']['changes'], 'entity_uuid');
        $this->assertSame([$second['uuid']], $uuids, 'A delta pull must not resend what the client has.');
    }

    public function test_a_pull_is_scoped_to_the_users_sites(): void
    {
        // SHEQ-OFF-1: the local store mirrors the subset scoped to the user's assigned sites.
        $otherSite = DB::table('sheq_sites')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'code' => 'O'.Str::random(6), 'name' => 'Other shaft', 'site_type' => 'SITE',
            'timezone' => 'Africa/Johannesburg', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $mine = $this->hazard(['site_id' => $this->siteId]);
        $theirs = $this->hazard(['site_id' => $otherSite]);

        foreach ([[1, $mine], [2, $theirs]] as [$offset, $hazard]) {
            $this->service()->push($this->companyId, [[
                'client_id' => $this->clientId($offset), 'entity_type' => 'HAZARD',
                'operation' => 'UPDATE', 'entity_uuid' => $hazard['uuid'],
                'base' => [], 'payload' => ['description' => 'edited'],
            ]], $this->userId);
        }

        $pull = $this->service()->pull($this->companyId, [], [$this->siteId]);
        $uuids = array_column($pull['entities']['HAZARD']['changes'], 'entity_uuid');

        $this->assertContains($mine['uuid'], $uuids);
        $this->assertNotContains($theirs['uuid'], $uuids);
    }

    public function test_a_truncated_pull_says_so_rather_than_implying_it_is_current(): void
    {
        // SHEQ-OFF-9: never fake success. A client that thought it was up to date would stop pulling.
        for ($i = 0; $i < 4; $i++) {
            $hazard = $this->hazard();
            $this->service()->push($this->companyId, [[
                'client_id' => $this->clientId($i + 10), 'entity_type' => 'HAZARD',
                'operation' => 'UPDATE', 'entity_uuid' => $hazard['uuid'],
                'base' => [], 'payload' => ['description' => 'edit '.$i],
            ]], $this->userId);
        }

        $pull = $this->service()->pull($this->companyId, [], [], limit: 2);

        $this->assertTrue($pull['entities']['HAZARD']['has_more']);
        $this->assertCount(2, $pull['entities']['HAZARD']['changes']);
    }

    // ── Queue behaviour ─────────────────────────────────────────────────────────────────────────

    public function test_one_bad_operation_does_not_roll_back_the_rest_of_the_queue(): void
    {
        /**
         * A device that has been offline for a week pushes forty operations. If one poisons the
         * whole batch the queue never drains and the worker's week of work is stuck behind it.
         */
        $hazard = $this->hazard();

        $result = $this->service()->push($this->companyId, [
            [
                'client_id' => $this->clientId(1), 'entity_type' => 'HAZARD', 'operation' => 'UPDATE',
                'entity_uuid' => $hazard['uuid'], 'base' => [], 'payload' => ['description' => 'good one'],
            ],
            [
                'client_id' => $this->clientId(2), 'entity_type' => 'NONSENSE', 'operation' => 'UPDATE',
                'entity_uuid' => (string) Str::uuid(), 'payload' => [],
            ],
            [
                'client_id' => $this->clientId(3), 'entity_type' => 'HAZARD', 'operation' => 'UPDATE',
                'entity_uuid' => $hazard['uuid'], 'base' => ['description' => 'good one'],
                'payload' => ['location_text' => 'Level 3'],
            ],
        ], $this->userId);

        $this->assertSame(1, $result['rejected']);
        $this->assertSame(2, $result['applied']);

        $row = DB::table('sheq_hazards')->find($hazard['id']);
        $this->assertSame('good one', $row->description);
        $this->assertSame('Level 3', $row->location_text);
    }

    public function test_a_rejected_operation_is_recorded_rather_than_lost(): void
    {
        $clientId = $this->clientId();

        $this->service()->push($this->companyId, [[
            'client_id' => $clientId, 'entity_type' => 'NONSENSE', 'operation' => 'UPDATE',
            'entity_uuid' => (string) Str::uuid(), 'payload' => ['description' => 'still the users work'],
        ]], $this->userId);

        $row = DB::table('sheq_sync_operations')
            ->where('company_id', $this->companyId)->where('client_id', $clientId)->first();

        $this->assertNotNull($row, 'A rejected operation must still be on record.');
        $this->assertSame('REJECTED', $row->status);
        $this->assertStringContainsString('not a syncable', $row->rejection_reason);
        $this->assertStringContainsString('still the users work', $row->payload);
    }

    public function test_another_tenants_operations_are_never_visible(): void
    {
        $otherCompany = DB::table('companies')->insertGetId([
            'uuid' => (string) Str::uuid(), 'name' => 'Other Tenant',
            'slug' => 'other-sync-'.Str::lower(Str::random(8)),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $hazard = $this->hazard(['status' => 'TRIAGED', 'description' => 'server text']);

        $this->service()->push($this->companyId, [[
            'client_id' => $this->clientId(), 'entity_type' => 'HAZARD', 'operation' => 'UPDATE',
            'entity_uuid' => $hazard['uuid'], 'base' => ['description' => 'base text'],
            'payload' => ['description' => 'client text'],
        ]], $this->userId);

        $this->assertNotEmpty($this->service()->conflicts($this->companyId));
        $this->assertSame([], $this->service()->conflicts($otherCompany));
        $this->assertSame(0, $this->service()->cursor($otherCompany));
    }

    // ── The inspection case ─────────────────────────────────────────────────────────────────────

    public function test_two_devices_answering_different_questions_of_one_inspection_keep_both(): void
    {
        $template = DB::table('sheq_inspection_templates')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'code' => 'T'.Str::random(6), 'title' => 'Monthly walk',
            'schema' => json_encode(['items' => []]), 'status' => 'PUBLISHED',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $uuid = (string) Str::uuid();
        $id = DB::table('sheq_inspections')->insertGetId([
            'uuid' => $uuid, 'company_id' => $this->companyId,
            'reference' => 'INS-'.Str::random(6), 'template_id' => $template,
            'template_version' => 1,
            'site_id' => $this->siteId, 'status' => 'COMPLETED',
            'responses' => json_encode(['q1' => 'yes', 'q12' => 'no']),
            'scheduled_for' => Carbon::today()->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->service()->push($this->companyId, [[
            'client_id' => $this->clientId(), 'entity_type' => 'INSPECTION', 'operation' => 'UPDATE',
            'entity_uuid' => $uuid,
            'base' => ['responses' => ['q1' => 'yes']],
            'payload' => ['responses' => ['q1' => 'yes', 'q88' => 'n/a']],
        ]], $this->userId);

        $responses = json_decode(DB::table('sheq_inspections')->find($id)->responses, true);

        $this->assertSame(['q1' => 'yes', 'q12' => 'no', 'q88' => 'n/a'], $responses,
            'Answers from both devices must survive.');
    }
}
