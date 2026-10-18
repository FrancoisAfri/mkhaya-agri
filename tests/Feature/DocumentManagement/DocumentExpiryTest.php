<?php

namespace Tests\Feature\DocumentManagement;

use App\Core\User\Models\User;
use App\Modules\Operations\DocumentManagement\Models\Document;
use App\Modules\Operations\DocumentManagement\Models\DocumentExpiryReminder;
use App\Modules\Operations\DocumentManagement\Models\DocumentSetting;
use App\Modules\Operations\DocumentManagement\Notifications\DocumentExpiryNotification;
use App\Modules\Operations\DocumentManagement\Services\DocumentCompliance;
use App\Modules\Operations\DocumentManagement\Services\ExpiryService;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Chasing documents before they lapse.
 *
 * The two things that make or break this feature are both about restraint: a reminder must be sent
 * once rather than every morning, and a lapsed document must only stop other work when somebody
 * deliberately said it should. Get either wrong and the organisation either ignores the emails or
 * cannot pay its suppliers.
 */
class DocumentExpiryTest extends TestCase
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
        app(CurrentCompany::class)->set($this->companyId);
        DB::statement("SELECT set_config('app.company_id', ?, false)", [(string) $companyId]);

        Notification::fake();
    }

    private function expiry(): ExpiryService
    {
        return app(ExpiryService::class);
    }

    private function document(array $attributes = []): Document
    {
        return Document::create(array_merge([
            'title' => 'Tax clearance certificate',
            'status' => Document::STATUS_ACTIVE,
            'version_count' => 1,
        ], $attributes));
    }

    private function owner(): User
    {
        return User::forceCreate([
            'uuid' => (string) Str::uuid(), 'email' => 'own+' . Str::random(8) . '@example.test',
            'password' => bcrypt('secret'), 'status' => 1, 'company_id' => $this->companyId,
        ]);
    }

    // ── Reminders ────────────────────────────────────────────────────────────

    public function test_a_reminder_goes_out_at_each_configured_threshold(): void
    {
        $owner = $this->owner();

        foreach ([90, 60, 30, 14, 7, 0] as $days) {
            $this->document([
                'title' => "Expires in {$days}",
                'owner_id' => $owner->id,
                'expiry_date' => now()->addDays($days),
            ]);
        }

        $result = $this->expiry()->sweep();

        // All six thresholds from the requirement, in one sweep.
        $this->assertSame(6, $result['reminded']);
        Notification::assertSentTo($owner, DocumentExpiryNotification::class);
    }

    public function test_the_same_reminder_is_not_sent_twice(): void
    {
        $owner = $this->owner();
        $this->document(['owner_id' => $owner->id, 'expiry_date' => now()->addDays(30)]);

        $first = $this->expiry()->sweep();
        $second = $this->expiry()->sweep();

        // The sweep runs every morning. Without this, so does the email — and a notification people
        // have learned to ignore is worse than none.
        $this->assertSame(1, $first['reminded']);
        $this->assertSame(0, $second['reminded']);
    }

    public function test_a_renewed_document_starts_its_reminders_again(): void
    {
        $owner = $this->owner();
        $document = $this->document(['owner_id' => $owner->id, 'expiry_date' => now()->addDays(30)]);

        $this->expiry()->sweep();

        // Renewed: a new certificate with a new expiry, the same document record.
        $document->update(['expiry_date' => now()->addDays(395)]);

        // Reminders are keyed on the expiry date they were about, so the new one is warned about
        // in its own right with nothing having to clear the old records.
        $this->assertSame(1, $this->expiry()->sweep(now()->addDays(305))['reminded']);
    }

    public function test_reminders_can_be_switched_off_without_losing_the_expiry_dates(): void
    {
        DocumentSetting::updateOrCreate([], ['expiry_reminders_enabled' => false]);

        $this->document(['owner_id' => $this->owner()->id, 'expiry_date' => now()->addDays(30)]);
        $result = $this->expiry()->sweep();

        $this->assertSame(0, $result['reminded']);
        Notification::assertNothingSent();
    }

    public function test_the_thresholds_are_configurable(): void
    {
        DocumentSetting::updateOrCreate([], ['expiry_reminder_days' => [45, 10]]);

        $this->assertSame([45, 10], $this->expiry()->thresholds());

        $this->document(['owner_id' => $this->owner()->id, 'expiry_date' => now()->addDays(45)]);
        $this->assertSame(1, $this->expiry()->sweep()['reminded']);
    }

    public function test_a_document_with_no_owner_is_still_recorded_as_reminded(): void
    {
        $this->document(['expiry_date' => now()->addDays(30)]);

        // Nobody to tell, but the reminder is logged — otherwise the sweep retries it every day
        // forever, and the log is what shows an administrator that nobody owns it.
        $this->assertSame(1, $this->expiry()->sweep()['reminded']);
        $this->assertSame(1, DocumentExpiryReminder::count());
    }

    // ── Expiring ─────────────────────────────────────────────────────────────

    public function test_a_lapsed_document_is_marked(): void
    {
        $document = $this->document(['expiry_date' => now()->subDay()]);

        $this->expiry()->sweep();

        $this->assertNotNull($document->fresh()->expired_at);
        $this->assertTrue($document->fresh()->isExpired());
    }

    public function test_a_document_is_only_marked_once(): void
    {
        $this->document(['expiry_date' => now()->subDay()]);

        $this->assertSame(1, $this->expiry()->sweep()['expired']);
        $this->assertSame(0, $this->expiry()->sweep()['expired']);
    }

    // ── Blocking other work ──────────────────────────────────────────────────

    public function test_an_expired_document_blocks_only_when_somebody_said_it_should(): void
    {
        $supplier = $this->supplier();
        $ordinary = $this->document(['expiry_date' => now()->subDay()]);
        $supplier->attachDocument($ordinary);

        // A repository where any expired file halts a payment run is one that halts payment runs by
        // accident. The person who uploaded a superseded quote did not sign up for that.
        $this->assertFalse(app(DocumentCompliance::class)->isBlocked($supplier));
    }

    public function test_a_blocking_document_stops_the_supplier(): void
    {
        $supplier = $this->supplier();
        $insurance = $this->document([
            'title' => 'Public liability insurance',
            'expiry_date' => now()->subDays(3),
            'blocks_when_expired' => true,
        ]);
        $supplier->attachDocument($insurance);

        // The requirement's own example.
        $this->assertTrue($supplier->isBlockedByExpiredDocuments());
        $this->assertStringContainsString('Public liability insurance', $supplier->documentBlockReason());
    }

    public function test_renewing_the_certificate_clears_the_block(): void
    {
        $supplier = $this->supplier();
        $insurance = $this->document(['expiry_date' => now()->subDay(), 'blocks_when_expired' => true]);
        $supplier->attachDocument($insurance);

        $this->assertTrue($supplier->isBlockedByExpiredDocuments());

        $insurance->update(['expiry_date' => now()->addYear(), 'expired_at' => null]);

        $this->assertFalse($supplier->isBlockedByExpiredDocuments());
    }

    public function test_a_valid_blocking_document_does_not_block(): void
    {
        $supplier = $this->supplier();
        $supplier->attachDocument($this->document([
            'expiry_date' => now()->addMonths(6), 'blocks_when_expired' => true,
        ]));

        $this->assertFalse($supplier->isBlockedByExpiredDocuments());
    }

    public function test_the_reason_names_the_document_rather_than_the_fact(): void
    {
        $supplier = $this->supplier();
        $supplier->attachDocument($this->document([
            'title' => 'B-BBEE certificate', 'expiry_date' => now()->subDay(), 'blocks_when_expired' => true,
        ]));

        // "Blocked by an expired document" sends somebody hunting; naming it tells them what to go
        // and get.
        $this->assertStringContainsString('B-BBEE certificate', app(DocumentCompliance::class)->reason($supplier));
    }

    private function supplier(): \App\Modules\SupplierManagement\Models\Supplier
    {
        return \App\Modules\SupplierManagement\Models\Supplier::create([
            'company_name' => 'Acme Trading ' . Str::random(4),
            'company_id' => $this->companyId,
        ]);
    }
}
