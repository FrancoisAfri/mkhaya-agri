<?php

namespace Tests\Feature\ProjectManagement;

use App\Modules\Operations\ProjectManagement\Models\OutboxMessage;
use App\Modules\Operations\ProjectManagement\Models\Project;
use App\Modules\Operations\ProjectManagement\Models\Webhook;
use App\Modules\Operations\ProjectManagement\Models\WebhookDelivery;
use App\Modules\Operations\ProjectManagement\Services\TaskService;
use App\Modules\Operations\ProjectManagement\Services\WebhookDispatcher;
use App\Modules\Tenancy\Models\Company;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

/**
 * Getting domain events out (EPIC-070, PM-ADR-008).
 *
 * The outbox already guarantees a message exists if and only if the change committed. What is
 * proved here is the other half: that a receiver can tell our payload from anyone else's, that a
 * captured request cannot be replayed against them next week, and that an endpoint which has been
 * dead for a fortnight is switched off rather than retried into the heat death of the queue.
 */
class WebhookDeliveryTest extends ProjectManagementTestCase
{
    private Company $company;

    private Project $project;

    private WebhookDispatcher $dispatcher;

    protected function setUp(): void
    {
        parent::setUp();

        // A real tenant, because the drain runs per company: a message with no company_id would be
        // delivered against whatever subscriptions happened to be visible, which with the RLS
        // session variable unset is all of them.
        $this->company = Company::create(['name' => 'Alpha Construction']);
        $this->project = $this->asCompany($this->company, fn () => $this->makeProject());
        $this->dispatcher = app(WebhookDispatcher::class);
    }

    private function subscribe(array $eventTypes = []): array
    {
        return $this->asCompany(
            $this->company,
            fn () => $this->dispatcher->subscribe('Site system', 'https://example.test/hooks/pm', $eventTypes)
        );
    }

    private function message(string $type = 'pm.task.created'): OutboxMessage
    {
        return $this->asCompany($this->company, function () use ($type) {
            app(TaskService::class)->create($this->project, ['name' => 'Excavate']);

            return OutboxMessage::where('event_type', $type)->firstOrFail();
        });
    }

    /** Deliveries happen inside the tenant, as the drain worker does. */
    private function deliver(OutboxMessage $message): array
    {
        return $this->asCompany($this->company, fn () => $this->dispatcher->deliver($message));
    }

    private function drain(): void
    {
        $this->artisan('pm:drain-outbox', ['--company' => $this->company->id])->assertSuccessful();
    }

    // ── The secret ───────────────────────────────────────────────────────────

    /**
     * The secret has to be recoverable to sign with — an HMAC needs the plaintext. Hashing it would
     * leave the subscription permanently unable to sign anything, which is the whole job.
     */
    public function test_the_secret_can_sign_but_never_leaves_the_server(): void
    {
        ['webhook' => $webhook, 'secret' => $secret] = $this->subscribe();

        $this->assertSame($secret, $webhook->fresh()->secret, 'The dispatcher has to be able to read it.');
        $this->assertStringEndsWith($webhook->secret_hint, $secret);

        // And it is not in anything that gets serialised to a client.
        $this->assertArrayNotHasKey('secret', $webhook->toArray());
        $this->assertStringNotContainsString($secret, json_encode($webhook));
    }

    public function test_the_stored_secret_is_not_readable_in_the_raw_row(): void
    {
        ['secret' => $secret] = $this->subscribe();

        $raw = \Illuminate\Support\Facades\DB::table('pm_webhooks')->value('secret');

        $this->assertNotSame($secret, $raw, 'A database dump must not hand over the signing key.');
    }

    public function test_a_plain_http_endpoint_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('has to be https');

