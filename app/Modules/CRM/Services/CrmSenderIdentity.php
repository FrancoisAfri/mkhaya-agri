<?php

namespace App\Modules\CRM\Services;

use App\Core\User\Models\User;
use App\Modules\CRM\Models\CrmMailbox;
use App\Modules\CRM\Models\CrmSequence;
use Illuminate\Support\Str;

/**
 * Who a sequence's email appears to come from.
 *
 * It went out as one shared address for the whole tenant, while the body signed off with
 * `{{rep_name}}` — the person who built the cadence. So a prospect received a letter signed by
 * Lerato from an address that was not Lerato's, and a reply to it reached a mailbox she does not
 * read. Cold outreach lives or dies on looking like a person wrote it; the From header is the first
 * thing a recipient sees and was the one part still saying "system".
 *
 * ── Why a registered mailbox, and not simply the person's account email ──────
 *
 * Because a reply has to come back. Replies are only ever noticed by polling a mailbox registered
 * under CRM → Mailboxes: `CrmMailboxService` matches the sender's address against the enrolments
 * and calls `recordReply`. Nothing else does. An address that is not one of those mailboxes is an
 * address whose replies the system will never see — stop-on-reply never fires, the reply exit goal
 * never fires, and the Replied column sits at zero while the conversation happens in somebody's
 * Outlook.
 *
 * So the sending address is the person's *registered mailbox*, which makes the outbound address and
 * the polled address the same address by construction. That is the whole point: the loop closes.
 *
 * Complaint mailboxes are excluded. They are watched for a different reason and outreach must not
 * arrive from the address people send grievances to.
 *
 * ── Why it is not simply "use that address" either ───────────────────────────
 *
 * A mail server may only send for domains it is authorised for. SPF says which servers may send as
 * a domain, DKIM signs with that domain's key, and DMARC fails the message when the From domain
 * matches neither. Put an unauthenticatable address in the From header of a message relayed through
 * the company's own SMTP and the receiving side is being told, by the sender domain's own published
 * policy, that the message is forged — so it is junked or rejected. Silently: the send succeeds,
 * the analytics say "sent", and nobody receives anything.
 *
 * A mailbox is somewhere to *receive*; it is not proof that this server may *send* as it. So the
 * address is used in the From header when it can be, and when it cannot the message authenticates
 * as the address that is allowed to send and puts the mailbox in Reply-To. Either way the replies
 * land in the polled mailbox, which is what was actually being asked for.
 *
 * Subdomains count as aligned, because a dedicated marketing subdomain — `mail.example.com`, which
 * `CrmMailService::marketingMailer()` exists to support — is the same organisation and DMARC's
 * relaxed alignment treats it as such.
 */
class CrmSenderIdentity
{
    public function __construct(private CrmMailService $mail)
    {
    }

    /**
     * The sending identity for a sequence: the person who built it, at the mailbox they receive on.
     *
     * @return array{address:?string, name:?string, reply_to:?string, source:string, mailbox:?string, replies_polled:bool}
     */
    public function forSequence(CrmSequence $sequence): array
    {
        $creator = $sequence->created_by
            ? User::withoutGlobalScopes()->with('person')->find($sequence->created_by)
            : null;

        return $this->forUser($creator);
    }

    /**
     * @return array{address:?string, name:?string, reply_to:?string, source:string, mailbox:?string, replies_polled:bool}
     */
    public function forUser(?User $user): array
    {
        $configured = $this->configured();
        $name = $this->displayName($user) ?? $configured['name'];
        $mailbox = $this->mailboxFor($user);

        // The address we would like the reply to arrive at, best first: the mailbox somebody is
        // actually polling, and only failing that the person's own account address — which is
        // better than a shared inbox for looking like a person, but nothing is watching it.
        $candidate = $mailbox?->email ?: ($user?->email ? trim($user->email) : null);

        if ($candidate === null || $candidate === '') {
            return $configured + ['reply_to' => null, 'mailbox' => null, 'replies_polled' => false];
        }

        $prefix = $mailbox ? 'mailbox' : 'creator';

        $identity = $this->isAligned($candidate, $configured['address'])
            ? [
                'address'  => $candidate,
                'name'     => $name,
                'reply_to' => null,
                'source'   => $prefix,
            ]
            : [
                // Cannot be authenticated from here, so it must not go in the From header — but it
                // is still where the reply belongs, and Reply-To carries no authentication burden.
                'address'  => $configured['address'],
                'name'     => $name,
                'reply_to' => $candidate,
                'source'   => $prefix . '_reply_to',
            ];

        return $identity + [
            'mailbox' => $mailbox?->email,
            'replies_polled' => $this->repliesArePolled($identity),
        ];
    }

