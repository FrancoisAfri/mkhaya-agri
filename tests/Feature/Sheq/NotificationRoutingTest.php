<?php

namespace Tests\Feature\Sheq;

use App\Modules\Operations\Sheq\Contracts\NotificationTransport;
use App\Modules\Operations\Sheq\Services\NotificationRouter;
use App\Modules\Operations\Sheq\Services\NotificationSender;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Notification routing and delivery (SHEQ-INC-4 / SHEQ-INC-5).
 *
 * These tests are written against the ways notification systems fail in practice rather than
 * against the happy path: a narrow rule silencing a broad one, one event arriving as four SMSs, a
 * tenant who deleted the fatality rule, and an alert that reports itself sent when nothing left the
 * building.
 */
class NotificationRoutingTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;

    private int $siteId;

    private int $childSiteId;

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

        $this->siteId = $this->site('Region');
        $this->childSiteId = $this->site('Depot', $this->siteId);
    }

    private function site(string $name, ?int $parentId = null): int
    {
        $id = DB::table('sheq_sites')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'code' => 'N'.Str::random(6), 'name' => $name, 'site_type' => 'SITE',
            'parent_id' => $parentId, 'timezone' => 'Africa/Johannesburg', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // The lineage the router reads. Ancestors first, this site last.
        $path = $parentId === null ? (string) $id : $parentId.'.'.$id;
        DB::statement('UPDATE sheq_sites SET path = ?::ltree WHERE id = ?', [$path, $id]);

        return $id;
    }

    private function user(?string $phone = '0821234567'): int
    {
        $userId = DB::table('users')->insertGetId([
            'uuid' => (string) Str::uuid(), 'email' => 'ntf-'.Str::random(8).'@example.test',
            'password' => bcrypt('secret'), 'company_id' => $this->companyId, 'status' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('hr_people')->insert([
            'user_id' => $userId, 'company_id' => $this->companyId,
            'first_name' => 'Thandi', 'surname' => 'Mokoena',
            'cell_number' => $phone, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $userId;
    }

    private function rule(array $overrides = []): int
    {
        return DB::table('sheq_notification_rules')->insertGetId(array_merge([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'name' => 'Rule '.Str::random(4), 'event_type' => 'incident.reported',
            'channels' => json_encode(['EMAIL']), 'delay_minutes' => 0, 'is_active' => true,
            'include_child_sites' => true, 'include_escalation_contacts' => false,
            'created_at' => now(), 'updated_at' => now(),
        ], $overrides));
    }

    private function contact(array $overrides = []): int
    {
        return DB::table('sheq_escalation_contacts')->insertGetId(array_merge([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'name' => 'Group SHE Manager', 'role_label' => 'Group SHE',
            'phone' => '0839999999', 'email' => 'she@example.test',
            'is_voice_target' => true, 'call_order' => 1, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ], $overrides));
    }

    private function router(): NotificationRouter
    {
        return app(NotificationRouter::class);
    }

    /** A garden-variety near miss: nothing statutory, so the floor stays out of it. */
    private function event(array $overrides = []): array
    {
        return array_merge([
            'subject_type' => 'INCIDENT', 'subject_id' => 9001, 'reference' => 'INC-2026-000001',
            'incident_type' => 'NEAR_MISS', 'actual_severity' => 1, 'potential_severity' => 2,
            'site_id' => $this->childSiteId, 'statutory_category' => 'NON_RECORDABLE',
            'is_reportable' => false, 'outcome' => 'NO_INJURY', 'title' => 'Ladder not tied off',
        ], $overrides);
    }

    private function dispatches(int $subjectId = 9001)
    {
        return DB::table('sheq_notification_dispatches')
            ->where('company_id', $this->companyId)
            ->where('subject_id', $subjectId)
            ->orderBy('id')->get();
    }

    // ── Matching (SHEQ-INC-5) ───────────────────────────────────────────────────────────────────

    public function test_an_empty_rule_is_a_catch_all(): void
    {
        // Every criterion null means "any". This is the first rule a tenant writes and it has to
        // work, otherwise nothing is routed until the matrix is complete.
        $user = $this->user();
        $this->rule(['recipient_user_ids' => json_encode([$user])]);

        $this->router()->route($this->companyId, 'incident.reported', $this->event());

        $this->assertCount(1, $this->dispatches());
    }

    public function test_a_rule_outside_the_severity_band_does_not_fire(): void
    {
        $user = $this->user();
        $this->rule([
            'recipient_user_ids' => json_encode([$user]),
            'potential_severity_min' => 4,
        ]);

        $this->router()->route($this->companyId, 'incident.reported',
            $this->event(['potential_severity' => 2]));

        $this->assertCount(0, $this->dispatches());
    }

    public function test_an_unassessed_severity_does_not_satisfy_a_band(): void
    {
        // Treating "not assessed yet" as in-range would page a manager for every unreviewed report.
        $user = $this->user();
        $this->rule(['recipient_user_ids' => json_encode([$user]), 'actual_severity_min' => 1]);

        $this->router()->route($this->companyId, 'incident.reported',
            $this->event(['actual_severity' => null]));

        $this->assertCount(0, $this->dispatches());
    }

    public function test_a_site_rule_covers_sites_beneath_it(): void
    {
        // The incident is at the depot; the rule is pinned to the region above it.
        $user = $this->user();
        $this->rule(['recipient_user_ids' => json_encode([$user]), 'site_id' => $this->siteId]);

        $this->router()->route($this->companyId, 'incident.reported', $this->event());

        $this->assertCount(1, $this->dispatches());
    }

    public function test_a_site_rule_that_excludes_children_stops_at_its_own_site(): void
    {
        $user = $this->user();
        $this->rule([
            'recipient_user_ids' => json_encode([$user]),
            'site_id' => $this->siteId, 'include_child_sites' => false,
        ]);

        $this->router()->route($this->companyId, 'incident.reported', $this->event());

        $this->assertCount(0, $this->dispatches());
    }

    public function test_a_narrow_rule_does_not_silence_a_broad_one(): void
    {
        /**
         * The regression this exists for: with first-match semantics, adding a depot rule would
         * quietly stop the group escalation. Both must fire.
         */
        $groupUser = $this->user();
        $depotUser = $this->user('0827654321');

        $this->rule(['recipient_user_ids' => json_encode([$groupUser]), 'priority' => 10]);
        $this->rule([
            'recipient_user_ids' => json_encode([$depotUser]),
            'site_id' => $this->childSiteId, 'priority' => 1,
        ]);

        $this->router()->route($this->companyId, 'incident.reported', $this->event());

        $recipients = $this->dispatches()->pluck('recipient_user_id')->map('intval')->all();
        $this->assertEqualsCanonicalizing([$groupUser, $depotUser], $recipients);
    }

    // ── Deduplication ───────────────────────────────────────────────────────────────────────────

    public function test_overlapping_rules_produce_one_message_per_person_per_channel(): void
    {
        $user = $this->user();

        $this->rule(['recipient_user_ids' => json_encode([$user]), 'delay_minutes' => 60]);
        $this->rule(['recipient_user_ids' => json_encode([$user]), 'delay_minutes' => 15]);
        $this->rule(['recipient_user_ids' => json_encode([$user]), 'delay_minutes' => 120]);

        $this->router()->route($this->companyId, 'incident.reported', $this->event());

        $rows = $this->dispatches();
        $this->assertCount(1, $rows, 'Three overlapping rules must not be three emails.');

        // The shortest delay wins: the most urgent rule is the one that means it.
        $this->assertLessThanOrEqual(
            Carbon::now()->addMinutes(16),
            Carbon::parse($rows[0]->send_after)
        );
    }

    public function test_the_same_person_still_gets_each_distinct_channel(): void
    {
        $user = $this->user();
        $this->rule([
            'recipient_user_ids' => json_encode([$user]),
            'channels' => json_encode(['EMAIL', 'SMS']),
        ]);

        $this->router()->route($this->companyId, 'incident.reported', $this->event());

        $this->assertEqualsCanonicalizing(['EMAIL', 'SMS'],
            $this->dispatches()->pluck('channel')->all());
    }

    public function test_a_channel_with_no_address_is_not_queued(): void
    {
        // A pending SMS to nobody is a false record of an alert. It must not exist.
        $user = $this->user(phone: null);
        $this->rule([
            'recipient_user_ids' => json_encode([$user]),
            'channels' => json_encode(['EMAIL', 'SMS']),
        ]);

        $this->router()->route($this->companyId, 'incident.reported', $this->event());

        $this->assertSame(['EMAIL'], $this->dispatches()->pluck('channel')->all());
    }

    public function test_rerouting_the_same_event_does_not_duplicate_or_delay(): void
    {
        $user = $this->user();
        $this->rule(['recipient_user_ids' => json_encode([$user]), 'delay_minutes' => 120]);

        $this->router()->route($this->companyId, 'incident.reported', $this->event());
        $before = $this->dispatches()->first();

        // The same incident, re-routed after being reclassified as a fatality.
        $this->router()->route($this->companyId, 'incident.reported',
            $this->event(['outcome' => 'FATALITY']));

        $rows = $this->dispatches()->where('recipient_user_id', $user);
        $this->assertCount(1, $rows, 'Re-routing must update, not duplicate.');

        $after = $rows->first();
        $this->assertSame('CRITICAL', $after->severity);
        $this->assertTrue(Carbon::parse($after->send_after)->lt(Carbon::parse($before->send_after)),
            'An upgrade to a fatality must pull the alert forward.');
    }

    // ── The statutory floor (SHEQ-INC-5) ────────────────────────────────────────────────────────

    public function test_a_fatality_alerts_the_escalation_contacts_even_with_no_rules_at_all(): void
    {
        // The tenant has configured nothing. The floor is not theirs to delete.
        $this->contact(['user_id' => $this->user()]);

        $result = $this->router()->route($this->companyId, 'incident.reported',
            $this->event(['outcome' => 'FATALITY', 'actual_severity' => 5]));

        $this->assertTrue($result['floor_applied']);
        $this->assertSame([], $result['matched_rules']);

        $channels = $this->dispatches()->pluck('channel')->all();
        $this->assertEqualsCanonicalizing(['PUSH', 'SMS', 'EMAIL', 'VOICE'], $channels);
    }

    public function test_a_section_24_event_triggers_the_same_floor(): void
    {
        $this->contact();

        $result = $this->router()->route($this->companyId, 'incident.reported', $this->event([
            'outcome' => 'INJURY', 'statutory_category' => 'OHSA_S24(a)', 'is_reportable' => true,
        ]));

        $this->assertTrue($result['floor_applied']);
        $this->assertContains('VOICE', $this->dispatches()->pluck('channel')->all());
    }

    public function test_the_floor_sends_no_voice_call_to_a_contact_who_is_not_a_voice_target(): void
    {
        $this->contact(['is_voice_target' => false]);

        $this->router()->route($this->companyId, 'incident.reported',
            $this->event(['outcome' => 'FATALITY']));

        $this->assertNotContains('VOICE', $this->dispatches()->pluck('channel')->all());
    }

    public function test_the_floor_collapses_a_configured_delay_to_immediate(): void
    {
        $user = $this->user();
        $this->rule(['recipient_user_ids' => json_encode([$user]), 'delay_minutes' => 240]);

        $this->router()->route($this->companyId, 'incident.reported',
            $this->event(['outcome' => 'FATALITY']));

        $row = $this->dispatches()->firstWhere('recipient_user_id', $user);
        $this->assertSame('CRITICAL', $row->severity);
        $this->assertLessThanOrEqual(Carbon::now()->addMinute(), Carbon::parse($row->send_after));
    }

    public function test_a_routine_incident_does_not_wake_the_escalation_contacts(): void
    {
        // The counterpart that keeps the floor honest: if everything escalated, nothing would.
        $this->contact();

        $result = $this->router()->route($this->companyId, 'incident.reported', $this->event());

        $this->assertFalse($result['floor_applied']);
        $this->assertCount(0, $this->dispatches());
    }

    // ── Tenancy ─────────────────────────────────────────────────────────────────────────────────

    public function test_a_rule_belonging_to_another_tenant_never_fires(): void
    {
        // Created rather than assumed: cross-tenant isolation is too important to leave to whether
        // the test database happens to have a second tenant in it.
        $otherCompany = DB::table('companies')->insertGetId([
            'uuid' => (string) Str::uuid(), 'name' => 'Other Tenant',
            'slug' => 'other-tenant-'.Str::lower(Str::random(8)),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $user = $this->user();
        $this->rule([
            'company_id' => $otherCompany,
            'recipient_user_ids' => json_encode([$user]),
        ]);

        $this->router()->route($this->companyId, 'incident.reported', $this->event());

        $this->assertCount(0, $this->dispatches());
    }

    // ── Delivery ────────────────────────────────────────────────────────────────────────────────

    public function test_an_unsupported_channel_stays_pending_rather_than_reporting_success(): void
    {
        /**
         * This platform cannot place a voice call yet. The requirement still stands, so the row must
         * remain visibly undelivered — marking it SENT would be a false record of a statutory alert.
         */
        $this->contact(['user_id' => $this->user()]);
        $this->router()->route($this->companyId, 'incident.reported',
            $this->event(['outcome' => 'FATALITY']));

        $sender = new NotificationSender(new class implements NotificationTransport
        {
            public array $sent = [];

            public function supports(string $channel): bool
            {
                return in_array($channel, ['EMAIL', 'SMS'], true);
            }

            public function send(array $message): bool
            {
                $this->sent[] = $message;

                return true;
            }
        });

        $result = $sender->drain($this->companyId);

        $this->assertSame(2, $result['sent'], 'Email and SMS go out.');
        $this->assertSame(2, $result['skipped'], 'Push and voice cannot be carried yet.');

        $voice = $this->dispatches()->firstWhere('channel', 'VOICE');
        $this->assertSame('PENDING', $voice->status);
        $this->assertNull($voice->sent_at);
    }

    public function test_a_critical_alert_is_never_abandoned_after_repeated_failure(): void
    {
        $this->contact(['is_voice_target' => false]);
        $this->router()->route($this->companyId, 'incident.reported',
            $this->event(['outcome' => 'FATALITY']));

        $refusing = new class implements NotificationTransport
        {
            public function supports(string $channel): bool
            {
                return true;
            }

            public function send(array $message): bool
            {
                return false;
            }
        };

        $sender = new NotificationSender($refusing);

        // Well past the give-up threshold for routine traffic.
        for ($pass = 0; $pass < 8; $pass++) {
            DB::table('sheq_notification_dispatches')
                ->where('company_id', $this->companyId)
                ->update(['send_after' => now()->subMinute()]);
            $sender->drain($this->companyId);
        }

        $statuses = $this->dispatches()->pluck('status')->unique()->all();
        $this->assertSame(['PENDING'], $statuses,
            'A fatality alert stays on the queue until a human deals with it.');
        $this->assertGreaterThanOrEqual(5, (int) $this->dispatches()->first()->attempts);
    }

    public function test_a_delayed_dispatch_is_not_sent_early(): void
    {
        $user = $this->user();
        $this->rule(['recipient_user_ids' => json_encode([$user]), 'delay_minutes' => 60]);
        $this->router()->route($this->companyId, 'incident.reported', $this->event());

        $sender = new NotificationSender(new class implements NotificationTransport
        {
            public function supports(string $channel): bool
            {
                return true;
            }

            public function send(array $message): bool
            {
                return true;
            }
        });

        $this->assertSame(0, $sender->drain($this->companyId)['sent']);
        $this->assertSame('PENDING', $this->dispatches()->first()->status);
    }
}
