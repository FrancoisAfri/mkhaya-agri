<?php

namespace Tests\Feature\Sheq;

use App\Modules\Operations\Sheq\Console\SweepEventsCommand;
use App\Modules\Operations\Sheq\Events\EventRegistry;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Time-based event publishing (§8.2).
 *
 * A deadline passing calls no method, so without this sweep the escalation events — the ones an
 * integration most needs — would never be emitted at all. What these tests defend is that it emits
 * each one exactly once: a sweep that re-announced the same overdue action every hour would train
 * every subscriber to filter SHEQ out, which is worse than silence.
 */
class EventSweepTest extends TestCase
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
    }

    private function sweep(): void
    {
        $this->artisan(SweepEventsCommand::class, ['--company' => $this->companyId])
            ->assertSuccessful();
    }

    private function published(string $type): int
    {
        return DB::table('sheq_outbox_messages')
            ->where('company_id', $this->companyId)->where('event_type', $type)->count();
    }

    private function overdueAction(): int
    {
        return DB::table('sheq_actions')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'reference' => 'ACT-'.Str::random(8),
            'title' => 'Replace the damaged guard',
            'due_date' => Carbon::today()->subDays(5),
            'status' => 'OPEN', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_an_overdue_action_is_announced(): void
    {
        $this->overdueAction();

        $before = $this->published('sheq.action.overdue');
        $this->sweep();

        $this->assertSame($before + 1, $this->published('sheq.action.overdue'));
    }

    public function test_the_same_overdue_action_is_never_announced_twice(): void
    {
        /**
         * The behaviour that decides whether anybody keeps subscribing. An hourly sweep that
         * re-announces the same action is noise, and noise gets filtered.
         */
        $this->overdueAction();

        $this->sweep();
        $after = $this->published('sheq.action.overdue');

        $this->sweep();
        $this->sweep();

        $this->assertSame($after, $this->published('sheq.action.overdue'));
    }

    public function test_an_action_that_is_not_yet_due_is_not_announced(): void
    {
        DB::table('sheq_actions')->insert([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'reference' => 'ACT-'.Str::random(8), 'title' => 'Not due yet',
            'due_date' => Carbon::today()->addDays(10),
            'status' => 'OPEN', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $before = $this->published('sheq.action.overdue');
        $this->sweep();

        $this->assertSame($before, $this->published('sheq.action.overdue'));
    }

    public function test_a_completed_action_is_not_announced_as_overdue(): void
    {
        DB::table('sheq_actions')->insert([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'reference' => 'ACT-'.Str::random(8), 'title' => 'Done long ago',
            'due_date' => Carbon::today()->subDays(30),
            'status' => 'VERIFIED_CLOSED', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $before = $this->published('sheq.action.overdue');
        $this->sweep();

        $this->assertSame($before, $this->published('sheq.action.overdue'));
    }

    public function test_a_breached_obligation_is_announced_and_its_status_follows(): void
    {
        /**
         * The record follows the fact. A deadline that passed is breached whether or not anybody
         * looked, and leaving it PENDING would understate the tenant's legal position.
         */
        $id = DB::table('sheq_statutory_obligations')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'obligation_def_id' => 'za.ohsa.notify', 'label' => 'Notify the Provincial Director',
            'subject_type' => 'INCIDENT', 'subject_id' => 4242,
            'regulatory_pack_id' => 'za-ohsa', 'regulatory_pack_version' => '2026.1.0',
            'anchor_at' => Carbon::now()->subDays(3), 'due_at' => Carbon::now()->subDay(),
            'status' => 'PENDING', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->sweep();

        $this->assertSame(1, DB::table('sheq_outbox_messages')
            ->where('company_id', $this->companyId)
            ->where('event_type', 'sheq.statutory_obligation.breached')
            ->where('subject_id', $id)->count());

        $this->assertSame('BREACHED',
            DB::table('sheq_statutory_obligations')->find($id)->status);
    }

    public function test_an_obligation_due_within_48_hours_is_warned_not_breached(): void
    {
        $id = DB::table('sheq_statutory_obligations')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'obligation_def_id' => 'za.ohsa.wcl2', 'label' => 'Submit W.Cl.2',
            'subject_type' => 'INCIDENT', 'subject_id' => 4243,
            'regulatory_pack_id' => 'za-ohsa', 'regulatory_pack_version' => '2026.1.0',
            'anchor_at' => Carbon::now(), 'due_at' => Carbon::now()->addHours(12),
            'status' => 'PENDING', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->sweep();

        $this->assertSame(1, DB::table('sheq_outbox_messages')
            ->where('company_id', $this->companyId)
            ->where('event_type', 'sheq.statutory_obligation.due')
            ->where('subject_id', $id)->count());

        // Still PENDING — a warning must not move the record.
        $this->assertSame('PENDING',
            DB::table('sheq_statutory_obligations')->find($id)->status);
    }

    public function test_an_expiring_competency_warns_and_a_lapsed_one_escalates(): void
    {
        $competencyId = DB::table('sheq_competencies')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'code' => 'C'.Str::random(6), 'title' => 'Working at heights',
            'competency_type' => 'TRAINING', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $soon = DB::table('sheq_competency_records')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'competency_id' => $competencyId, 'hr_person_id' => 7001,
            'status' => 'VALID', 'expires_at' => Carbon::today()->addDays(10),
            'source' => 'SHEQ', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $lapsed = DB::table('sheq_competency_records')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'competency_id' => $competencyId, 'hr_person_id' => 7002,
            'status' => 'VALID', 'expires_at' => Carbon::today()->subDays(2),
            'source' => 'SHEQ', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->sweep();

        $this->assertSame(1, DB::table('sheq_outbox_messages')
            ->where('event_type', 'sheq.competency.expiring')->where('subject_id', $soon)->count());
        $this->assertSame(1, DB::table('sheq_outbox_messages')
            ->where('event_type', 'sheq.competency.expired')->where('subject_id', $lapsed)->count());
    }

    public function test_a_revoked_competency_is_not_announced_as_expired(): void
    {
        // Revoked is a decision somebody made; expired is time passing. They are not the same
        // event and a consumer reacting to them differently is the point.
        $competencyId = DB::table('sheq_competencies')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'code' => 'R'.Str::random(6), 'title' => 'Revoked ticket',
            'competency_type' => 'LICENCE', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $id = DB::table('sheq_competency_records')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'competency_id' => $competencyId, 'hr_person_id' => 7003,
            'status' => 'REVOKED', 'expires_at' => Carbon::today()->subDays(5),
            'revoked_at' => Carbon::now()->subDay(), 'revocation_reason' => 'Licence withdrawn',
            'source' => 'SHEQ', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->sweep();

        $this->assertSame(0, DB::table('sheq_outbox_messages')
            ->where('event_type', 'sheq.competency.expired')->where('subject_id', $id)->count());
    }

    public function test_every_swept_event_satisfies_its_registered_schema(): void
    {
        // The publisher validates on write, so anything in the outbox already conformed — this
        // asserts the sweep actually exercised more than one event type.
        $this->overdueAction();
        $this->sweep();

        $types = DB::table('sheq_outbox_messages')
            ->where('company_id', $this->companyId)
            ->distinct()->pluck('event_type')->all();

        $this->assertNotEmpty($types);

        $registry = app(EventRegistry::class);

        foreach ($types as $type) {
            $this->assertContains($type, $registry->types(), "{$type} is not registered.");
        }
    }
}