    /**
     * The registered mailbox a person sends and receives on.
     *
     * Three ways to find one, narrowest first:
     *
     *  1. A mailbox at their own address — unambiguously theirs.
     *  2. A mailbox assigned to them as default owner — an explicit "this one is Lerato's".
     *  3. The only one there is. Most tenants run a single shared sales inbox, and on that setup
     *     the answer is not in doubt. With two or more and nothing tying either to this person,
     *     it *is* in doubt — picking one at random would send a cadence from an address its author
     *     never chose, so nothing is picked and the page says so instead.
     *
     * Complaint mailboxes are never candidates: `purpose` is NOT NULL defaulting to 'lead', so
     * excluding the complaint boxes leaves exactly the ones outreach may come from.
     */
    private function mailboxFor(?User $user): ?CrmMailbox
    {
        $usable = fn () => CrmMailbox::query()
            ->where('active', true)
            ->where('purpose', '!=', 'complaint');

        if ($user?->email) {
            $own = $usable()->whereRaw('lower(email) = ?', [mb_strtolower(trim($user->email))])->first();

            if ($own) {
                return $own;
            }
        }

        if ($user) {
            $assigned = $usable()->where('default_owner_id', $user->id)->orderBy('id')->first();

            if ($assigned) {
                return $assigned;
            }
        }

        $all = $usable()->limit(2)->get();

        return $all->count() === 1 ? $all->first() : null;
    }

    /**
     * Whether anything is watching the mailbox these replies will land in.
     *
     * Normally true by construction now — the address *is* a registered mailbox. It still has to be
     * asked, because the fallbacks do not go through one: a person with no mailbox sends from their
     * account address, and a tenant with several unattributable mailboxes sends from the shared
     * one. Both are cases where a reply can vanish, and both look identical from the screen.
     *
     * @param  array{address:?string, reply_to:?string}  $identity
     */
    private function repliesArePolled(array $identity): bool
    {
        // Where a reply actually goes: Reply-To when there is one, otherwise the From address.
        $inbox = $identity['reply_to'] ?? $identity['address'];

        if (! $inbox) {
            return false;
        }

        return CrmMailbox::query()
            ->where('active', true)
            ->whereRaw('lower(email) = ?', [mb_strtolower(trim($inbox))])
            ->exists();
    }

    /**
     * The tenant's own sending identity, which is what any other address is measured against.
     *
     * @return array{address:?string, name:?string, source:string}
     */
    public function configured(): array
    {
        if ($from = $this->mail->from()) {
            return ['address' => $from['address'], 'name' => $from['name'], 'source' => 'integration'];
        }

        return [
            'address' => config('mail.from.address'),
            'name'    => config('mail.from.name'),
            'source'  => 'application',
        ];
    }

    /** A person's name as a recipient would recognise it. */
    private function displayName(?User $user): ?string
    {
        $person = $user?->person;

        if (! $person) {
            return null;
        }

        // There is no `users.name`; a person's name lives on their HR record.
        $name = trim(($person->first_name ?? '') . ' ' . ($person->surname ?? ''));

        return $name !== '' ? $name : null;
    }

    /**
     * Whether a From address may be authenticated by whatever sends for the configured one.
     *
     * Same domain, or one a subdomain of the other — DMARC's relaxed alignment, which is the
     * default and what a marketing subdomain relies on.
     */
    private function isAligned(?string $address, ?string $configured): bool
    {
        $a = $this->domainOf($address);
        $b = $this->domainOf($configured);

        if ($a === null || $b === null) {
            return false;
        }

        return $a === $b
            || Str::endsWith($a, '.' . $b)
            || Str::endsWith($b, '.' . $a);
    }

    private function domainOf(?string $address): ?string
    {
        if (! $address || ! str_contains($address, '@')) {
            return null;
        }

        $domain = mb_strtolower(trim(Str::afterLast($address, '@')));

        return $domain !== '' ? $domain : null;
    }
}
