<?php

namespace Tests\Feature\Sheq;

use App\Modules\Operations\Sheq\Sync\SyncRegistry;
use App\Modules\Operations\Sheq\Sync\SyncService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Offline child collections (REG-FIX-8).
 *
 * The registry used to say this out loud: witness statements, evidence and causes were left out
 * because child-collection sync did not exist, and listing them would have been a promise the
 * merge could not keep.
 *
 * They are the collections that matter most in the field, because they are the ones two people
 * genuinely fill in at the same incident at the same time. At a serious injury one person is with
 * the casualty and another is taking statements at the gate; two devices each holding half the
 * statements is the normal case, not an edge case. Record-level merge cannot carry that — it would
 * throw one half away and report success.
 *
 * The single most important test in this file is
 * `test_pushing_from_one_device_does_not_delete_the_other_devices_statements`. "Sync the
 * collection" reads naturally as "make the server match the client", and implementing it that way
 * deletes the other device's evidence the moment the first one reconnects — and the deletion looks
 * exactly like a successful sync.
 *
 * Evidence is deliberately not here: it already has a better path than a merge could offer, the
 * chunked resumable upload at `api/v1/sheq/files`, which carries `client_id`, `device_id`,
 * `chunks_received` and `upload_status`.
 */
class SyncChildCollectionTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;

    private int $userId;

    private int $siteId;

    private int $incidentId;

    private string $incidentUuid;

    protected function setUp(): void
    {
        parent::setUp();

        $companyId = DB::table('companies')->min('id');

        if ($companyId === null) {
            $this->markTestSkipped('No company in the test database.');
        }

        $this->companyId = (int) $companyId;

        $this->userId = (int) DB::table('users')->insertGetId([
            'uuid' => (string) Str::uuid(), 'email' => 'child-'.Str::random(8).'@example.test',
            'password' => bcrypt('secret'), 'company_id' => $this->companyId, 'status' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->siteId = (int) DB::table('sheq_sites')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'code' => 'S'.Str::upper(Str::random(5)), 'name' => 'Shaft 4',
            'site_type' => 'MINE', 'timezone' => 'Africa/Johannesburg', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->incidentUuid = (string) Str::uuid();

        $this->incidentId = (int) DB::table('sheq_incidents')->insertGetId([
            'uuid' => $this->incidentUuid, 'company_id' => $this->companyId,
            'reference' => 'INC-'.Str::upper(Str::random(8)), 'site_id' => $this->siteId,
            'incident_type' => 'INJURY', 'actual_severity' => 4, 'potential_severity' => 5,
            'occurred_at' => now()->subHours(3), 'reported_at' => now()->subHours(2),
            'description' => 'Fall from scaffold.', 'status' => 'REPORTED',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function service(): SyncService
    {
        return app(SyncService::class);
    }

    private function clientId(int $offset = 0): string
    {
        return sprintf('0192%04x-%04x-7000-8000-%012x',
            $offset, mt_rand(0, 0xFFFF), mt_rand(0, 0xFFFFFFFFFFFF));
    }

    /**
     * @param  array<string, list<array<string,mixed>>>  $children
     *
     * The payload moves the description on purpose. An UPDATE that changes nothing writes nothing
     * and therefore records no sync change — so a pull would find the incident absent and the
     * child assertions would be testing the wrong thing.
     */
    private function push(array $children, int $offset = 0): array
    {
        $current = (string) DB::table('sheq_incidents')
            ->where('id', $this->incidentId)->value('description');

        return $this->service()->push($this->companyId, [[
            'client_id' => $this->clientId($offset),
            'entity_type' => 'INCIDENT',
            'operation' => 'UPDATE',
            'entity_uuid' => $this->incidentUuid,
            'base' => ['description' => $current],
            'payload' => ['description' => $current.' Noted at '.$offset.'.'],
            'children' => $children,
        ]], $this->userId, 'device-1')['results'][0];
    }

    // ── The collections are declared ──────────────────────────────────────────────────────────

    public function test_the_two_collections_the_requirement_names_are_declared(): void
    {
        $spec = app(SyncRegistry::class)->get('INCIDENT');

        $names = array_map(fn ($c) => $c->name, $spec->childCollections);

        $this->assertContains('people', $names,
            'REG-FIX-8 names witnesses and nothing syncs them.');
        $this->assertContains('causes', $names,
            'REG-FIX-8 names causes and nothing syncs them.');
    }

    public function test_a_device_cannot_claim_a_statement_was_signed(): void
    {
        /*
         * `statement_signed_at` is absent from the writable list on purpose. It is a fact about a
         * person putting their name to something, and a device that could assert it could
         * manufacture signed evidence while offline with nobody present.
         */
        $child = app(SyncRegistry::class)->get('INCIDENT')->childCollection('people');

        $this->assertNotContains('statement_signed_at', $child->writable);

        $stripped = $child->only([
            'role' => 'WITNESS', 'external_name' => 'Thabo Ndlovu',
            'statement_signed_at' => now()->toDateTimeString(),
            'company_id' => 999, 'id' => 5,
        ]);

        $this->assertArrayNotHasKey('statement_signed_at', $stripped);
        $this->assertArrayNotHasKey('company_id', $stripped,
            'A device can set the company id on a child row, which is a cross-tenant write.');
        $this->assertArrayNotHasKey('id', $stripped);
    }

    // ── Witness statements ────────────────────────────────────────────────────────────────────

    public function test_statements_taken_offline_arrive(): void
    {
        $result = $this->push(['people' => [
            ['uuid' => (string) Str::uuid(), 'role' => 'WITNESS',
                'external_name' => 'Thabo Ndlovu', 'statement' => 'I saw the plank give way.'],
            ['uuid' => (string) Str::uuid(), 'role' => 'INJURED',
                'external_name' => 'Sipho Khumalo', 'injury_nature' => 'Fractured wrist',
                'body_part' => 'Left wrist'],
        ]]);

        $this->assertSame('APPLIED', $result['children']['people']['status']);
        $this->assertSame(2, $result['children']['people']['inserted']);

        $this->assertSame(2, DB::table('sheq_incident_people')
            ->where('company_id', $this->companyId)
            ->where('incident_id', $this->incidentId)->count());
    }

    public function test_a_retried_push_does_not_duplicate_a_statement(): void
    {
        $uuid = (string) Str::uuid();

        $rows = ['people' => [
            ['uuid' => $uuid, 'role' => 'WITNESS', 'external_name' => 'Thabo Ndlovu',
                'statement' => 'I saw the plank give way.'],
        ]];

        $this->push($rows, 1);
        $second = $this->push($rows, 2);
        $third = $this->push($rows, 3);

        $this->assertSame(0, $second['children']['people']['inserted']);
        $this->assertSame(1, $second['children']['people']['skipped']);
        $this->assertSame(1, $third['children']['people']['skipped']);

        $this->assertSame(1, DB::table('sheq_incident_people')
            ->where('incident_id', $this->incidentId)->count(),
            'A device on a bad connection retried its push and the incident now has three copies '
            .'of the same witness statement.');
    }

    public function test_pushing_from_one_device_does_not_delete_the_other_devices_statements(): void
    {
        /*
         * The one that would be catastrophic. "Sync the collection" reads as "make the server
         * match the client", and doing that deletes the other device's statements the moment the
         * first one reconnects — and the deletion looks exactly like a successful sync.
         *
         * At a serious injury this is the normal case: one person is with the casualty, another
         * is taking statements at the gate, and both are offline.
         */
        $fromGate = (string) Str::uuid();
        $fromCasualty = (string) Str::uuid();

        $this->push(['people' => [
            ['uuid' => $fromGate, 'role' => 'WITNESS', 'external_name' => 'Gate Witness',
                'statement' => 'Taken at the gate.'],
        ]], 1);

        // The second device reconnects. It has never seen the first device's statement.
        $this->push(['people' => [
            ['uuid' => $fromCasualty, 'role' => 'INJURED', 'external_name' => 'The Casualty',
                'injury_nature' => 'Fractured wrist'],
        ]], 2);

        $names = DB::table('sheq_incident_people')
            ->where('incident_id', $this->incidentId)->pluck('external_name')->all();

        $this->assertContains('Gate Witness', $names,
            'The second device\'s push deleted the statement taken on the first one. Both were '
            .'offline, neither was wrong, and the evidence is gone.');
        $this->assertContains('The Casualty', $names);
        $this->assertCount(2, $names);
    }

    public function test_a_statement_signed_on_the_server_is_not_overwritten_by_the_phones_copy(): void
    {
        $uuid = (string) Str::uuid();

        $this->push(['people' => [
            ['uuid' => $uuid, 'role' => 'WITNESS', 'external_name' => 'Thabo Ndlovu',
                'statement' => 'First draft, unsigned.'],
        ]], 1);

        // Somebody reads it back to the witness and they sign it.
        DB::table('sheq_incident_people')->where('uuid', $uuid)->update([
            'statement_signed_at' => now(),
            'updated_at' => now(),
        ]);

        // The phone, still holding its unsigned copy, pushes again.
        $this->push(['people' => [
            ['uuid' => $uuid, 'role' => 'WITNESS', 'external_name' => 'Thabo Ndlovu',
                'statement' => 'First draft, unsigned.'],
        ]], 2);

        $this->assertNotNull(DB::table('sheq_incident_people')->where('uuid', $uuid)
            ->value('statement_signed_at'),
            'A phone reconnecting un-signed a statement a witness had signed, which destroys the '
            .'evidential value of the whole statement.');
    }

    public function test_a_row_with_no_client_uuid_is_refused_with_a_reason(): void
    {
        /*
         * Without a client uuid there is no way to tell a retry from a new statement, so the
         * choice is between duplicating evidence and discarding it. Neither is acceptable
         * silently.
         */
        $result = $this->push(['people' => [
            ['role' => 'WITNESS', 'external_name' => 'No Uuid'],
        ]]);

        $this->assertSame('PARTIAL', $result['children']['people']['status']);
        $this->assertSame(0, $result['children']['people']['inserted']);
        $this->assertStringContainsString('uuid is required',
            $result['children']['people']['rejections'][0]['reason']);
    }

    public function test_a_person_with_neither_a_name_nor_a_record_is_refused(): void
    {
        $result = $this->push(['people' => [
            ['uuid' => (string) Str::uuid(), 'role' => 'WITNESS',
                'statement' => 'Somebody said something.'],
        ]]);

        $this->assertSame(0, $result['children']['people']['inserted']);
        $this->assertStringContainsString('needs a name',
            $result['children']['people']['rejections'][0]['reason'],
            'A witness with no name was inserted, which puts a blank line in the statement list '
            .'of a serious incident.');
    }

    public function test_a_witness_statement_is_encrypted_at_rest(): void
    {
        /*
         * A row arriving through the sync endpoint must not be the one copy stored in clear.
         * Witness statements and injury detail are POPIA s26 special personal information, and
         * the online path already encrypts them.
         */
        $this->push(['people' => [
            ['uuid' => (string) Str::uuid(), 'role' => 'WITNESS',
                'external_name' => 'Thabo Ndlovu',
                'statement' => 'A very distinctive sentence about the plank.'],
        ]]);

        $raw = (string) DB::table('sheq_incident_people')
            ->where('incident_id', $this->incidentId)->value('statement');

        $this->assertNotSame('', $raw);
        $this->assertStringNotContainsString('distinctive sentence', $raw,
            'The witness statement is stored in clear, so a sync push is the way round the '
            .'column-level encryption the online path applies.');
    }

    // ── Causes ────────────────────────────────────────────────────────────────────────────────

    public function test_causes_need_an_investigation_and_say_so_rather_than_vanishing(): void
    {
        /*
         * Causes hang off `sheq_investigations`, so a device syncing an incident has no
         * investigation id. Refusing with a reason — and telling the device to keep the rows —
         * is the whole point: silently discarding a root cause somebody typed at a scene is
         * exactly the data loss this requirement is about.
         */
        $result = $this->push(['causes' => [
            ['uuid' => (string) Str::uuid(), 'description' => 'Scaffold was not inspected.',
                'cause_type' => 'ROOT', 'category' => 'ROOT'],
        ]]);

        $this->assertSame('NO_PARENT', $result['children']['causes']['status']);
        $this->assertStringContainsString('not lost',
            $result['children']['causes']['reason'],
            'The device is not told to keep the rows, so it will discard them as applied.');
    }

    public function test_causes_attach_once_an_investigation_is_open(): void
    {
        $investigationId = (int) DB::table('sheq_investigations')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'incident_id' => $this->incidentId, 'status' => 'OPEN',
            'method' => 'FIVE_WHYS', 'opened_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $result = $this->push(['causes' => [
            ['uuid' => (string) Str::uuid(), 'description' => 'Scaffold was not inspected.',
                'cause_type' => 'ROOT', 'category' => 'ROOT', 'depth' => 3],
        ]]);

        $this->assertSame('APPLIED', $result['children']['causes']['status']);
        $this->assertSame(1, $result['children']['causes']['inserted']);

        $this->assertSame($investigationId, (int) DB::table('sheq_investigation_causes')
            ->where('company_id', $this->companyId)->value('investigation_id'),
            'The cause did not attach to the incident\'s own investigation.');
    }

    public function test_a_cause_with_no_description_is_refused(): void
    {
        DB::table('sheq_investigations')->insert([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'incident_id' => $this->incidentId, 'status' => 'OPEN',
            'method' => 'FIVE_WHYS', 'opened_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $result = $this->push(['causes' => [
            ['uuid' => (string) Str::uuid(), 'cause_type' => 'ROOT'],
        ]]);

        $this->assertSame(0, $result['children']['causes']['inserted']);
        $this->assertStringContainsString('description',
            $result['children']['causes']['rejections'][0]['reason']);
    }

    // ── Behaviour of the path itself ──────────────────────────────────────────────────────────

    public function test_an_unknown_collection_is_named_rather_than_ignored(): void
    {
        /*
         * A client pushing a collection this version does not support is a version mismatch, and
         * silence would let a device believe for months that its rows were arriving.
         */
        $result = $this->push(['sketches' => [
            ['uuid' => (string) Str::uuid(), 'description' => 'A drawing.'],
        ]]);

        $this->assertSame('UNSUPPORTED', $result['children']['sketches']['status']);
        $this->assertSame(1, $result['children']['sketches']['rejected']);
        $this->assertStringContainsString('not a syncable collection',
            $result['children']['sketches']['reason']);
    }

    public function test_children_arrive_even_when_the_record_merge_conflicts(): void
    {
        /*
         * A witness statement is not in conflict with anything — it is a thing that happened.
         * Withholding it because two devices disagreed about the incident's description would
         * lose evidence over an editorial dispute.
         */
        $uuid = (string) Str::uuid();

        $result = $this->service()->push($this->companyId, [[
            'client_id' => $this->clientId(1),
            'entity_type' => 'INCIDENT',
            'operation' => 'UPDATE',
            'entity_uuid' => $this->incidentUuid,
            // A stale base against a server value that has moved: the classic conflict.
            'base' => ['description' => 'Something else entirely.'],
            'payload' => ['description' => 'The device\'s version.'],
            'children' => ['people' => [
                ['uuid' => $uuid, 'role' => 'WITNESS', 'external_name' => 'Thabo Ndlovu',
                    'statement' => 'I saw the plank give way.'],
            ]],
        ]], $this->userId, 'device-1')['results'][0];

        $this->assertSame(1, $result['children']['people']['inserted'],
            'The statement was withheld because the incident description conflicted, so evidence '
            .'is lost to an editorial dispute.');

        $this->assertTrue(DB::table('sheq_incident_people')->where('uuid', $uuid)->exists());
    }

    public function test_a_push_with_no_children_behaves_exactly_as_before(): void
    {
        // The collection key is optional. Every existing client has to keep working.
        $result = $this->service()->push($this->companyId, [[
            'client_id' => $this->clientId(),
            'entity_type' => 'INCIDENT',
            'operation' => 'UPDATE',
            'entity_uuid' => $this->incidentUuid,
            'base' => ['description' => 'Fall from scaffold.'],
            'payload' => ['description' => 'Fall from the third lift of the scaffold.'],
        ]], $this->userId)['results'][0];

        $this->assertSame('APPLIED', $result['status']);
        $this->assertArrayNotHasKey('children', $result);
    }

    // ── The pull side ─────────────────────────────────────────────────────────────────────────

    public function test_a_second_device_learns_a_statement_exists_without_reading_it(): void
    {
        /*
         * The privacy line this sync has to hold. A second device needs to know a statement
         * EXISTS — so it does not take the same one again, and so its copy of the incident is not
         * misleadingly short. It does not need the text, which is POPIA s26 special personal
         * information encrypted at column level.
         */
        $this->push(['people' => [
            ['uuid' => (string) Str::uuid(), 'role' => 'WITNESS',
                'external_name' => 'Thabo Ndlovu',
                'statement' => 'A very distinctive sentence about the plank.',
                'external_contact' => '082 555 1234'],
        ]]);

        $pull = $this->service()->pull($this->companyId, [], [$this->siteId]);

        $change = collect($pull['entities']['INCIDENT']['changes'])
            ->firstWhere('entity_uuid', $this->incidentUuid);

        $this->assertNotNull($change, 'The incident did not come back on the pull at all.');

        $people = $change['children']['people'] ?? [];

        $this->assertCount(1, $people,
            'The second device cannot see that a statement was taken, so it will take the same '
            .'one again and the incident will carry two versions of one account.');

        $row = $people[0];

        // Readable: enough to render "statement taken from Thabo Ndlovu".
        $this->assertSame('Thabo Ndlovu', $row['external_name']);
        $this->assertSame('WITNESS', $row['role']);

        // Withheld.
        $this->assertArrayNotHasKey('statement', $row,
            'The witness statement was sent to a device, so a stolen phone carries somebody '
            .'else\'s account of a serious injury.');
        $this->assertArrayNotHasKey('injury_nature', $row);
        $this->assertArrayNotHasKey('external_contact', $row);

        // And said rather than silently absent, so a blank field is not mistaken for a statement
        // nobody wrote down.
        $this->assertTrue($row['statement_present']);
        $this->assertContains('statement', $row['redacted']);
    }

    public function test_the_pull_says_when_no_statement_was_actually_taken(): void
    {
        // A person recorded with no statement is a different fact from a statement withheld, and
        // the device has to be able to tell them apart.
        $this->push(['people' => [
            ['uuid' => (string) Str::uuid(), 'role' => 'WITNESS',
                'external_name' => 'Saw Nothing'],
        ]]);

        $change = collect($this->service()->pull($this->companyId, [], [$this->siteId])
            ['entities']['INCIDENT']['changes'])
            ->firstWhere('entity_uuid', $this->incidentUuid);

        $this->assertFalse($change['children']['people'][0]['statement_present']);
    }

    public function test_a_record_with_no_children_pulls_a_null_rather_than_an_empty_shape(): void
    {
        $this->service()->push($this->companyId, [[
            'client_id' => $this->clientId(),
            'entity_type' => 'INCIDENT', 'operation' => 'UPDATE',
            'entity_uuid' => $this->incidentUuid,
            'base' => ['description' => 'Fall from scaffold.'],
            'payload' => ['description' => 'Fall from the third lift.'],
        ]], $this->userId);

        $change = collect($this->service()->pull($this->companyId, [], [$this->siteId])
            ['entities']['INCIDENT']['changes'])
            ->firstWhere('entity_uuid', $this->incidentUuid);

        $this->assertNull($change['children']);
    }

    public function test_another_tenants_row_with_the_same_uuid_does_not_block_an_insert(): void
    {
        /*
         * The duplicate check is scoped by company. Without that, one tenant's uuid collision
         * would silently swallow another tenant's witness statement — and the sync would report
         * it as skipped.
         */
        $other = (int) DB::table('companies')->where('id', '!=', $this->companyId)->min('id');

        if ($other === 0) {
            $this->markTestSkipped('Only one company in the test database.');
        }

        $uuid = (string) Str::uuid();

        DB::table('sheq_incident_people')->insert([
            'uuid' => $uuid, 'company_id' => $other, 'incident_id' => $this->incidentId,
            'role' => 'WITNESS', 'external_name' => 'Theirs',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $result = $this->push(['people' => [
            ['uuid' => $uuid, 'role' => 'WITNESS', 'external_name' => 'Ours'],
        ]]);

        $this->assertSame(1, $result['children']['people']['inserted'],
            'Another tenant holding the same uuid caused this tenant\'s statement to be reported '
            .'as a duplicate and dropped.');
    }
}
