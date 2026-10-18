<?php

namespace Tests\Feature\Sheq;

use App\Modules\Operations\Sheq\Events\EventPublisher;
use App\Modules\Operations\Sheq\Events\EventRegistry;
use App\Modules\Operations\Sheq\Events\WebhookDispatcher;
use App\Modules\Operations\Sheq\Services\HazardService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Domain event publishing (§8.2, SHEQ-ARC-1, SHEQ-INT-1, SHEQ-API-5).
 *
 * The outbox table has existed since Phase 0 with nothing writing to it. These tests defend what
 * that filling has to guarantee: an event never outlives a rolled-back write, a payload always
 * satisfies the registered schema another team builds against, and a webhook signature covers the
 * exact bytes the subscriber received.
 */
class EventPublishingTest extends TestCase
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

    private function publisher(): EventPublisher
    {
        return app(EventPublisher::class);
    }

    private function validPayload(): array
    {
        return [
            'incident_id' => 1, 'site_id' => null, 'type' => 'INJURY',
            'actual_severity' => 3, 'potential_severity' => 4,
            'statutory_category' => 'OHSA_S24(b)', 'is_reportable' => true,
            'occurred_at' => now()->toIso8601String(),
        ];
    }

    // ── The transactional guarantee (SHEQ-ARC-1) ────────────────────────────────────────────────

    /**
     * The guard that refuses a publish outside a transaction cannot be exercised here, and saying so
     * is better than a test that quietly proves nothing: `DatabaseTransactions` opens a transaction
     * around every test, so `DB::transactionLevel()` is never 0 inside one.
     *
     * What the guard protects is covered by the rollback test below, which is the behaviour that
     * actually matters. This asserts only that the guard is present in the code path.
     */
    public function test_the_publisher_guards_against_publishing_outside_a_transaction(): void
    {
        $source = file_get_contents(
            app_path('Modules/Operations/Sheq/Events/EventPublisher.php')
        );

        $this->assertStringContainsString('DB::transactionLevel() === 0', $source,
            'The transactional guard has been removed from EventPublisher.');
        $this->assertStringContainsString('outside a transaction', $source);
    }

    public function test_an_event_does_not_survive_a_rolled_back_transaction(): void
    {
        $before = DB::table('sheq_outbox_messages')->where('company_id', $this->companyId)->count();

        try {
            DB::transaction(function () {
                $this->publisher()->publish($this->companyId, 'sheq.incident.reported',
                    'INCIDENT', 1, $this->validPayload());

                throw new \RuntimeException('the write failed after the event was published');
            });
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertSame($before,
            DB::table('sheq_outbox_messages')->where('company_id', $this->companyId)->count(),
            'An event outlived the transaction that produced it.');
    }

    // ── The schema registry (SHEQ-INT-1) ────────────────────────────────────────────────────────

    public function test_an_event_missing_a_registered_key_is_refused(): void
    {
        // A consumer builds against the registered schema. Shipping an incomplete event breaks
        // their integration silently; failing here is far cheaper.
        $this->expectExceptionMessageMatches('/missing required payload keys/');

        DB::transaction(fn () => $this->publisher()->publish($this->companyId,
            'sheq.incident.reported', 'INCIDENT', 1, ['incident_id' => 1]));
    }

    public function test_an_unregistered_event_type_is_refused(): void
    {
        $this->expectExceptionMessageMatches('/not a registered SHEQ event/');

        DB::transaction(fn () => $this->publisher()->publish($this->companyId,
            'sheq.something.invented', 'INCIDENT', 1, []));
    }

    public function test_every_registered_event_declares_a_version_and_its_required_keys(): void
    {
        $registry = app(EventRegistry::class);

        $this->assertNotEmpty($registry->types());

        foreach ($registry->all() as $type => $schema) {
            $this->assertMatchesRegularExpression('/^sheq\.[a-z_]+\.[a-z_]+$/', $type,
                "Event {$type} does not follow the sheq.subject.verb convention.");
            $this->assertGreaterThanOrEqual(1, $schema['version']);
            $this->assertNotEmpty($schema['required'], "{$type} declares no required keys.");
            $this->assertNotEmpty($schema['describes'], "{$type} has no description for consumers.");
        }
    }

    public function test_the_envelope_carries_everything_sheq_int_1_requires(): void
    {
        DB::transaction(fn () => $this->publisher()->publish($this->companyId,
            'sheq.incident.reported', 'INCIDENT', 42, $this->validPayload(),
            ['correlation_id' => 'req-abc']));

        $row = DB::table('sheq_outbox_messages')
            ->where('company_id', $this->companyId)->orderByDesc('id')->first();

        $this->assertNotNull($row->event_id);
        $this->assertSame('sheq.incident.reported', $row->event_type);
        $this->assertSame(1, (int) $row->event_version);
        $this->assertSame($this->companyId, (int) $row->company_id);
        $this->assertSame(42, (int) $row->subject_id);
        // The sequence is the table's identity — ordering belongs to the database.
        $this->assertGreaterThan(0, (int) $row->id);
        $this->assertSame('req-abc', json_decode($row->metadata, true)['correlation_id']);
    }

    // ── Wired into the domain ───────────────────────────────────────────────────────────────────

    public function test_reporting_a_hazard_publishes_an_event(): void
    {
        $site = DB::table('sheq_sites')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'code' => 'E'.Str::random(6), 'name' => 'Yard', 'site_type' => 'SITE',
            'timezone' => 'Africa/Johannesburg', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        app(HazardService::class)->report($this->companyId, [
            'description' => 'Oil spill by the loading bay.',
            'hazard_category' => 'HOUSEKEEPING', 'site_id' => $site,
        ]);

        $event = DB::table('sheq_outbox_messages')
            ->where('company_id', $this->companyId)
            ->where('event_type', 'sheq.hazard.reported')->orderByDesc('id')->first();

        $this->assertNotNull($event, 'Reporting a hazard published nothing.');
        $this->assertSame('HOUSEKEEPING', json_decode($event->payload, true)['category']);
    }

    public function test_an_anonymous_report_does_not_identify_the_reporter_in_the_event(): void
    {
        // SHEQ-INC-3. A subscriber must not be able to work out who reported anonymously.
        $site = DB::table('sheq_sites')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'code' => 'A'.Str::random(6), 'name' => 'Yard', 'site_type' => 'SITE',
            'timezone' => 'Africa/Johannesburg', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $userId = DB::table('users')->insertGetId([
            'uuid' => (string) Str::uuid(), 'email' => 'anon-'.Str::random(6).'@example.test',
            'password' => bcrypt('x'), 'company_id' => $this->companyId, 'status' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        app(HazardService::class)->report($this->companyId, [
            'description' => 'Reported anonymously.', 'hazard_category' => 'OTHER',
            'site_id' => $site, 'is_anonymous' => true,
        ], $userId);

        $event = DB::table('sheq_outbox_messages')
            ->where('company_id', $this->companyId)
            ->where('event_type', 'sheq.hazard.reported')->orderByDesc('id')->first();

        $this->assertNull(json_decode($event->metadata, true)['actor_id']);
        $this->assertTrue(json_decode($event->metadata, true)['anonymous']);
    }

    // ── Webhook delivery (SHEQ-API-5) ───────────────────────────────────────────────────────────

    private function webhook(array $overrides = []): int
    {
        return DB::table('sheq_webhooks')->insertGetId(array_merge([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'name' => 'Test subscriber', 'url' => 'https://example.test/hooks/sheq',
            'secret' => 'shhh-secret-key', 'secret_hint' => '-key',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ], $overrides));
    }

    private function message(): object
    {
        DB::transaction(fn () => $this->publisher()->publish($this->companyId,
            'sheq.incident.reported', 'INCIDENT', 7, $this->validPayload()));

        return DB::table('sheq_outbox_messages')
            ->where('company_id', $this->companyId)->orderByDesc('id')->first();
    }

    public function test_a_delivery_is_signed_over_the_exact_bytes_that_were_sent(): void
    {
        /**
         * Signing a re-encoded copy is the classic bug: JSON key order and whitespace change, the
         * subscriber's verification fails, and nobody can work out why.
         */
        $this->webhook();
        Http::fake(['*' => Http::response('', 200)]);

        app(WebhookDispatcher::class)->dispatch($this->companyId, $this->message());

        Http::assertSent(function ($request) {
            $timestamp = $request->header('X-Sheq-Timestamp')[0];
            $expected = 'sha256='.hash_hmac('sha256',
                $timestamp.'.'.$request->body(), 'shhh-secret-key');

            return $request->header('X-Sheq-Signature')[0] === $expected;
        });
    }

    public function test_a_subscriber_only_receives_the_events_it_asked_for(): void
    {
        $this->webhook(['event_types' => json_encode(['sheq.action.overdue'])]);
        Http::fake(['*' => Http::response('', 200)]);

        $result = app(WebhookDispatcher::class)->dispatch($this->companyId, $this->message());

        $this->assertSame(1, $result['skipped']);
        $this->assertSame(0, $result['delivered']);
        Http::assertNothingSent();
    }

    public function test_a_failed_delivery_is_recorded_rather_than_lost(): void
    {
        $webhookId = $this->webhook();
        Http::fake(['*' => Http::response('nope', 500)]);

        $result = app(WebhookDispatcher::class)->dispatch($this->companyId, $this->message());

        $this->assertSame(1, $result['failed']);

        $delivery = DB::table('sheq_webhook_deliveries')
            ->where('webhook_id', $webhookId)->orderByDesc('id')->first();

        $this->assertSame(500, (int) $delivery->response_status);
        $this->assertSame(1, (int) DB::table('sheq_webhooks')
            ->where('id', $webhookId)->value('consecutive_failures'));
    }

    public function test_a_persistently_failing_subscriber_is_disabled_with_a_reason(): void
    {
        // An endpoint dead for a week is not coming back this minute, and retrying it forever
        // delays every healthy subscriber behind it.
        $webhookId = $this->webhook(['consecutive_failures' => 19]);
        Http::fake(['*' => Http::response('', 503)]);

        app(WebhookDispatcher::class)->dispatch($this->companyId, $this->message());

        $row = DB::table('sheq_webhooks')->find($webhookId);
        $this->assertFalse((bool) $row->is_active);
        $this->assertNotNull($row->disabled_at);
        $this->assertStringContainsString('consecutive failures', $row->disabled_reason);
    }

    public function test_a_dead_letter_can_be_replayed(): void
    {
        // SHEQ-API-5 requires a replayable DLQ — a message that vanished cannot be explained.
        $message = $this->message();

        for ($i = 0; $i < 8; $i++) {
            $this->publisher()->markFailed((int) $message->id, 'subscriber down');
        }

        $this->assertNotNull(DB::table('sheq_outbox_messages')->find($message->id)->failed_at);

        $this->assertTrue($this->publisher()->replay($this->companyId, $message->event_id));

        $row = DB::table('sheq_outbox_messages')->find($message->id);
        $this->assertNull($row->failed_at);
        $this->assertSame(0, (int) $row->attempts);
    }

    public function test_another_tenants_events_are_never_pending_for_this_one(): void
    {
        $otherCompany = DB::table('companies')->insertGetId([
            'uuid' => (string) Str::uuid(), 'name' => 'Other Tenant',
            'slug' => 'other-evt-'.Str::lower(Str::random(8)),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->message();

        $this->assertNotEmpty($this->publisher()->pending($this->companyId));
        $this->assertSame([], $this->publisher()->pending($otherCompany));
    }
}
