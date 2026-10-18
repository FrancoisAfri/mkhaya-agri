<?php

namespace App\Modules\Procurement\Governance\Services;

use App\Modules\Procurement\Governance\Exceptions\DomainRuleException;
use App\Modules\Procurement\Governance\Support\BidSeal;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Electronic bid intake (§1.1, §7): bidders submit their own bids, sealed until the tender closes,
 * opened once by two named officers.
 *
 * The point of this service is to remove the procurement officer from between the bidder and the
 * record. Previously a bid became data when somebody typed it in, which meant the audit ledger
 * could prove the record was never altered afterwards while proving nothing about whether it
 * matched what the bidder actually sent. Here the bidder writes their own figure, the server stamps
 * the time, and nobody — including the officer running the tender — can read a price before the
 * ceremony that opens them all at once.
 *
 * Read BidSeal for exactly what the sealing does and does not guarantee. In short: early opening is
 * made evident, not impossible, and claiming otherwise would be dishonest.
 */
class BidIntakeService
{
    public function __construct(
        private readonly BidSeal $seal,
        private readonly AuditLedger $audit,
    ) {}

    // ── Invitations ─────────────────────────────────────────────────────────────────────────────

    /**
     * Invite a bidder, returning the invitation including its token.
     *
     * The token is the entire credential, so it is long, random and unique across every tender.
     * Bidders are deliberately not platform users: an account per company that ever bids turns the
     * supplier register into a directory of logins, and puts a password reset between a bidder and a
     * closing deadline.
     *
     * @param  array<string, mixed>  $data
     */
    public function invite(int $companyId, int $reviewId, array $data, ?int $userId = null): object
    {
        $review = $this->review($companyId, $reviewId);

        if ($this->isClosed($review)) {
            throw DomainRuleException::forField(
                'invitation',
                __('procurement_governance.rules.bid.tender_closed'),
            );
        }

        $id = DB::table('procgov_bid_invitations')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'company_id' => $companyId,
            'review_id' => $reviewId,
            'token' => $this->freshToken(),
            'supplier_id' => $data['supplier_id'] ?? null,
            'supplier_name' => $data['supplier_name'],
            'contact_email' => $data['contact_email'] ?? null,
            'contact_person' => $data['contact_person'] ?? null,
            'status' => 'INVITED',
            'invited_at' => Carbon::now(),
            'created_by' => $userId,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        $this->audit->record($companyId, [
            'action' => 'bid_invitation_issued',
            'capability' => 'tender_evaluation',
            'entity_type' => 'procgov_bid_invitations',
            'entity_id' => $id,
            'summary' => sprintf('Invited %s to bid on %s', $data['supplier_name'], $review->reference),
        ]);

        return DB::table('procgov_bid_invitations')->find($id);
    }

    /**
     * Resolve an invitation from its token.
     *
     * Returns null rather than throwing so the portal can answer an unknown token with a plain 404
     * and no hint about whether the tender exists — a token oracle would let anyone enumerate live
     * tenders.
     */
    public function invitationByToken(string $token): ?object
    {
        return DB::table('procgov_bid_invitations')
            ->where('token', $token)
            ->whereNull('deleted_at')
            ->first();
    }

    // ── Submission ──────────────────────────────────────────────────────────────────────────────

