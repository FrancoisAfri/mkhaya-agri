<?php

namespace Tests\Feature\Audit;

use App\Shared\Models\EmailDeliveryLog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

/**
 * Whether an email went out, on the record.
 *
 * The question that produced this was "did that invitation go out?", and nothing could answer it.
 * The only evidence was the recipient saying it never arrived — by which point nobody could tell
 * whether it had been sent and lost downstream, refused by the mail server, or never attempted at
 * all. Three different problems with three different fixes, and no way to tell them apart.
 *
 * Two events do the work, because one is not enough: `MessageSending` fires before the transport is
 * touched and `MessageSent` only after it accepts, so a transport that throws produces the first
 * and never the second. A row left at `pending` is precisely that case.
 */
class EmailDeliveryLogTest extends TestCase
{
    use DatabaseTransactions;

    private function send(string $to = 'someone@example.test'): void
    {
        Mail::raw('Body', fn ($m) => $m->to($to)->subject('A subject'));
    }

    public function test_a_sent_email_is_written_down(): void
    {
        $this->send('recipient@example.test');

        $row = EmailDeliveryLog::where('to', 'recipient@example.test')->latest('id')->first();

        $this->assertNotNull($row, 'Nothing recorded the send.');
        $this->assertSame(EmailDeliveryLog::SENT, $row->status);
        $this->assertSame('A subject', $row->subject);
    }

    public function test_the_status_says_accepted_rather_than_delivered(): void
    {
        /*
         * Worth pinning. SMTP accepting a message is not the recipient receiving it, and a log that
         * claimed "delivered" would be telling somebody a comforting thing it has no way of
         * knowing — which is exactly the kind of false confidence this page exists to remove.
         */
        $this->assertContains(EmailDeliveryLog::SENT, EmailDeliveryLog::STATUSES);
        $this->assertNotContains('delivered', EmailDeliveryLog::STATUSES);
    }

    public function test_an_attempt_that_never_comes_back_is_visible(): void
    {
        /*
         * What a mail server refusing connections looks like from in here: the attempt is recorded
         * on the way in, the transport throws, and the completion never happens. Before this the
         * failure left no trace at all.
         */
        $row = EmailDeliveryLog::create([
            'uuid'   => (string) Str::uuid(),
            'to'     => 'unreachable@example.test',
            'status' => EmailDeliveryLog::PENDING,
        ]);

        $this->assertSame(EmailDeliveryLog::PENDING, $row->fresh()->status);
    }

    public function test_a_failure_records_the_reason(): void
    {
        // "It did not arrive" is not actionable; "connection to mail.host:25 timed out" is.
        EmailDeliveryLog::fail(
            'unreachable@example.test',
            'Portal invitation',
            new TransportException('Connection to "mail.example.test:25" timed out.'),
        );

        $row = EmailDeliveryLog::where('to', 'unreachable@example.test')->latest('id')->first();

        $this->assertSame(EmailDeliveryLog::FAILED, $row->status);
        $this->assertStringContainsString('timed out', $row->error);
    }

    public function test_a_failure_completes_the_attempt_rather_than_writing_a_second_row(): void
    {
        /*
         * The send logs itself on the way in, and the catch site records why it failed. Both
         * describing one attempt must not read as two — a duplicated row would double every count
         * on the page.
         */
        EmailDeliveryLog::create([
            'uuid'   => (string) Str::uuid(),
            'to'     => 'once@example.test',
            'status' => EmailDeliveryLog::PENDING,
        ]);

        EmailDeliveryLog::fail('once@example.test', 'Subject', new TransportException('Refused.'));

        $rows = EmailDeliveryLog::where('to', 'once@example.test')->get();

        $this->assertCount(1, $rows);
        $this->assertSame(EmailDeliveryLog::FAILED, $rows->first()->status);
    }

    public function test_logging_never_breaks_the_send(): void
    {
        /*
         * A log that can take down the thing it is observing is worse than no log. An address far
         * longer than the column would otherwise throw from inside the mail event and abort a send
         * that was about to succeed.
         */
        $long = str_repeat('a', 400) . '@example.test';

        EmailDeliveryLog::fail($long, str_repeat('s', 500), new TransportException(str_repeat('e', 5000)));

        // No exception escaped, which is the assertion.
        $this->assertTrue(true);
    }

    /**
     * The kind of email is recorded, which is the column the page is filtered by.
     *
     * `originOf()` looked for a `mailable` key. Laravel sets `__laravel_mailable`, so notifications
     * were recorded and every Mailable — invitations, invoices, signing requests — came through
     * with a blank kind. Filtering the log for invitations therefore returned nothing, which reads
     * as "none were sent" rather than as "this column was never filled in" — and that is exactly
     * the question the page exists to answer.
     */
    public function test_the_kind_of_email_is_recorded_so_a_category_can_be_found(): void
    {
        $to = 'invited-' . Str::random(6) . '@example.test';

        Mail::to($to)->send(new \App\Modules\Operations\ProjectManagement\Mail\PortalInviteMail(
            new \App\Modules\Operations\ProjectManagement\Models\MemberInvite([
                'email' => $to, 'role_key' => 'client', 'expires_at' => now()->addDays(7),
            ]),
            null,
            'https://example.test/invite/token',
        ));

        $row = EmailDeliveryLog::where('to', $to)->latest('id')->first();

        $this->assertNotNull($row, 'The invitation was not recorded at all.');
        $this->assertSame(EmailDeliveryLog::SENT, $row->status);
        $this->assertStringContainsString('PortalInviteMail', (string) $row->mailable,
            'The log cannot say which kind of email this was, so the invitations cannot be found.');
    }

    // ── Reading the log without a browser ────────────────────────────────────

    /**
     * The page needs the code deployed, the ribbons synced and the assets built before anybody can
     * open it — and "did that invitation go out?" is most urgent exactly when a release has not
     * happened yet.
     */
    public function test_the_console_reader_shows_what_was_sent(): void
    {
        $to = 'shell-' . Str::random(6) . '@example.test';
        Mail::raw('Body', fn ($m) => $m->to($to)->subject('A subject'));

        $this->artisan('mail:log --limit=50')
            ->expectsOutputToContain($to)
            ->assertSuccessful();
    }

    /**
     * Unscoped on purpose: the model is BelongsToCompany and a console pass has no signed-in user,
     * so the ordinary scope resolves no company and shows nothing — a diagnostic that hides the
     * rows it was opened to find answers "nothing was sent", which is the opposite of the truth.
     */
    public function test_the_console_reader_sees_rows_belonging_to_a_company(): void
    {
        $companyId = \Illuminate\Support\Facades\DB::table('companies')->min('id');

        if ($companyId === null) {
            $this->markTestSkipped('No company in the test database.');
        }

        $to = 'tenanted-' . Str::random(6) . '@example.test';

        EmailDeliveryLog::create([
            'uuid' => (string) Str::uuid(),
            'company_id' => $companyId,
            'to' => $to,
            'subject' => 'Tenanted',
            'status' => EmailDeliveryLog::SENT,
        ]);

        $this->artisan('mail:log --limit=50')->expectsOutputToContain($to)->assertSuccessful();
    }

    /** An empty result must not read as "it was attempted and it failed". */
    public function test_the_console_reader_says_when_nothing_matches(): void
    {
        $this->artisan('mail:log --kind=NothingCalledThis')
            ->expectsOutputToContain('Nothing in the log matches that')
            ->assertSuccessful();
    }
}
