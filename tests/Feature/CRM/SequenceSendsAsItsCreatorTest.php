<?php

namespace Tests\Feature\CRM;

use App\Core\User\Models\User;
use App\Modules\CRM\Models\CrmMailbox;
use App\Modules\CRM\Models\CrmSequence;
use App\Modules\CRM\Models\CrmSequenceEnrollment;
use App\Modules\CRM\Services\CrmSenderIdentity;
use App\Modules\CRM\Services\CrmSequenceService;
use App\Modules\HR\Models\HRPerson;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A sequence goes out as the person who built it.
 *
 * The body already signed off with `{{rep_name}}` — the creator — while the From header carried one
 * shared tenant address. The letter contradicted its own signature, and a reply to it landed in a
 * mailbox that person does not read.
 *
 * The complication is that a mail server may only send for domains it is authorised for. Putting an
 * unauthenticatable address in the From header does not fail loudly; SPF and DKIM misalign, DMARC
 * says forged, and the message is junked while the send still reports success. So the address is
 * used when it can be, and when it cannot the message keeps the person's *name*, authenticates as
 * the address that is allowed to send, and sets Reply-To so replies reach them anyway.
 */
class SequenceSendsAsItsCreatorTest extends TestCase
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

        // A known tenant identity to measure a personal address against.
        config(['mail.from.address' => 'accounts@mkhayamk.co.za', 'mail.from.name' => 'Mkhaya Hub']);
    }

    protected function tearDown(): void
    {
        app(CurrentCompany::class)->clear();
        parent::tearDown();
    }

    private function creator(string $email, ?string $first = 'Lerato', ?string $surname = 'Mokoena'): User
    {
        $user = User::forceCreate([
            'uuid' => (string) Str::uuid(), 'email' => $email,
            'password' => bcrypt('secret'), 'status' => 1, 'company_id' => $this->companyId,
        ]);

        if ($first !== null) {
            HRPerson::forceCreate([
                'user_id' => $user->id, 'first_name' => $first, 'surname' => $surname,
                'company_id' => $this->companyId,
            ]);
        }

        return $user->fresh('person');
    }

    private function sequence(?User $creator): CrmSequence
    {
        return CrmSequence::create([
            'uuid'   => (string) Str::uuid(),
            'name'   => 'Outreach ' . Str::random(6),
            'active' => true,
            'created_by' => $creator?->id,
            'steps'  => [['channel' => 'email', 'day_offset' => 0, 'subject' => 'Hello', 'body' => 'Hi there']],
        ]);
    }

    private function identity(CrmSequence $sequence): array
    {
        return app(CrmSenderIdentity::class)->forSequence($sequence);
    }

    private function mailbox(
        string $email,
        bool $active = true,
        string $purpose = 'lead',
        ?User $owner = null,
    ): CrmMailbox {
        return CrmMailbox::create([
            'name' => 'Box ' . Str::random(4), 'email' => $email, 'active' => $active,
            'purpose' => $purpose, 'default_owner_id' => $owner?->id,
            'host' => 'imap.example.test', 'port' => 993, 'encryption' => 'ssl',
            'username' => $email, 'password' => 'x', 'folder' => 'INBOX',
        ]);
    }

    // ── What was asked for ───────────────────────────────────────────────────

    /** Lerato built it, so the email comes from Lerato. */
    public function test_it_sends_as_the_person_who_built_it(): void
    {
        $sequence = $this->sequence($this->creator('lerato@mkhayamk.co.za'));

        $sender = $this->identity($sequence);

        $this->assertSame('lerato@mkhayamk.co.za', $sender['address']);
        $this->assertSame('Lerato Mokoena', $sender['name']);
        $this->assertSame('creator', $sender['source']);
        // Her address is already in the From header; a Reply-To would be noise.
        $this->assertNull($sender['reply_to']);
    }

    /** Two people, two sequences, two From addresses — the point of the change. */
    public function test_two_sequences_send_as_their_own_authors(): void
    {
        $one = $this->identity($this->sequence($this->creator('lerato@mkhayamk.co.za')));
        $two = $this->identity($this->sequence($this->creator('sipho@mkhayamk.co.za', 'Sipho', 'Ndlovu')));

        $this->assertSame('lerato@mkhayamk.co.za', $one['address']);
        $this->assertSame('sipho@mkhayamk.co.za', $two['address']);
    }

    /** A dedicated marketing subdomain is the same organisation; DMARC's relaxed alignment says so. */
    public function test_a_marketing_subdomain_still_counts_as_the_creators_own_domain(): void
    {
        config(['mail.from.address' => 'noreply@mail.mkhayamk.co.za']);

        $sender = $this->identity($this->sequence($this->creator('lerato@mkhayamk.co.za')));

        $this->assertSame('lerato@mkhayamk.co.za', $sender['address']);
        $this->assertSame('creator', $sender['source']);
    }

    // ── What must not be allowed to happen ───────────────────────────────────

    /**
     * The reason this is not simply "use her address".
     *
     * A message relayed through the company's SMTP claiming to be from gmail.com fails DMARC, and
     * fails it silently — the send reports success and nobody receives anything. So the From header
     * stays the address that can be authenticated.
     */
    public function test_an_address_that_cannot_be_authenticated_is_not_forged_into_the_from_header(): void
    {
        $sender = $this->identity($this->sequence($this->creator('lerato@gmail.com')));

        $this->assertSame('accounts@mkhayamk.co.za', $sender['address'],
            'An unauthenticatable address was put in the From header; those messages are junked.');
        $this->assertSame('creator_reply_to', $sender['source']);
    }

    /** …but she is still the one who wrote it, and still the one who gets written back to. */
    public function test_her_name_and_her_replies_survive_the_fallback(): void
    {
        $sender = $this->identity($this->sequence($this->creator('lerato@gmail.com')));

        $this->assertSame('Lerato Mokoena', $sender['name']);
        $this->assertSame('lerato@gmail.com', $sender['reply_to'],
            'Replies would go to the shared inbox rather than to the person who wrote the letter.');
    }

    // ── Nothing to send as ───────────────────────────────────────────────────

    /** A sequence whose author has since left. The tenant's own identity, exactly as before. */
    public function test_a_sequence_with_no_creator_falls_back_to_the_tenant_address(): void
    {
        $sender = $this->identity($this->sequence(null));

        $this->assertSame('accounts@mkhayamk.co.za', $sender['address']);
        $this->assertSame('application', $sender['source']);
        $this->assertNull($sender['reply_to']);
    }

    /** A user with no HR record has no name to show, but their address still works. */
    public function test_a_creator_without_an_hr_record_still_sends_as_their_address(): void
    {
        $sender = $this->identity($this->sequence($this->creator('ops@mkhayamk.co.za', null)));

        $this->assertSame('ops@mkhayamk.co.za', $sender['address']);
        $this->assertSame('Mkhaya Hub', $sender['name'], 'The tenant name should stand in.');
    }

    // ── The address is the mailbox somebody is actually polling ──────────────

    /**
     * The point of the whole arrangement: outbound address and polled address are one address.
     *
     * A reply is only ever noticed by polling a mailbox registered under CRM → Mailboxes. Sending
     * from anything else means the reply arrives somewhere nothing is watching, and stop-on-reply,
     * the reply exit goal and the Replied column all quietly stop working.
     */
    public function test_it_sends_from_the_creators_registered_mailbox(): void
    {
        $creator = $this->creator('lerato@mkhayamk.co.za');
        $this->mailbox('lerato@mkhayamk.co.za');

        $sender = $this->identity($this->sequence($creator));

        $this->assertSame('lerato@mkhayamk.co.za', $sender['address']);
        $this->assertSame('mailbox', $sender['source']);
        $this->assertTrue($sender['replies_polled'], 'The replies would arrive where nothing is looking.');
    }

    /** A mailbox assigned to her by name, even though its address is not hers. */
    public function test_a_mailbox_assigned_to_the_creator_is_used(): void
    {
        $creator = $this->creator('lerato@mkhayamk.co.za');
        $this->mailbox('sales@mkhayamk.co.za', owner: $creator);

        $sender = $this->identity($this->sequence($creator));

        $this->assertSame('sales@mkhayamk.co.za', $sender['address']);
        // Still signed by her: the address receives, the name is who wrote it.
        $this->assertSame('Lerato Mokoena', $sender['name']);
        $this->assertTrue($sender['replies_polled']);
    }

    /** Her own address wins over a box merely assigned to her. */
    public function test_her_own_mailbox_is_preferred_to_one_she_merely_owns(): void
    {
        $creator = $this->creator('lerato@mkhayamk.co.za');
        $this->mailbox('sales@mkhayamk.co.za', owner: $creator);
        $this->mailbox('lerato@mkhayamk.co.za');

        $this->assertSame('lerato@mkhayamk.co.za', $this->identity($this->sequence($creator))['address']);
    }

    /** One shared sales inbox is the common setup, and there the answer is not in doubt. */
    public function test_the_only_mailbox_there_is_gets_used(): void
    {
        $creator = $this->creator('lerato@mkhayamk.co.za');
        $this->mailbox('sales@mkhayamk.co.za');

        $sender = $this->identity($this->sequence($creator));

        $this->assertSame('sales@mkhayamk.co.za', $sender['address']);
        $this->assertTrue($sender['replies_polled']);
    }

    /**
     * Two of them and nothing tying either to this person: genuinely ambiguous.
     *
     * Picking one would send a cadence from an address its author never chose. Nothing is picked,
     * and the page warns instead.
     */
    public function test_two_unattributable_mailboxes_are_not_guessed_between(): void
    {
        $creator = $this->creator('lerato@mkhayamk.co.za');
        $this->mailbox('sales@mkhayamk.co.za');
        $this->mailbox('hello@mkhayamk.co.za');

        $sender = $this->identity($this->sequence($creator));

        $this->assertSame('creator', $sender['source'], 'A mailbox was guessed at.');
        $this->assertNull($sender['mailbox']);
    }

    // ── Which mailboxes may not be used ──────────────────────────────────────

    /** Outreach must not arrive from the address people send grievances to. */
    public function test_a_complaints_mailbox_is_never_used_to_send_outreach(): void
    {
        $creator = $this->creator('lerato@mkhayamk.co.za');
        $this->mailbox('complaints@mkhayamk.co.za', purpose: 'complaint');

        $this->assertNull($this->identity($this->sequence($creator))['mailbox']);
    }

    /** A switched-off mailbox is polling nothing, so sending from it strands the replies. */
    public function test_a_deactivated_mailbox_is_not_used(): void
    {
        $creator = $this->creator('lerato@mkhayamk.co.za');
        $this->mailbox('sales@mkhayamk.co.za', active: false);

        $sender = $this->identity($this->sequence($creator));

        $this->assertNull($sender['mailbox']);
        $this->assertFalse($sender['replies_polled']);
    }

    // ── When there is no mailbox to send from ────────────────────────────────

    /**
     * No mailbox at all: the person's own address, which at least looks like a person — but with
     * nothing polling it the replies are invisible, and the page has to say so.
     */
    public function test_with_no_mailbox_it_falls_back_and_flags_the_unwatched_replies(): void
    {
        $sender = $this->identity($this->sequence($this->creator('lerato@mkhayamk.co.za')));

        $this->assertSame('lerato@mkhayamk.co.za', $sender['address']);
        $this->assertSame('creator', $sender['source']);
        $this->assertFalse($sender['replies_polled']);
    }

    /**
     * A mailbox on a domain this server cannot authenticate as.
     *
     * The From header has to stay the address that is allowed to send, but Reply-To carries no
     * authentication burden — so the replies still arrive in the polled mailbox, which is the thing
     * that mattered.
     */
    public function test_an_unauthenticatable_mailbox_still_receives_the_replies(): void
    {
        $creator = $this->creator('lerato@mkhayamk.co.za');
        $this->mailbox('mkhaya.sales@gmail.com');

        $sender = $this->identity($this->sequence($creator));

        $this->assertSame('accounts@mkhayamk.co.za', $sender['address'],
            'An unauthenticatable address was put in the From header; those messages are junked.');
        $this->assertSame('mkhaya.sales@gmail.com', $sender['reply_to']);
        $this->assertSame('mailbox_reply_to', $sender['source']);
        $this->assertTrue($sender['replies_polled'], 'The replies land in a registered mailbox either way.');
    }

    // ── End to end, through the actual send ──────────────────────────────────

    /** The header on the message that leaves, not just what the resolver returns. */
    public function test_the_message_that_goes_out_carries_the_creators_address(): void
    {
        // The array transport rather than Mail::fake(): this send goes out as raw html() through a
        // mailer resolved at call time, and only a real transport keeps the built Symfony message
        // — which is the only place the From header actually exists.
        config(['mail.default' => 'array']);
        Mail::purge('array');

        $sequence = $this->sequence($this->creator('lerato@mkhayamk.co.za'));

        CrmSequenceEnrollment::create([
            'sequence_id' => $sequence->id, 'email' => 'prospect@example.test',
            'status' => 'active', 'current_step' => 0,
            'enrolled_at' => now()->subDay(), 'next_step_at' => now()->subHour(),
        ]);

        app(CrmSequenceService::class)->processDue($sequence);

        $messages = Mail::mailer('array')->getSymfonyTransport()->messages();

        $this->assertNotEmpty($messages, 'Nothing was sent, so the From header proves nothing.');

        $from = $messages[0]->getOriginalMessage()->getFrom();

        $this->assertSame('lerato@mkhayamk.co.za', $from[0]->getAddress());
        $this->assertSame('Lerato Mokoena', $from[0]->getName());
    }

    /** And the fallback's Reply-To, on the message itself. */
    public function test_the_message_carries_a_reply_to_when_the_address_could_not_be_used(): void
    {
        config(['mail.default' => 'array']);
        Mail::purge('array');

        $sequence = $this->sequence($this->creator('lerato@gmail.com'));

        CrmSequenceEnrollment::create([
            'sequence_id' => $sequence->id, 'email' => 'prospect@example.test',
            'status' => 'active', 'current_step' => 0,
            'enrolled_at' => now()->subDay(), 'next_step_at' => now()->subHour(),
        ]);

        app(CrmSequenceService::class)->processDue($sequence);

        $messages = Mail::mailer('array')->getSymfonyTransport()->messages();
        $this->assertNotEmpty($messages);

        $email = $messages[0]->getOriginalMessage();

        $this->assertSame('accounts@mkhayamk.co.za', $email->getFrom()[0]->getAddress());
        $this->assertSame('lerato@gmail.com', $email->getReplyTo()[0]->getAddress(),
            'A prospect hitting reply would reach the shared inbox, not the person who wrote to them.');
    }
}
