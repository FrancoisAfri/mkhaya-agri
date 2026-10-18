<?php

namespace Tests\Unit\Sheq;

use App\Modules\Operations\Sheq\Contracts\CompetencyLedger;
use App\Modules\Operations\Sheq\Contracts\EmployeeDirectory;
use App\Modules\Operations\Sheq\Services\ContextSnapshotBuilder;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

/**
 * The context snapshot (SHEQ-INC-6), tested through the anti-corruption layer with fakes.
 *
 * This is the payoff from SHEQ-ADR-002: the behaviour that matters — which gaps get raised, how
 * severe they are, and what an unavailable source records — is testable without an LMS course,
 * an enrolment and a certificate chain in the database. The adapters' SQL is a separate concern.
 */
class ContextSnapshotBuilderTest extends TestCase
{
    private function directory(array $people = [], bool $available = true): EmployeeDirectory
    {
        return new class($people, $available) implements EmployeeDirectory
        {
            public function __construct(private array $people, private bool $available) {}

            public function find(int $hrPersonId): ?array
            {
                return $this->people[$hrPersonId] ?? null;
            }

            public function findMany(array $hrPersonIds): array
            {
                return array_intersect_key($this->people, array_flip($hrPersonIds));
            }

            /** Not used by these tests; present because the interface requires it. */
            public function roster(int $companyId): array
            {
                return [];
            }

            public function isAvailable(): bool
            {
                return $this->available;
            }
        };
    }

    private function ledger(array $byPerson = [], bool $available = true): CompetencyLedger
    {
        return new class($byPerson, $available) implements CompetencyLedger
        {
            public function __construct(private array $byPerson, private bool $available) {}

            public function statusFor(int $hrPersonId): array
            {
                return $this->byPerson[$hrPersonId] ?? [];
            }

            public function statusForCompetency(int $hrPersonId, string $competencyCode): ?array
            {
                foreach ($this->statusFor($hrPersonId) as $entry) {
                    if ($entry['competency'] === $competencyCode) {
                        return ['status' => $entry['status'], 'expires_at' => $entry['expires_at'], 'days_overdue' => null];
                    }
                }

                return null;
            }

            public function isAvailable(): bool
            {
                return $this->available;
            }
        };
    }

    /** The §5.1 acceptance criterion, verbatim: E123's ticket expired 62 days before the incident. */
    public function test_an_expired_competency_on_an_involved_person_raises_a_contributing_gap(): void
    {
        $builder = new ContextSnapshotBuilder(
            $this->directory([123 => ['id' => 123, 'full_name' => 'Thabo Mokoena', 'employee_number' => 'E123']]),
            $this->ledger([123 => [[
                'competency' => 'Working at Heights', 'status' => CompetencyLedger::EXPIRED,
                'achieved_at' => '2024-06-01', 'expires_at' => '2026-06-05',
                'days_until_expiry' => -62, 'certificate_ref' => 'WAH-001',
                'blocks_work' => true, 'source' => 'LMS',
            ]]]),
        );

        $result = $builder->build(Carbon::parse('2026-08-06T14:00:00+02:00'), [123]);

        $this->assertCount(1, $result['gaps']);
        $gap = $result['gaps'][0];

        $this->assertSame('COMPETENCY_EXPIRED', $gap['type']);
        $this->assertSame('CRITICAL', $gap['severity'], 'A competency that blocks work is critical, not merely high.');
        $this->assertStringContainsString('Thabo Mokoena', $gap['detail']);
        $this->assertStringContainsString('Working at Heights', $gap['detail']);
        $this->assertStringContainsString('2026-06-05', $gap['detail']);
        $this->assertStringContainsString('62 days', $gap['detail']);
    }

    public function test_an_expired_competency_that_does_not_block_work_is_high_not_critical(): void
    {
        $builder = new ContextSnapshotBuilder(
            $this->directory([7 => ['id' => 7, 'full_name' => 'Sarah Nkosi']]),
            $this->ledger([7 => [[
                'competency' => 'Fire Awareness', 'status' => CompetencyLedger::EXPIRED,
                'achieved_at' => '2023-01-01', 'expires_at' => '2026-01-01',
                'days_until_expiry' => -217, 'certificate_ref' => 'FA-9',
                'blocks_work' => false, 'source' => 'LMS',
            ]]]),
        );

        $this->assertSame('HIGH', $builder->build(Carbon::now(), [7])['gaps'][0]['severity']);
    }

    public function test_a_valid_competency_raises_nothing(): void
    {
        $builder = new ContextSnapshotBuilder(
            $this->directory([9 => ['id' => 9, 'full_name' => 'In Date']]),
            $this->ledger([9 => [[
                'competency' => 'First Aid', 'status' => CompetencyLedger::COMPLIANT,
                'achieved_at' => '2026-01-01', 'expires_at' => '2027-01-01',
                'days_until_expiry' => 148, 'certificate_ref' => 'FA-1',
                'blocks_work' => true, 'source' => 'LMS',
            ]]]),
        );

        $this->assertSame([], $builder->build(Carbon::now(), [9])['gaps']);
    }

    /**
     * The distinction this test defends is the whole reason `sources` exists: in a court bundle,
     * "we could not check" and "we checked and it was fine" must never look the same.
     */
    public function test_an_unavailable_source_is_recorded_as_a_gap_not_as_compliance(): void
    {
        $builder = new ContextSnapshotBuilder(
            $this->directory(available: false),
            $this->ledger(available: false),
        );

        $result = $builder->build(Carbon::now(), [1, 2, 3]);

        $this->assertCount(1, $result['gaps']);
        $this->assertSame('SOURCE_UNAVAILABLE', $result['gaps'][0]['type']);
        $this->assertFalse($result['sources']['employees']['available']);
        $this->assertFalse($result['sources']['competencies']['available']);
        $this->assertStringContainsString('could not be verified', $result['gaps'][0]['detail']);
    }

    public function test_the_snapshot_records_the_moment_it_describes_not_the_moment_it_was_taken(): void
    {
        // Contemporaneity is the point: the snapshot answers "what was true when this happened",
        // which is not the same question as "what is true now that someone got round to reporting it".
        $occurredAt = Carbon::parse('2026-08-06T14:00:00+02:00');

        $result = (new ContextSnapshotBuilder($this->directory(), $this->ledger()))->build($occurredAt);

        $this->assertSame($occurredAt->toIso8601String(), $result['snapshot']['as_at']);
    }

    public function test_competency_state_is_carried_per_person_for_the_investigation(): void
    {
        $builder = new ContextSnapshotBuilder(
            $this->directory([
                1 => ['id' => 1, 'full_name' => 'Driver'],
                2 => ['id' => 2, 'full_name' => 'Spotter'],
            ]),
            $this->ledger([
                1 => [['competency' => 'PDP', 'status' => CompetencyLedger::EXPIRED, 'expires_at' => '2026-07-01',
                    'achieved_at' => '2024-07-01', 'days_until_expiry' => -36, 'certificate_ref' => 'PDP-1',
                    'blocks_work' => true, 'source' => 'LMS']],
                2 => [],
            ]),
        );

        $snapshot = $builder->build(Carbon::now(), [1, 2])['snapshot'];

        $this->assertCount(2, $snapshot['people']);
        $this->assertSame('Driver', $snapshot['people'][0]['employee']['full_name']);
        $this->assertCount(1, $snapshot['people'][0]['competencies']);
        $this->assertSame([], $snapshot['people'][1]['competencies']);
    }
}
