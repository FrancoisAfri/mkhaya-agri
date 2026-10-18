<?php

namespace Tests\Feature\Tax;

use App\Modules\Finance\Tax\Events\TaxEventOccurred;
use App\Modules\Finance\Tax\Support\TaxEvents;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/** §19: tax events are durable, de-duplicated, delivered at least once, and failures stay visible. */
class TaxEventsTest extends TaxTestCase
{
    public function test_an_event_is_written_once_and_delivered_to_listeners(): void
    {
        $events = app(TaxEvents::class);
        $events->publish('tax.return.approved', ['return_id' => 7, 'company_id' => $this->company->id]);
        $events->publish('tax.return.approved', ['company_id' => $this->company->id, 'return_id' => 7]); // same fact, retried
        $this->assertSame(1, DB::table('tax_events')->where('name', 'tax.return.approved')->count(), 'Duplicates are ignored.');

        $seen = [];
        Event::listen(TaxEventOccurred::class, function (TaxEventOccurred $e) use (&$seen) {
            $seen[] = [$e->name, $e->payload['return_id']];
        });
        $this->assertSame(1, $events->deliver()['delivered']);
        $this->assertSame([['tax.return.approved', 7]], $seen);
        $this->assertSame(0, $events->deliver()['delivered'], 'Delivered once.');
    }

    public function test_a_failing_listener_is_retried_then_left_visible_as_failed(): void
    {
        $events = app(TaxEvents::class);
        $events->publish('tax.deadline.breached', ['deadline_id' => 1, 'company_id' => $this->company->id]);
        Event::listen(TaxEventOccurred::class, fn () => throw new \RuntimeException('webhook down'));

        for ($i = 0; $i < TaxEvents::MAX_ATTEMPTS; $i++) {
            DB::table('tax_events')->update(['available_at' => now()->subMinute()]);
            $events->deliver();
        }
        $row = DB::table('tax_events')->first();
        $this->assertSame(['failed', TaxEvents::MAX_ATTEMPTS], [$row->status, (int) $row->attempts]);
        $this->assertStringContainsString('webhook down', $row->last_error);

        $events->retry((int) $row->id);
        $this->assertSame('pending', DB::table('tax_events')->value('status'));
    }

    public function test_business_actions_publish_through_the_outbox(): void
    {
        $this->standardRules();
        $this->vatRegistration();
        $this->invoice('receivable', '2026-03-10', [['1000.00', '15', 'STD']]);
        $this->actingAs($this->preparer);
        app(\App\Modules\Finance\Tax\Services\VatRegisterService::class)->refresh($this->period('2026-03-15'));

        $this->assertTrue(DB::table('tax_events')->where('name', 'tax.transaction.recorded')->exists());
        $payload = json_decode(DB::table('tax_events')->where('name', 'tax.transaction.recorded')->value('payload'), true);
        $this->assertSame(['company_id', 'source_key'], array_keys($payload), 'Identifiers only — no amounts or personal data.');
    }
}
