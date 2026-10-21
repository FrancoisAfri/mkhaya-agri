<?php

namespace Tests\Feature\Agri;

use App\Modules\Agri\Models\CropObservation;
use App\Modules\Agri\Models\FarmAlert;
use App\Modules\Agri\Models\FarmMembership;
use App\Modules\Agri\Models\FarmTask;
use App\Modules\Agri\Notifications\FarmAlertDigest;
use App\Modules\Agri\Notifications\FarmTaskUpdate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Module K offline capture (queued changes applied exactly once, under the same rules) and the
 * emails for alerts and tasks.
 */
class OfflineAndEmailTest extends AgriTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    private function task($farm, $user, array $o = []): FarmTask
    {
        return $this->asCompany($this->company, fn () => FarmTask::withoutGlobalScopes()->create($o + [
            'company_id' => $this->company->id, 'farm_id' => $farm->id, 'title' => 'Fix fence', 'priority' => 'normal',
            'status' => 'open', 'assigned_to' => $user->id, 'requires_approval' => true,
        ]));
    }

    private function sync($user, array $ops): array
    {
        return $this->actingAs($user)->postJson(route('agri.my-work.sync'), ['ops' => $ops])->assertOk()->json('results');
    }

    // ── Offline ──────────────────────────────────────────────────────────────────────────────

    public function test_a_queued_change_is_applied_once_however_often_the_phone_sends_it(): void
    {
        $farm = $this->farm('NORTH');
        $this->member($this->worker, $farm, 'worker');
        $task = $this->task($farm, $this->worker);
        $op = ['client_id' => (string) Str::uuid(), 'op' => 'task.submit', 'payload' => ['id' => $task->uuid, 'labour_hours' => 3, 'completion_notes' => 'Done offline'],
            'captured_at' => now()->subHours(2)->toIso8601String()];

        $first = $this->sync($this->worker, [$op]);
        $again = $this->sync($this->worker, [$op]);

        $this->assertSame('applied', $first[0]['status']);
        $this->assertSame('applied', $again[0]['status']);
        $this->assertTrue($again[0]['duplicate']);
        $this->assertSame('submitted', $task->fresh()->status);
        $this->assertSame('Done offline', $task->fresh()->completion_notes);
    }

    public function test_offline_is_no_way_around_the_rules(): void
    {
        $north = $this->farm('NORTH');
        $south = $this->farm('SOUTH');
        $this->member($this->worker, $north, 'viewer');      // read-only on NORTH
        $someoneElses = $this->task($north, $this->manager);

        $r = $this->sync($this->worker, [
            ['client_id' => (string) Str::uuid(), 'op' => 'observation.create', 'payload' => ['farm_id' => $north->uuid, 'kind' => 'pest', 'severity' => 'low', 'description' => 'x']],
            ['client_id' => (string) Str::uuid(), 'op' => 'observation.create', 'payload' => ['farm_id' => $south->uuid, 'kind' => 'pest', 'severity' => 'low', 'description' => 'x']],
            ['client_id' => (string) Str::uuid(), 'op' => 'task.start', 'payload' => ['id' => $someoneElses->uuid]],
        ]);

        $this->assertSame(['rejected', 'rejected', 'rejected'], array_column($r, 'status'));
        $this->assertStringContainsString('read-only', $r[0]['message']);
        $this->assertSame(0, CropObservation::withoutGlobalScopes()->count());
        $this->assertSame('open', $someoneElses->fresh()->status);
    }

    public function test_an_observation_made_offline_keeps_the_day_it_was_seen(): void
    {
        $farm = $this->farm('NORTH');
        $this->member($this->worker, $farm, 'worker');
        $this->sync($this->worker, [['client_id' => (string) Str::uuid(), 'op' => 'observation.create', 'captured_at' => now()->subDays(2)->toIso8601String(),
            'payload' => ['farm_id' => $farm->uuid, 'kind' => 'disease', 'severity' => 'high', 'description' => 'Rust on lower leaves']]]);

        $o = CropObservation::withoutGlobalScopes()->firstOrFail();
        $this->assertSame(now()->subDays(2)->toDateString(), $o->observed_on->toDateString());
        $this->assertTrue($o->action_required);
    }

    public function test_the_phone_app_files_are_served_without_data(): void
    {
        $this->get('/agri/sw.js')->assertOk()->assertHeader('Service-Worker-Allowed', '/agri/');
        $this->get('/agri/manifest.webmanifest')->assertOk()->assertJsonPath('start_url', '/agri/my-work');
    }

    // ── Email ────────────────────────────────────────────────────────────────────────────────

    public function test_new_alerts_are_emailed_once_to_the_farms_leads_and_owners_but_not_info_or_opted_out(): void
    {
        $north = $this->farm('NORTH');
        $this->member($this->manager, $north, 'manager');
        $sup = $this->member($this->supervisor, $north, 'supervisor');
        $this->member($this->worker, $north, 'worker');
        $sup->update(['alert_emails' => false]);
        $this->task($north, $this->worker, ['title' => 'Late job', 'due_on' => today()->subDays(3)]);

        $this->artisan('agri:scan-alerts')->assertSuccessful();
        $this->artisan('agri:scan-alerts')->assertSuccessful();

        Notification::assertSentToTimes($this->manager, FarmAlertDigest::class, 1);
        Notification::assertSentToTimes($this->admin, FarmAlertDigest::class, 1); // reaches every farm
        Notification::assertNotSentTo($this->supervisor, FarmAlertDigest::class);  // turned it off
        Notification::assertNotSentTo($this->worker, FarmAlertDigest::class);      // not a lead
        $this->assertNotNull(FarmAlert::withoutGlobalScopes()->first()->notified_at);
    }

    public function test_task_emails_reach_the_assignee_and_the_signers_never_the_doer(): void
    {
        $north = $this->farm('NORTH');
        $this->member($this->manager, $north, 'manager');
        $this->member($this->worker, $north, 'worker');

        $this->actingAs($this->manager)->post(route('agri.tasks.store'), ['farm_id' => $north->uuid, 'title' => 'Fix pump', 'priority' => 'high',
            'assigned_to' => $this->worker->id, 'requires_approval' => true])->assertSessionHasNoErrors();
        Notification::assertSentTo($this->worker, FarmTaskUpdate::class, fn ($n) => $n->event === FarmTaskUpdate::ASSIGNED);

        $task = FarmTask::withoutGlobalScopes()->firstOrFail();
        $this->actingAs($this->worker)->post(route('agri.tasks.transition', $task->uuid), ['action' => 'submit'])->assertSessionHasNoErrors();
        Notification::assertSentTo($this->manager, FarmTaskUpdate::class, fn ($n) => $n->event === FarmTaskUpdate::SUBMITTED);
        Notification::assertNotSentTo($this->worker, FarmTaskUpdate::class, fn ($n) => $n->event === FarmTaskUpdate::SUBMITTED);

        $this->actingAs($this->manager)->post(route('agri.tasks.transition', $task->uuid), ['action' => 'reject', 'review_note' => 'Still leaking'])->assertSessionHasNoErrors();
        Notification::assertSentTo($this->worker, FarmTaskUpdate::class, fn ($n) => $n->event === FarmTaskUpdate::REJECTED && $n->note === 'Still leaking');
    }

    public function test_a_mail_server_that_is_down_never_stops_the_work(): void
    {
        Notification::swap(new class extends \Illuminate\Support\Testing\Fakes\NotificationFake {
            public function sendNow($notifiables, $notification, ?array $channels = null) { throw new \RuntimeException('SMTP down'); }
            public function send($notifiables, $notification) { throw new \RuntimeException('SMTP down'); }
        });
        $north = $this->farm('NORTH');
        $this->member($this->manager, $north, 'manager');
        $this->member($this->worker, $north, 'worker');

        $this->actingAs($this->manager)->post(route('agri.tasks.store'), ['farm_id' => $north->uuid, 'title' => 'Fix pump', 'priority' => 'high',
            'assigned_to' => $this->worker->id])->assertSessionHasNoErrors();
        $this->assertSame(1, FarmTask::withoutGlobalScopes()->count());

        $this->task($north, $this->worker, ['title' => 'Late job', 'due_on' => today()->subDays(3)]);
        $this->artisan('agri:scan-alerts')->assertSuccessful();
        $this->assertNull(FarmAlert::withoutGlobalScopes()->where('kind', 'task_overdue')->first()->notified_at, 'Retried at the next scan.');
    }

    public function test_15_4_field_records_from_phase_two_sync_once_and_keep_their_rules(): void
    {
        $this->catalogue();
        $farm = $this->farm('NORTH');
        $this->member($this->worker, $farm, 'worker');
        $this->member($this->supervisor, $farm, 'supervisor');
        $tractor = $this->asCompany($this->company, fn () => app(\App\Modules\Agri\Services\Machinery::class)->register($farm, ['code' => 'T1', 'name' => 'Tractor', 'machine_type' => 'tractor', 'hours_reading' => 10]));
        $cold = $this->asCompany($this->company, fn () => app(\App\Modules\Agri\Services\FarmInventory::class)->addStore($farm, ['kind' => 'cold_room', 'name' => 'Cold']));
        $this->asCompany($this->company, fn () => $cold->update(['temperature_max' => 4]));
        $items = collect($this->asCompany($this->company, fn () => app(\App\Modules\Agri\Services\MachineMaintenance::class)->checklistFor($tractor)))
            ->map(fn ($c) => ['item' => $c['item'], 'ok' => true])->all();

        $ops = [
            ['client_id' => (string) Str::uuid(), 'op' => 'safety.report', 'payload' => ['farm_id' => $farm->uuid, 'kind' => 'hazard', 'description' => 'Loose cattle-grid bar', 'report_id' => 'p-1']],
            ['client_id' => (string) Str::uuid(), 'op' => 'prestart.record', 'payload' => ['machine_id' => $tractor->uuid, 'items' => $items, 'hours_reading' => 12]],
            ['client_id' => (string) Str::uuid(), 'op' => 'storage.reading', 'payload' => ['store_id' => $cold->uuid, 'temperature' => 6.5, 'reading_id' => 'cr-1']],
        ];
        // The worker may report a hazard, do the pre-start check and read the cold room; someone with no access to the farm may do none of it.
        $r = $this->sync($this->worker, $ops);
        $this->assertSame(['applied', 'applied', 'applied'], array_column($r, 'status'));
        $this->assertStringContainsString('Out of range', $r[2]['message']);
        $this->actingAs($this->outsider)->postJson(route('agri.my-work.sync'), ['ops' => $ops])->assertForbidden();
        $again = $this->sync($this->worker, $ops);
        $this->assertTrue($again[0]['duplicate']);
        $this->assertSame(1, \Illuminate\Support\Facades\DB::table('sheq_hazards')->count());
        $this->assertSame(1, \App\Modules\Agri\Models\MachinePrestartCheck::withoutGlobalScopes()->count());
        $this->assertEqualsWithDelta(12.0, (float) $tractor->fresh()->hours_reading, 0.01);

        // The same reading sent again by another phone is kept once.
        $this->sync($this->supervisor, [['client_id' => (string) Str::uuid(), 'op' => 'storage.reading', 'payload' => ['store_id' => $cold->uuid, 'temperature' => 6.5, 'reading_id' => 'cr-1']]]);
        $this->assertSame(1, \App\Modules\Agri\Models\StorageReading::withoutGlobalScopes()->count());
    }
}
