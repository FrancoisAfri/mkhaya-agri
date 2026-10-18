<?php

namespace Tests\Unit\Sheq;

use App\Modules\Operations\Sheq\Sync\EntitySpec;
use App\Modules\Operations\Sheq\Sync\SyncMerger;
use PHPUnit\Framework\TestCase;

/**
 * Field-level conflict resolution (SHEQ-OFF-4).
 *
 * One test per row of the specification's table, then a chaos suite. The chaos suite is not
 * decoration: §12 names "sync conflicts lose field data" as the risk that kills the product, and
 * §11 requires the conflict paths to be covered. A hand-written example proves the rule I thought
 * of; thousands of generated scenarios prove the invariant I did not.
 */
class SyncMergerTest extends TestCase
{
    private SyncMerger $merger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->merger = new SyncMerger;
    }

    private function spec(string $status = 'DRAFT', array $appendOnly = ['evidence']): EntitySpec
    {
        return new EntitySpec(
            type: 'INCIDENT',
            table: 'sheq_incidents',
            syncable: ['description', 'location_text', 'actual_severity', 'evidence', 'responses'],
            appendOnly: $appendOnly,
            draftStates: ['DRAFT', 'REPORTED'],
            transitions: ['REPORTED' => ['UNDER_INVESTIGATION']],
        )->at($status);
    }

    // ── The SHEQ-OFF-4 table, row by row ────────────────────────────────────────────────────────

    public function test_a_field_changed_on_the_server_only_keeps_the_server_value(): void
    {
        // The client is echoing back what it was handed. Nothing to merge.
        $result = $this->merger->merge(
            base: ['description' => 'original'],
            server: ['description' => 'edited by supervisor'],
            client: ['description' => 'original'],
            spec: $this->spec(),
        );

        $this->assertSame([], $result->values);
        $this->assertFalse($result->hasConflicts());
    }

    public function test_a_field_changed_on_the_client_only_takes_the_client_value(): void
    {
        $result = $this->merger->merge(
            base: ['description' => 'original'],
            server: ['description' => 'original'],
            client: ['description' => 'what the worker actually saw'],
            spec: $this->spec('CLOSED'),
        );

        $this->assertSame('what the worker actually saw', $result->values['description']);
        $this->assertSame(SyncMerger::RULE_CLIENT_ONLY, $result->rules['description']);
    }

    public function test_an_append_only_collection_unions_and_never_drops_either_side(): void
    {
        // Two people photographed the same incident. Both photographs exist.
        $result = $this->merger->merge(
            base: ['evidence' => [['id' => 'a']]],
            server: ['evidence' => [['id' => 'a'], ['id' => 'b']]],
            client: ['evidence' => [['id' => 'a'], ['id' => 'c']]],
            spec: $this->spec('CLOSED'),
        );

        $ids = array_column($result->values['evidence'], 'id');
        $this->assertEqualsCanonicalizing(['a', 'b', 'c'], $ids);
        $this->assertFalse($result->hasConflicts(), 'Adding to a collection is not a conflict.');
    }

    public function test_a_scalar_contested_on_a_draft_takes_the_client_as_author_intent(): void
    {
        $result = $this->merger->merge(
            base: ['description' => 'original'],
            server: ['description' => 'server edit'],
            client: ['description' => 'author rewrite'],
            spec: $this->spec('REPORTED'),
        );

        $this->assertSame('author rewrite', $result->values['description']);
        $this->assertSame(SyncMerger::RULE_DRAFT_AUTHOR_INTENT, $result->rules['description']);
        $this->assertFalse($result->hasConflicts());
    }

    public function test_a_scalar_contested_beyond_draft_keeps_the_server_and_queues_a_conflict(): void
    {
        // The critical row: the server wins, but the client's value is preserved for review.
        $result = $this->merger->merge(
            base: ['description' => 'original'],
            server: ['description' => 'investigator finding'],
            client: ['description' => 'what the worker typed'],
            spec: $this->spec('UNDER_INVESTIGATION'),
        );

        $this->assertArrayNotHasKey('description', $result->values, 'The server value stands.');
        $this->assertCount(1, $result->conflicts);

        $conflict = $result->conflicts[0];
        $this->assertSame('investigator finding', $conflict['server']);
        $this->assertSame('what the worker typed', $conflict['client'],
            "The worker's value must survive into the queue — losing it is the P0 defect.");
    }

    public function test_the_status_field_is_never_taken_from_the_client(): void
    {
        $result = $this->merger->merge(
            base: ['status' => 'REPORTED'],
            server: ['status' => 'REPORTED'],
            client: ['status' => 'CLOSED'],
            spec: $this->spec('REPORTED'),
        );

        $this->assertArrayNotHasKey('status', $result->values);
        $this->assertSame('CLOSED', $result->rejectedStatus);
    }

    public function test_a_record_deleted_on_the_server_resurrects_the_clients_edits_as_conflicts(): void
    {
        $result = $this->merger->resurrect(
            base: ['description' => 'original'],
            client: ['description' => 'edited while offline'],
            spec: $this->spec(),
        );

        $this->assertTrue($result->resurrected);
        $this->assertCount(1, $result->conflicts);
        $this->assertSame('edited while offline', $result->conflicts[0]['client']);
        $this->assertSame(SyncMerger::RULE_RESURRECT, $result->conflicts[0]['rule']);
    }

    // ── Keyed collections: the 200-question inspection ──────────────────────────────────────────

    public function test_two_devices_answering_different_questions_keep_both_answers(): void
    {
        $result = $this->merger->merge(
            base: ['responses' => ['q1' => 'yes']],
            server: ['responses' => ['q1' => 'yes', 'q12' => 'no']],
            client: ['responses' => ['q1' => 'yes', 'q88' => 'n/a']],
            spec: $this->spec('COMPLETED', ['responses']),
        );

        $this->assertSame(['q1' => 'yes', 'q12' => 'no', 'q88' => 'n/a'], $result->values['responses']);
        $this->assertFalse($result->hasConflicts());
    }

    public function test_the_same_question_answered_differently_names_the_question_in_the_conflict(): void
    {
        $result = $this->merger->merge(
            base: ['responses' => ['q1' => null]],
            server: ['responses' => ['q1' => 'pass']],
            client: ['responses' => ['q1' => 'fail']],
            spec: $this->spec('COMPLETED', ['responses']),
        );

        $this->assertCount(1, $result->conflicts);
        $this->assertSame('responses.q1', $result->conflicts[0]['field'],
            'The queue must point at the question, not at "the answers".');
        $this->assertSame('fail', $result->conflicts[0]['client']);
        $this->assertSame('pass', $result->values['responses']['q1'], 'Server holds pending review.');
    }

    // ── JSON round-trip robustness ──────────────────────────────────────────────────────────────

    public function test_a_value_that_only_changed_type_in_transit_is_not_a_change(): void
    {
        /**
         * A severity that left as int 3 comes back from a mobile JSON encoder as "3" or 3.0. Reading
         * that as an edit would raise a conflict on every sync for every untouched numeric field and
         * bury the real ones.
         */
        $result = $this->merger->merge(
            base: ['actual_severity' => 3],
            server: ['actual_severity' => 3],
            client: ['actual_severity' => '3'],
            spec: $this->spec('CLOSED'),
        );

        $this->assertSame([], $result->values);
        $this->assertFalse($result->hasConflicts());
    }

    public function test_reordered_object_keys_are_not_a_change(): void
    {
        $result = $this->merger->merge(
            base: ['evidence' => [['id' => 'a', 'kind' => 'photo']]],
            server: ['evidence' => [['id' => 'a', 'kind' => 'photo']]],
            client: ['evidence' => [['kind' => 'photo', 'id' => 'a']]],
            spec: $this->spec('CLOSED'),
        );

        $this->assertFalse($result->hasConflicts());
    }

    // ── The invariant, under chaos ──────────────────────────────────────────────────────────────

    public function test_no_generated_scenario_ever_loses_a_client_field(): void
    {
        /**
         * §12: "Sync conflicts lose field data → frontline abandons the app; product dies", mitigated
         * by a chaos suite. Every field the client changed must end up either written or in the
         * conflict queue. Never neither.
         *
         * Deterministic seed: a failure here has to be reproducible, and a random suite that cannot
         * be replayed is worse than no suite.
         */
        mt_srand(20260808);

        $statuses = ['DRAFT', 'REPORTED', 'UNDER_INVESTIGATION', 'ACTIONS_PENDING', 'CLOSED'];
        $fields = ['description', 'location_text', 'actual_severity', 'evidence'];
        $values = ['alpha', 'beta', 'gamma', 3, 4, null, '', [['id' => 'x']], [['id' => 'y']]];

        $scenarios = 0;
        $conflictsSeen = 0;
        $unionsSeen = 0;

        for ($i = 0; $i < 1000; $i++) {
            $status = $statuses[mt_rand(0, count($statuses) - 1)];
            $spec = $this->spec($status);

            $base = [];
            $server = [];
            $client = [];

            foreach ($fields as $field) {
                $pick = fn () => $values[mt_rand(0, count($values) - 1)];
                $baseValue = $pick();

                $base[$field] = $baseValue;
                // Each side independently either leaves the field alone or changes it.
                $server[$field] = mt_rand(0, 1) ? $baseValue : $pick();
                $client[$field] = mt_rand(0, 1) ? $baseValue : $pick();
            }

            $result = $this->merger->merge($base, $server, $client, $spec);
            $scenarios++;

            $lost = $result->unaccountedFor($client, $base, $spec->statusField);

            $this->assertSame([], $lost, sprintf(
                "Scenario %d (status %s) dropped client data for: %s\nbase=%s\nserver=%s\nclient=%s",
                $i, $status, implode(', ', $lost),
                json_encode($base), json_encode($server), json_encode($client)
            ));

            $conflictsSeen += count($result->conflicts);
            $unionsSeen += count(array_filter($result->rules, fn ($r) => $r === SyncMerger::RULE_UNION));
        }

        $this->assertSame(1000, $scenarios);

        // A suite that never exercised the interesting branches would pass trivially.
        $this->assertGreaterThan(0, $conflictsSeen, 'The chaos suite never produced a conflict.');
        $this->assertGreaterThan(0, $unionsSeen, 'The chaos suite never exercised a collection union.');
    }

    public function test_no_generated_scenario_ever_loses_a_server_entry_from_a_collection(): void
    {
        /**
         * The symmetric invariant, added after a real defect: a jsonb column arrives from the driver
         * as a string, and merging it against a decoded array made the server side look empty — so
         * the server's half of an inspection's answers vanished. The client-loss invariant did not
         * catch it, because nothing the client sent was lost.
         *
         * Every entry present on the server before the merge must still be present after it, unless
         * the client legitimately superseded that exact key.
         */
        mt_srand(20260810);

        for ($i = 0; $i < 300; $i++) {
            $keys = ['q1', 'q2', 'q3', 'q4'];
            $answers = ['pass', 'fail', 'n/a', null];
            $pick = fn () => $answers[mt_rand(0, count($answers) - 1)];

            $base = $server = $client = [];

            foreach ($keys as $key) {
                $baseValue = $pick();
                $base[$key] = $baseValue;
                $server[$key] = mt_rand(0, 1) ? $baseValue : $pick();

                // The client only holds a subset — a device that never saw every question.
                if (mt_rand(0, 1)) {
                    $client[$key] = mt_rand(0, 1) ? $baseValue : $pick();
                }
            }

            $result = $this->merger->merge(
                ['responses' => $base], ['responses' => $server], ['responses' => $client],
                $this->spec('COMPLETED', ['responses'])
            );

            // Untouched by this merge is a valid outcome; server state simply stands.
            $merged = $result->values['responses'] ?? $server;

            foreach ($server as $key => $value) {
                $this->assertArrayHasKey($key, $merged,
                    "Scenario {$i} dropped the server's answer for {$key}.");
            }
        }
    }

    public function test_the_chaos_invariant_holds_for_resurrection_too(): void
    {
        mt_srand(20260809);

        for ($i = 0; $i < 200; $i++) {
            $base = ['description' => 'base '.mt_rand(0, 3), 'location_text' => 'loc '.mt_rand(0, 3)];
            $client = [
                'description' => mt_rand(0, 1) ? $base['description'] : 'client '.mt_rand(0, 9),
                'location_text' => mt_rand(0, 1) ? $base['location_text'] : 'client loc '.mt_rand(0, 9),
            ];

            $result = $this->merger->resurrect($base, $client, $this->spec());

            $this->assertSame([], $result->unaccountedFor($client, $base, 'status'),
                "A deleted record's edits were dropped in scenario {$i}.");
        }
    }
}