    /**
     * Accept a sealed bid.
     *
     * Two things here are deliberately not the caller's to decide. The submission time is the
     * server's, because a bidder asserting their own timestamp is not evidence and an officer
     * deciding one after the fact is worse. Lateness follows from that time against the tender's
     * published closing, rather than being a flag somebody sets.
     *
     * Late bids are accepted and marked, not rejected. Whether a late bid may be considered is a
     * decision for the committee under its own rules; silently dropping it at the door would destroy
     * the evidence that it arrived at all.
     *
     * @param  array<string, mixed>  $payload
     */
    public function submit(int $companyId, object $invitation, array $payload, ?string $ip = null): object
    {
        $review = $this->review($companyId, (int) $invitation->review_id);

        if ($invitation->status === 'WITHDRAWN') {
            throw DomainRuleException::forField('bid', __('procurement_governance.rules.bid.invitation_withdrawn'));
        }

        $existing = DB::table('procgov_bid_submissions')
            ->where('company_id', $companyId)
            ->where('invitation_id', $invitation->id)
            ->first();

        if ($existing) {
            throw DomainRuleException::forField(
                'bid',
                __('procurement_governance.rules.bid.already_submitted'),
            );
        }

        if (! isset($payload['bid_amount']) || ! is_numeric($payload['bid_amount']) || (float) $payload['bid_amount'] <= 0) {
            throw DomainRuleException::forField('bid_amount', __('procurement_governance.rules.bid.amount_required'));
        }

        $now = Carbon::now();
        $isLate = $this->isClosed($review, $now);

        $sealed = $this->seal->seal($this->keyFor($companyId, $review), [
            'bid_amount' => round((float) $payload['bid_amount'], 2),
            'currency' => strtoupper((string) ($payload['currency'] ?? $review->currency ?? 'ZAR')),
            'bid_reference' => $payload['bid_reference'] ?? null,
            'bbbee_level' => $payload['bbbee_level'] ?? null,
            'registration_number' => $payload['registration_number'] ?? null,
            'tax_number' => $payload['tax_number'] ?? null,
            'is_local_content_compliant' => (bool) ($payload['is_local_content_compliant'] ?? false),
            'notes' => $payload['notes'] ?? null,
            'supplier_name' => $invitation->supplier_name,
        ]);

        $submissionId = DB::transaction(function () use ($companyId, $invitation, $review, $sealed, $now, $isLate, $ip) {
            $id = DB::table('procgov_bid_submissions')->insertGetId([
                'uuid' => (string) Str::uuid(),
                'company_id' => $companyId,
                'review_id' => $review->id,
                'invitation_id' => $invitation->id,
                'sealed_payload' => $sealed['ciphertext'],
                'payload_digest' => $sealed['digest'],
                'submitted_at' => $now,
                'received_ip' => $ip,
                'is_late' => $isLate,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('procgov_bid_invitations')->where('id', $invitation->id)->update([
                'status' => 'SUBMITTED',
                'last_seen_at' => $now,
                'updated_at' => $now,
            ]);

            return $id;
        });

        /*
         * The ledger records that a bid arrived, from whom, and when — never its contents. An audit
         * trail that quoted the price would be a hole straight through the seal, readable by
         * everyone with audit access before the tender closed.
         */
        $this->audit->record($companyId, [
            'action' => 'bid_submitted',
            'capability' => 'tender_evaluation',
            'entity_type' => 'procgov_bid_submissions',
            'entity_id' => $submissionId,
            'summary' => sprintf(
                'Sealed bid received from %s for %s%s',
                $invitation->supplier_name,
                $review->reference,
                $isLate ? ' (after closing)' : '',
            ),
        ]);

        return DB::table('procgov_bid_submissions')->find($submissionId);
    }

    /**
     * Attach a document to a sealed bid.
     *
     * The bytes go into the row, as every file on this platform does, but encrypted under the
     * tender's envelope key first. A bid document almost always restates the price the seal is
     * protecting, so storing it in the clear beside a sealed amount would defeat the seal for the
     * cost of opening one PDF.
     *
     * The digest is over the plaintext bytes, so a document swapped in the database fails at
     * opening in exactly the way an altered bid amount does.
     */
    public function attachDocument(int $companyId, object $submission, UploadedFile $file): void
    {
        if ($submission->opened_at !== null) {
            throw DomainRuleException::forField(
                'document',
                __('procurement_governance.rules.document.already_opened'),
            );
        }

        $review = $this->review($companyId, (int) $submission->review_id);
        $bytes = file_get_contents($file->getRealPath());

        /*
         * Sealed first, then stored. The seal is what protects the bid — AES-256-GCM under the
         * tender's own envelope key — and the storage layer encrypts again underneath it with a
         * different key. Two layers, neither depending on the other, and the digest below is over
         * the plaintext so it verifies the bid rather than either encryption.
         */
        $sealed = $this->seal->sealBytes($this->keyFor($companyId, $review), $bytes);

        $path = 'procurement-governance/sealed-bids/' . Str::uuid() . '.sealed';
        Storage::disk('documents')->put($path, $sealed);

        DB::table('procgov_bid_submission_documents')->insert([
            'uuid' => (string) Str::uuid(),
            'company_id' => $companyId,
            'submission_id' => $submission->id,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size_bytes' => strlen($bytes),
            'storage_path' => $path,
            'content_digest' => hash('sha256', $bytes),
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        // Named, never described. The ledger says a document arrived, not what was in it.
        $this->audit->record($companyId, [
            'action' => 'bid_document_attached',
            'capability' => 'tender_evaluation',
            'entity_type' => 'procgov_bid_submissions',
            'entity_id' => (int) $submission->id,
            'summary' => sprintf('Sealed document attached to a bid for %s', $review->reference),
        ]);
    }

    /**
     * The plaintext of a bid document, once the tender has been opened.
     *
     * Refuses before opening rather than returning ciphertext, because a download endpoint that
     * quietly hands back an unreadable file teaches everyone to ignore it — and one that hands back
     * a readable one before the ceremony is the whole failure this design exists to prevent.
     *
     * @return array{name: string, mime: string, bytes: string}
     */
    public function openDocument(int $companyId, int $documentId): array
    {
        $document = DB::table('procgov_bid_submission_documents')
            ->where('company_id', $companyId)
            ->where('id', $documentId)
            ->first();

        if (! $document) {
            throw DomainRuleException::forField('document', __('procurement_governance.rules.document.not_found'));
        }

        $submission = DB::table('procgov_bid_submissions')
            ->where('company_id', $companyId)
            ->where('id', $document->submission_id)
            ->first();

        if (! $submission || $submission->opened_at === null) {
            throw DomainRuleException::forField(
                'document',
                __('procurement_governance.rules.document.not_opened_yet'),
            );
        }

        $review = $this->review($companyId, (int) $submission->review_id);
        $bytes = $this->seal->unsealBytes(
            $this->keyFor($companyId, $review),
            $this->sealedBytes($document),
        );

        if (! hash_equals((string) $document->content_digest, hash('sha256', $bytes))) {
            throw DomainRuleException::forField(
                'document',
                __('procurement_governance.rules.document.digest_mismatch'),
            );
        }

        return [
            'name' => (string) $document->original_name,
            'mime' => (string) ($document->mime_type ?: 'application/octet-stream'),
            'bytes' => $bytes,
        ];
    }

    /** Withdraw an invitation's bid so the bidder may submit again. Both acts stay in the ledger. */
    public function withdraw(int $companyId, int $invitationId, ?int $userId = null): void
    {
        $invitation = DB::table('procgov_bid_invitations')
            ->where('company_id', $companyId)
            ->where('id', $invitationId)
            ->first();

        if (! $invitation) {
            throw DomainRuleException::forField('invitation', __('procurement_governance.rules.bid.invitation_not_found'));
        }

        $submission = DB::table('procgov_bid_submissions')
            ->where('company_id', $companyId)
            ->where('invitation_id', $invitationId)
            ->first();

        if ($submission && $submission->opened_at !== null) {
            throw DomainRuleException::forField(
                'bid',
                __('procurement_governance.rules.bid.already_opened'),
            );
        }

        DB::transaction(function () use ($invitationId, $submission) {
            if ($submission) {
                DB::table('procgov_bid_submissions')->where('id', $submission->id)->delete();
            }

            DB::table('procgov_bid_invitations')->where('id', $invitationId)->update([
                'status' => 'INVITED',
                'updated_at' => Carbon::now(),
            ]);
        });

        $this->audit->record($companyId, [
            'action' => 'bid_withdrawn',
            'capability' => 'tender_evaluation',
            'entity_type' => 'procgov_bid_invitations',
            'entity_id' => $invitationId,
            'summary' => sprintf('Withdrew the sealed bid from %s so it can be resubmitted', $invitation->supplier_name),
        ]);
    }

    // ── The opening ceremony ────────────────────────────────────────────────────────────────────

    /**
     * Why this tender cannot be opened yet, or null when it can.
     *
     * Answered as a reason rather than a boolean so the screen can say which condition is unmet.
     * "Open" being greyed out with no explanation is how a committee ends up ringing IT on the
     * afternoon they are all sitting in a room together.
     */
    public function openingBlockedBecause(int $companyId, object $review, ?int $userId, ?int $witnessId): ?string
    {
        if ($reason = $this->openingPreconditionsBlockedBecause($companyId, $review)) {
            return $reason;
        }

        if (! $userId || ! $witnessId) {
            return __('procurement_governance.rules.opening.needs_two');
        }

        if ($userId === $witnessId) {
            return __('procurement_governance.rules.opening.witness_distinct');
        }

        return null;
    }

    /**
     * Everything that must be true before anyone can open, independent of who is in the room.
     *
     * Split out because the screen needs this answer *before* a witness has been chosen: it decides
     * whether to offer the ceremony at all, and it does so without pretending someone has been
     * nominated. Asking the two-person question with a placeholder would either offer opening on a
     * tender that has not closed, or hide it behind a witness field nobody can satisfy yet.
     */
    public function openingPreconditionsBlockedBecause(int $companyId, object $review): ?string
    {
        if (! $this->isClosed($review)) {
            return __('procurement_governance.rules.opening.not_closed');
        }

        $seal = $this->sealRow($companyId, (int) $review->id);

        if ($seal && $seal->opened_at !== null) {
            return __('procurement_governance.rules.opening.already_opened');
        }

        if (DB::table('procgov_bid_submissions')->where('company_id', $companyId)->where('review_id', $review->id)->count() === 0) {
            return __('procurement_governance.rules.opening.no_bids');
        }

        return null;
    }

    /**
     * Open every sealed bid for a tender, once, in front of a witness.
     *
     * All bids are opened in one act deliberately. Opening them individually would let whoever holds
     * the key read the first price and decide whether to carry on — which is the abuse a sealed-bid
     * process exists to prevent.
     *
     * Each opened bid becomes a row in procgov_bidders, which is where every existing scoring,
     * ranking and recommendation path already reads from. Nothing downstream needs to know bids
     * arrived this way rather than by hand.
     *
     * @return int the number of bids opened
     */
    public function open(int $companyId, int $reviewId, int $userId, int $witnessId): int
    {
        $review = $this->review($companyId, $reviewId);

        if ($reason = $this->openingBlockedBecause($companyId, $review, $userId, $witnessId)) {
            throw DomainRuleException::forField('opening', $reason);
        }

        $key = $this->keyFor($companyId, $review);

        $submissions = DB::table('procgov_bid_submissions')
            ->where('company_id', $companyId)
            ->where('review_id', $reviewId)
            ->whereNull('opened_at')
            ->orderBy('submitted_at')
            ->get();

        /*
         * Every bid is unsealed before anything is written.
         *
         * A digest mismatch on the last bid must not leave the first three already recorded as
         * opened: a partly-opened tender is unrecoverable, because the bids that were opened can
         * never be unseen. So the whole set is verified first and the ceremony either happens or
         * does not.
         */
        $opened = [];

        foreach ($submissions as $submission) {
            $opened[$submission->id] = $this->seal->unseal($key, $submission->sealed_payload, $submission->payload_digest);
        }

        $now = Carbon::now();

        DB::transaction(function () use ($companyId, $reviewId, $review, $submissions, $opened, $userId, $witnessId, $now) {
            foreach ($submissions as $submission) {
                $payload = $opened[$submission->id];

                $bidderId = DB::table('procgov_bidders')->insertGetId([
                    'uuid' => (string) Str::uuid(),
                    'company_id' => $companyId,
                    'review_id' => $reviewId,
                    'supplier_id' => DB::table('procgov_bid_invitations')->where('id', $submission->invitation_id)->value('supplier_id'),
                    'supplier_name' => $payload['supplier_name'] ?? 'Unknown bidder',
                    'registration_number' => $payload['registration_number'] ?? null,
                    'tax_number' => $payload['tax_number'] ?? null,
                    'bid_reference' => $payload['bid_reference'] ?? null,
                    'bid_amount' => $payload['bid_amount'] ?? null,
                    'currency' => $payload['currency'] ?? ($review->currency ?: 'ZAR'),

                    // Carried from the submission, where the server stamped it — not recomputed here
                    // against a closing time an officer may have moved since.
                    'submitted_at' => $submission->submitted_at,
                    'is_late' => $submission->is_late,

                    'bbbee_level' => $payload['bbbee_level'] ?? null,
                    'is_local_content_compliant' => $payload['is_local_content_compliant'] ?? false,
                    'is_disqualified' => false,
                    'status' => 'SUBMITTED',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                DB::table('procgov_bid_submissions')->where('id', $submission->id)->update([
                    'opened_at' => $now,
                    'bidder_id' => $bidderId,
                    'updated_at' => $now,
                ]);
            }

            DB::table('procgov_tender_seals')
                ->where('company_id', $companyId)
                ->where('review_id', $reviewId)
                ->update([
                    'opened_at' => $now,
                    'opened_by' => $userId,
                    'witnessed_by' => $witnessId,
                    'submissions_opened' => $submissions->count(),
                    'updated_at' => $now,
                ]);
        });

        $this->audit->record($companyId, [
            'action' => 'bids_opened',
            'capability' => 'tender_evaluation',
            'entity_type' => 'procgov_tender_reviews',
            'entity_id' => $reviewId,
            'summary' => sprintf(
                'Opened %d sealed bid(s) for %s, witnessed',
                $submissions->count(),
                $review->reference,
            ),
        ]);

        return $submissions->count();
    }

    // ── Internals ───────────────────────────────────────────────────────────────────────────────

    /**
     * The tender's envelope key, created on first use.
     *
     * Created lazily rather than at tender creation so that tenders raised before this feature
     * existed, and tenders that never take electronic bids, carry no key at all.
     */
    private function keyFor(int $companyId, object $review): string
    {
        $seal = $this->sealRow($companyId, (int) $review->id);

        if ($seal) {
            return $this->seal->unwrapKey($seal->key_ciphertext);
        }

        $key = $this->seal->generateKey();

        DB::table('procgov_tender_seals')->insert([
            'uuid' => (string) Str::uuid(),
            'company_id' => $companyId,
            'review_id' => $review->id,
            'key_ciphertext' => $this->seal->wrapKey($key),
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        return $key;
    }

    public function sealRow(int $companyId, int $reviewId): ?object
    {
        return DB::table('procgov_tender_seals')
            ->where('company_id', $companyId)
            ->where('review_id', $reviewId)
            ->first();
    }

    private function review(int $companyId, int $reviewId): object
    {
        $review = DB::table('procgov_tender_reviews')
            ->where('company_id', $companyId)
            ->where('id', $reviewId)
            ->whereNull('deleted_at')
            ->first();

        if (! $review) {
            throw DomainRuleException::forField('tender', __('procurement_governance.rules.bid.tender_not_found'));
        }

        return $review;
    }

    /**
     * Has the tender closed?
     *
     * A tender with no closing time has not closed. That is the safe reading: treating a missing
     * deadline as "closed" would mark every bid late and let bids be opened immediately, which is
     * the opposite of what a sealed process is for.
     */
    private function isClosed(object $review, ?Carbon $at = null): bool
    {
        if (empty($review->tender_closing_at)) {
            return false;
        }

        return ($at ?? Carbon::now())->greaterThan(Carbon::parse($review->tender_closing_at));
    }

    private function freshToken(): string
    {
        do {
            $token = Str::random(64);
        } while (DB::table('procgov_bid_invitations')->where('token', $token)->exists());

        return $token;
    }

    /**
     * The sealed ciphertext for a document, from the disk or the column it used to live in.
     *
     * The fallback keeps bids sealed before the move readable. It matters more here than elsewhere:
     * a bid that cannot be unsealed at opening is a bid that cannot be evaluated, and the tender
     * cannot simply be re-run.
     */
    private function sealedBytes(object $document): string
    {
        if (! blank($document->storage_path ?? null)) {
            try {
                $disk = Storage::disk('documents');

                if ($disk->exists($document->storage_path)) {
                    return (string) $disk->get($document->storage_path);
                }
            } catch (\Throwable $e) {
                Log::error('Sealed bid could not be read from storage.', [
                    'document' => $document->uuid ?? null, 'error' => $e->getMessage(),
                ]);
            }
        }

        return base64_decode((string) ($document->sealed_data ?? ''), true) ?: '';
    }
}