        $this->dispatcher->subscribe('Insecure', 'http://example.test/hook');
    }

    // ── Delivery ─────────────────────────────────────────────────────────────

    public function test_a_delivery_is_signed_so_a_receiver_can_verify_it(): void
    {
        Http::fake(['*' => Http::response('', 200)]);

        ['secret' => $secret] = $this->subscribe();
        $this->deliver($this->message());

        Http::assertSent(function ($request) use ($secret) {
            $timestamp = (int) $request->header('X-Mkhaya-Timestamp')[0];
            $expected = WebhookDispatcher::signature($secret, $timestamp, $request->body());

            // The timestamp is inside the signed string, so it cannot be altered without breaking
            // the signature — which is what makes the replay window enforceable at the receiver.
            return hash_equals($expected, $request->header('X-Mkhaya-Signature')[0]);
        });
    }

    public function test_a_tampered_body_fails_the_signature(): void
    {
        ['secret' => $secret] = $this->subscribe();

        $timestamp = now()->getTimestamp();
        $signature = WebhookDispatcher::signature($secret, $timestamp, '{"amount":100}');

        $this->assertFalse(hash_equals(
            $signature,
            WebhookDispatcher::signature($secret, $timestamp, '{"amount":100000}')
        ));
    }

    public function test_only_subscribed_event_types_are_delivered(): void
    {
        Http::fake(['*' => Http::response('', 200)]);

        $this->subscribe(['pm.project.archived']);

        $result = $this->dispatcher->deliver($this->message('pm.task.created'));

        $this->assertSame(0, $result['sent']);
        Http::assertNothingSent();
    }

    public function test_an_empty_subscription_list_means_everything(): void
    {
        Http::fake(['*' => Http::response('', 200)]);

        $this->subscribe([]);

        $this->assertSame(1, $this->deliver($this->message())['sent']);
    }

    public function test_a_delivery_is_logged_with_its_status_and_duration(): void
    {
        Http::fake(['*' => Http::response('', 202)]);

        $this->subscribe();
        $this->deliver($this->message());

        $delivery = WebhookDelivery::firstOrFail();

        $this->assertSame(202, $delivery->status_code);
        $this->assertNotNull($delivery->delivered_at);
        $this->assertNotNull($delivery->duration_ms);
    }

    public function test_a_refused_connection_is_a_failed_delivery_not_a_failed_run(): void
    {
        Http::fake(fn () => throw new \RuntimeException('Connection refused'));

        $this->subscribe();

        // The worker has other messages to get through; one dead endpoint must not stop the drain.
        $result = $this->deliver($this->message());

        $this->assertSame(1, $result['failed']);
        $this->assertStringContainsString('Connection refused', WebhookDelivery::firstOrFail()->error);
    }

    public function test_a_five_hundred_is_recorded_with_the_receivers_own_message(): void
    {
        Http::fake(['*' => Http::response('upstream database is down', 500)]);

        $this->subscribe();
        $this->deliver($this->message());

        $this->assertSame(500, WebhookDelivery::firstOrFail()->status_code);
        // Their error, kept, so an integrator debugging at four in the afternoon has something.
        $this->assertStringContainsString('upstream database is down', WebhookDelivery::firstOrFail()->error);
    }

    /**
     * Retrying a dead endpoint forever costs the tenant nothing and costs us a worker, and the
     * silence is how nobody notices for a month.
     */
    public function test_an_endpoint_that_keeps_failing_is_switched_off_with_a_reason(): void
    {
        Http::fake(['*' => Http::response('', 500)]);

        ['webhook' => $webhook] = $this->subscribe();
        $message = $this->message();

        for ($i = 0; $i < Webhook::FAILURE_LIMIT; $i++) {
            $this->deliver($message);
        }

        $webhook->refresh();

        $this->assertFalse($webhook->is_active);
        $this->assertNotNull($webhook->disabled_at);
        $this->assertStringContainsString('Fix the endpoint', $webhook->disabled_reason);
    }

    public function test_a_success_clears_the_failure_count(): void
    {
        ['webhook' => $webhook] = $this->subscribe();
        $message = $this->message();

        // A sequence, not two fake() calls: fake() merges stubs, so a second '*' stub never wins.
        Http::fakeSequence()->push('', 500)->push('', 200);

        $this->deliver($message);
        $this->assertSame(1, $webhook->fresh()->consecutive_failures);

        $this->deliver($message);

        $this->assertSame(0, $webhook->fresh()->consecutive_failures);
        $this->assertNotNull($webhook->fresh()->last_delivered_at);
    }

    // ── The drain ────────────────────────────────────────────────────────────

    public function test_the_drain_marks_delivered_messages_and_backs_off_failures(): void
    {
        Http::fake(['*' => Http::response('', 200)]);
        $this->subscribe();
        $this->message();

        $this->drain();

        $this->assertNotNull(OutboxMessage::where('event_type', 'pm.task.created')->first()->delivered_at);
    }

    public function test_a_failed_message_is_retried_later_rather_than_immediately(): void
    {
        Http::fake(['*' => Http::response('', 500)]);
        $this->subscribe();
        $this->message();

        $this->drain();

        $message = OutboxMessage::where('event_type', 'pm.task.created')->first();

        $this->assertNull($message->delivered_at);
        $this->assertSame(1, $message->attempts);
        // Backed off, so a consumer having a bad afternoon is not hammered into a worse one.
        $this->assertTrue($message->available_at->isFuture());
    }

    /**
     * A queue with no consumer is just a table that grows. The ledger is the record of what
     * happened; the outbox is a delivery mechanism, and with nobody listening there is nothing to
     * deliver.
     */
    public function test_messages_are_not_hoarded_when_nothing_is_subscribed(): void
    {
        $this->message();

        $this->drain();

        $this->assertNotNull(OutboxMessage::where('event_type', 'pm.task.created')->first()->delivered_at);
        $this->assertSame(0, WebhookDelivery::count());
    }
}
