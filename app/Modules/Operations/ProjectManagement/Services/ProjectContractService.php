<?php

namespace App\Modules\Operations\ProjectManagement\Services;

use App\Modules\Operations\ProjectManagement\Models\ContractSignature;
use App\Modules\Operations\ProjectManagement\Models\ContractTemplate;
use App\Modules\Operations\ProjectManagement\Models\ContractVersion;
use App\Modules\Operations\ProjectManagement\Models\Project;
use App\Modules\Operations\ProjectManagement\Models\ProjectContract;
use App\Modules\Operations\ProjectManagement\Models\ProjectMember;
use App\Modules\Operations\ProjectManagement\Notifications\ContractAwaitingSignatureNotification;
use App\Modules\Operations\ProjectManagement\Support\ContractBody;
use App\Modules\Operations\ProjectManagement\Support\Invoicing\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The whole life of a project contract, inside Project Management.
 *
 * Draft it — from a template or from nothing — amend the clauses that do not apply to this
 * particular contractor, send it for review, sign it. None of that leaves this module, which is
 * the point: a tenant may own Project Management and nothing else.
 *
 * ── Amendment is a version, never an edit ───────────────────────────────────
 *
 * Every change writes a new `pm_contract_versions` row with the body as it then read and a hash of
 * it. Nothing rewrites a version once written. That is what lets the register answer "what did I
 * sign", and what makes `amend()` safe to offer on a contract that is already out for signature:
 * the pending signatures are withdrawn, loudly, rather than silently carried onto wording the
 * signer never saw.
 *
 * ── Review is the platform's approval engine, not a second one ──────────────
 *
 * `ApprovalService` already routes by subject type with per-tenant flows, steps, thresholds and
 * escalation. Contracts join it as one more subject. A company that has designed no contract flow
 * gets no review step and signs directly — which is what a two-person practice actually wants —
 * and one that needs three desks configures them in PM Setup → Approvals without any code.
 */
class ProjectContractService
{
    public function __construct(
        private readonly ApprovalService $approvals,
        private readonly AuditLogger $audit,
    ) {
    }

    // ── Drafting ────────────────────────────────────────────────────────────

    /**
     * Raise a contract on a project, optionally starting from a template.
     *
     * With no template the body is whatever the manager typed, including nothing at all — "from
     * scratch" was an explicit requirement, and a blank contract you then write into is a normal
     * way to work.
     */
    public function draft(
        Project $project,
        ?ProjectMember $member,
        array $attributes,
        ?ContractTemplate $template = null,
    ): ProjectContract {
        return DB::transaction(function () use ($project, $member, $attributes, $template) {
            $body = $template
                ? $this->fillPlaceholders((string) $template->body, $project, $member, $attributes)
                : ($attributes['body'] ?? null);

            $body = ContractBody::sanitize($body);

            $contract = ProjectContract::create([
                'uuid'              => (string) Str::uuid(),
                'project_id'        => $project->id,
                'project_member_id' => $member?->id,
                'template_id'       => $template?->id,
                'contract_number'   => $this->nextContractNumber($project),
                'title'             => ($attributes['title'] ?? null)
                    ?: trim(($member?->display_name ?? __('project_management.contracts.untitled_party')) . ' — ' . $project->name),
                'summary'           => $attributes['summary'] ?? null,
                'status'            => 'draft',
                'contract_value'    => $attributes['contract_value'] ?? 0,
                'currency'          => $attributes['currency'] ?? $project->currency ?? 'ZAR',
                'start_date'        => $attributes['start_date'] ?? null,
                'end_date'          => $attributes['end_date'] ?? null,
                'current_version'   => 1,
                'created_by'        => auth()->id(),
            ]);

            $this->writeVersion($contract, 1, $body, __('project_management.contracts.first_version_note'));

            $this->audit->recordAction($contract, 'contract.drafted', [
                'project'  => $project->code,
                'template' => $template?->name,
            ]);

            return $contract->fresh();
        });
    }

    /**
     * Amend the wording, or the commercial terms, as a new version.
     *
     * This is the negotiation loop. A contractor asks for clause 7 to go; the manager strikes it;
     * everybody who had been asked to sign is asked again, against the new text.
     */
    public function amend(ProjectContract $contract, array $attributes, ?string $changeNote = null): ProjectContract
    {
        if ($contract->isClosed()) {
            throw new InvalidArgumentException(__('project_management.contracts.closed'));
        }

        return DB::transaction(function () use ($contract, $attributes, $changeNote) {
            $withdrew = $this->withdrawPendingSignatures($contract);

            $next = ((int) $contract->versions()->max('version_no')) + 1;

            $current = $contract->currentVersion()?->body;

            $body = array_key_exists('body', $attributes)
                ? ContractBody::sanitize($attributes['body'])
                : $current;

            $this->assertNothingStructuralWasLost($current, $body);

            $this->writeVersion($contract, $next, $body, $changeNote);

            $contract->update(array_filter([
                'title'          => $attributes['title'] ?? null,
                'summary'        => $attributes['summary'] ?? null,
                'contract_value' => $attributes['contract_value'] ?? null,
                'start_date'     => $attributes['start_date'] ?? null,
                'end_date'       => $attributes['end_date'] ?? null,
            ], fn ($v) => $v !== null) + [
                'current_version' => $next,
                // Back to draft: an amended contract has not been reviewed and has not been signed,
                // whatever it had been before. Leaving it 'approved' would let a change slip past
                // the people whose approval was for different words.
                'status'          => 'draft',
            ]);

            $this->audit->recordAction($contract, 'contract.amended', [
                'version'             => $next,
                'note'                => $changeNote,
                'withdrew_signatures' => $withdrew,
            ]);

            return $contract->fresh();
        });
    }

    /**
     * Refuse an amendment that would silently delete a table.
     *
     * The editor these bodies are edited in has no table support, so loading a contract into it
     * and saving flattens every table into a run of prose — a rate schedule and the signature
     * block at the foot of an agreement, gone, in the stored document. That happened to a live
     * contract here: v1 and v2 carried the signature table, v3 did not, and nothing said so.
     *
     * Losing structure from a legal document is not something to warn about after the fact. Until
     * the editor can represent a table, an amendment that would drop one is refused and the person
     * is told to change the wording elsewhere in the contract instead.
     */
    private function assertNothingStructuralWasLost(?string $before, ?string $after): void
    {
        $had = substr_count(strtolower((string) $before), '<table');
        $has = substr_count(strtolower((string) $after), '<table');

        if ($has < $had) {
            throw new InvalidArgumentException(
                __('project_management.contracts.amendment_would_drop_tables', ['n' => $had - $has])
            );
        }
    }

    // ── Review ──────────────────────────────────────────────────────────────

    /**
     * Send it round for approval.
     *
     * Returns the contract either way. Where the tenant has designed no contract flow — or the
     * value falls under the bar they set — `ApprovalService` returns null and the contract goes
     * straight to `approved`, ready to sign. That is not a bypass; it is the company's own stated
     * position that a contract this size does not need a committee.
     */
    public function submitForReview(ProjectContract $contract, ?string $note = null): ProjectContract
    {
        if (! $contract->isEditable()) {
            throw new InvalidArgumentException(__('project_management.contracts.not_submittable'));
        }

        return DB::transaction(function () use ($contract, $note) {
            $request = $this->approvals->submit('contract', $contract, $contract->project, [
                'label'    => $contract->contract_number . ' — ' . $contract->title,
                'amount'   => (float) $contract->contract_value,
                'currency' => $contract->currency,
                'note'     => $note,
            ]);

            $contract->update(['status' => $request ? 'in_review' : 'approved']);

            $this->audit->recordAction($contract, 'contract.submitted', [
                'routed' => $request !== null,
            ]);

            return $contract->fresh();
        });
    }

    /** The approval engine said yes. Called from ApprovalsController's subject map. */
    public function applyApproval(ProjectContract $contract): ProjectContract
    {
        if ($contract->status !== 'in_review') {
            throw new InvalidArgumentException(__('project_management.contracts.not_in_review'));
        }

        $contract->update(['status' => 'approved']);
        $this->audit->recordAction($contract, 'contract.approved');

        return $contract->fresh();
    }

    /** The approval engine said no. The contract returns to the desk it came from. */
    public function applyRejection(ProjectContract $contract, string $reason): ProjectContract
    {
        if ($contract->status !== 'in_review') {
            throw new InvalidArgumentException(__('project_management.contracts.not_in_review'));
        }

        $contract->update(['status' => 'rejected']);
        $this->audit->recordAction($contract, 'contract.rejected', ['reason' => $reason]);

        return $contract->fresh();
    }

    /**
     * Reopen a signed contract so it can be amended.
     *
     * A signed agreement is closed to everybody, including the practice — that is what makes a
     * signature mean anything. But agreements do need changing, so this is the deliberate way
     * back in: it is not a quiet edit. It writes a version recording why, returns the contract to
     * draft, and tells every party who signed that the document they agreed to is being changed.
     *
     * The signatures already given are left exactly as they are. They are the record of what was
     * agreed on the old wording, and the content hash on each one is what will show them as stale
     * against the new version rather than silently carrying forward.
     */
    public function reopenForAmendment(ProjectContract $contract, string $reason): ProjectContract
    {
        if ($contract->status !== 'signed') {
            throw new InvalidArgumentException(__('project_management.contracts.not_reopenable'));
        }

        if (trim($reason) === '') {
            throw new InvalidArgumentException(__('project_management.contracts.reopen_reason_required'));
        }

        return DB::transaction(function () use ($contract, $reason) {
            $next = ((int) $contract->versions()->max('version_no')) + 1;

            $this->writeVersion(
                $contract,
                $next,
                $contract->currentVersion()?->body,
                __('project_management.contracts.reopened_note', ['reason' => trim($reason)]),
            );

            $contract->update(['current_version' => $next, 'status' => 'draft']);

            $this->audit->recordAction($contract, 'contract.reopened', ['reason' => $reason]);

            $this->notifyPartiesOfReopening($contract->fresh(), trim($reason));

            return $contract->fresh();
        });
    }

    /** Everybody who signed, told that what they agreed to is being changed. */
    private function notifyPartiesOfReopening(ProjectContract $contract, string $reason): void
    {
        $userIds = ContractSignature::where('contract_id', $contract->id)
            ->whereNotNull('user_id')
            ->pluck('user_id')
            ->unique()
            ->all();

        if ($userIds === []) {
            return;
        }

        $recipients = \App\Core\User\Models\User::query()
            ->withoutGlobalScope('company')
            ->whereIn('id', $userIds)
            ->get();

        \Illuminate\Support\Facades\Notification::send(
            $recipients,
            new \App\Modules\Operations\ProjectManagement\Notifications\ContractReopenedNotification(
                $contract,
                $contract->project,
                $reason,
            )
        );
    }

    // ── The document ────────────────────────────────────────────────────────

    /**
     * Render the contract to PDF, once, at the moment it goes out.
     *
     * Those bytes are what every party signs. Re-rendering per signer would mean two people
     * putting their name to two different documents the moment a template, a logo or a font moved
     * in between — and the hash that proves what was signed would prove nothing.
     *
     * The value is included only when the contract has one; a contractor is a party to the price
     * they agreed, so it is not redacted here the way it is on a delivery screen.
     */
    public function generateDocument(ProjectContract $contract): ProjectContract
    {
        $version = $contract->currentVersion();

        $signatories = $contract->signatures()
            ->whereIn('status', ['pending', 'signed'])
            ->orderBy('sign_order')
            ->get()
            ->map(fn (ContractSignature $s) => ['name' => $s->signer_name, 'party' => $s->party])
            ->values()->all();

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('pdf.contracts.project-contract', [
            'contract'       => $contract,
            'project'        => $contract->project,
            'contractorName' => $contract->member?->display_name,
            'showValue'      => (float) $contract->contract_value > 0,
            'body'           => ContractBody::sanitize($version?->body) ?? '',
            'signatories'    => $signatories,
            'generatedAt'    => now()->toDateTimeString(),
        ])->setPaper('a4');

        $binary = $pdf->output();

        $contract->update([
            'document_path'         => $contract->writeDocument($binary),
            'document_data'         => null,
            'document_name'         => str_replace('/', '-', $contract->contract_number) . '.pdf',
            'document_sha256'       => hash('sha256', $binary),
            'document_generated_at' => now(),
            // A new issue supersedes whatever was executed against the old wording.
            'signed_document_path'   => null,
            'signed_document_data'   => null,
            'signed_document_sha256' => null,
            'signed_document_at'     => null,
        ]);

        $this->audit->recordAction($contract, 'contract.rendered', [
            'version' => $version?->version_no,
        ]);

        return $contract->fresh();
    }

    /**
     * Keep the executed copy — the PDF with every signature burned into the page.
     *
     * Produced in the browser by the same filler Tender Pursuit uses, because that is where the
     * signature is placed on the page. What arrives here is checked for being a PDF and hashed;
     * the hash is the answer to "has this been altered since it was signed".
     */
    public function storeSignedDocument(ProjectContract $contract, string $base64Pdf): ProjectContract
    {
        $raw = preg_replace('#^data:[^;]+;base64,#', '', trim($base64Pdf));
        $binary = base64_decode((string) $raw, true) ?: '';

        if ($binary === '' || ! str_starts_with($binary, '%PDF')) {
            throw new InvalidArgumentException(__('project_management.contracts.bad_signed_pdf'));
        }

        $contract->update([
            'signed_document_path'   => $contract->writeDocument($binary, signed: true),
            'signed_document_data'   => null,
            'signed_document_sha256' => hash('sha256', $binary),
            'signed_document_at'     => now(),
        ]);

        $this->audit->recordAction($contract, 'contract.executed', [
            'sha256' => hash('sha256', $binary),
        ]);

        return $contract->fresh();
    }

    // ── Signature ───────────────────────────────────────────────────────────

    /**
     * Ask the parties to sign the version that currently stands.
     *
     * The contractor signs first and we countersign, and unlike the E-Sign envelope this module
     * used to lean on, that order is actually enforced — see `assertItIsTheirTurn()`. A contract we
     * signed first is an offer, not an agreement.
     *
     * @param  array{name:string, email:string, user_id?:int|null}  $ourSignatory
     */
    public function sendForSignature(
        ProjectContract $contract,
        ProjectMember $member,
        array $ourSignatory,
        ?Request $request = null,
    ): ProjectContract {
        if (! in_array($contract->status, ['approved', 'draft'], true)) {
            throw new InvalidArgumentException(__('project_management.contracts.not_sendable'));
        }

        $version = $contract->currentVersion();

        if ($version === null) {
            throw new InvalidArgumentException(__('project_management.contracts.no_body_to_sign'));
        }

        // Signing is keyed to a user account, so a counterparty without one can never sign — the
        // contract goes out and sits at "awaiting" forever with no way for anybody to move it.
        // Three live contracts reached exactly that state before this check existed.
        if (blank($member->user_id)) {
            throw new InvalidArgumentException(__('project_management.contracts.counterparty_has_no_login'));
        }

        return DB::transaction(function () use ($contract, $member, $ourSignatory, $version) {
            $this->withdrawPendingSignatures($contract);

            $envelope = (string) Str::uuid();

            ContractSignature::create([
                'uuid'          => (string) Str::uuid(),
                'contract_id'   => $contract->id,
                'version_id'    => $version->id,
                'envelope_uuid' => $envelope,
                'sign_order'    => 1,
                'party'         => 'contractor',
                'user_id'       => $member->user_id,
                'signer_name'   => $member->display_name,
                'signer_email'  => $member->email,
                'status'        => 'pending',
            ]);

            ContractSignature::create([
                'uuid'          => (string) Str::uuid(),
                'contract_id'   => $contract->id,
                'version_id'    => $version->id,
                'envelope_uuid' => $envelope,
                'sign_order'    => 2,
                'party'         => 'company',
                'user_id'       => $ourSignatory['user_id'] ?? null,
                'signer_name'   => $ourSignatory['name'],
                'signer_email'  => $ourSignatory['email'],
                'status'        => 'pending',
            ]);

            $contract->update(['status' => 'out_for_signature']);

            $this->audit->recordAction($contract, 'contract.sent', [
                'version' => $version->version_no,
            ]);

            // Rendered now, not when somebody first opens it: every party has to sign the same
            // bytes, and a document re-rendered per signer is two documents.
            $this->generateDocument($contract->fresh());

            $this->notifySignatory($contract, $member, $ourSignatory);

            return $contract->fresh();
        });
    }

    /**
     * Sign, as one of the named parties.
     *
     * The hash of what was in front of them is copied here and never recomputed, so amending the
     * contract afterwards shows as a stale signature rather than quietly inheriting the tick.
     */
    public function sign(
        ContractSignature $signature,
        string $typedName,
        ?Request $request = null,
        ?string $drawing = null,
    ): ContractSignature {
        if ($signature->status !== 'pending') {
            throw new InvalidArgumentException(__('project_management.contracts.already_decided'));
        }

        // A name is required either way. A drawing is a signature, but the typed name is what makes
        // the record legible six months later when nobody can read the squiggle.
        if (trim($typedName) === '') {
            throw new InvalidArgumentException(__('project_management.contracts.name_required'));
        }

        $drawing = self::cleanDrawing($drawing);

        $this->assertItIsTheirTurn($signature);

        return DB::transaction(function () use ($signature, $typedName, $request, $drawing) {
            $signature->update([
                'status'         => 'signed',
                'signed_at'      => now(),
                'typed_name'     => trim($typedName),
                'signature_type' => $drawing === null ? 'typed' : 'drawn',
                'signature_data' => null,
                'signature_path' => $drawing === null ? null : \App\Core\Storage\StoredImage::putBytes(
                    base64_decode((string) preg_replace('#^data:[^;]+;base64,#', '', $drawing), true) ?: '',
                    'project-management/signatures',
                ),
                'content_sha256' => $signature->version?->content_sha256,
                'ip_address'     => $request?->ip(),
                'user_agent'     => $request?->userAgent(),
            ]);

            $contract = $signature->contract;

            $this->audit->recordAction($contract, 'contract.signed', [
                'party' => $signature->party,
                'by'    => $signature->signer_name,
            ]);

            $outstanding = ContractSignature::where('contract_id', $contract->id)
                ->where('envelope_uuid', $signature->envelope_uuid)
                ->where('status', 'pending')
                ->exists();

            if (! $outstanding) {
                $contract->update(['status' => 'signed']);
                $this->audit->recordAction($contract, 'contract.completed');
            }

            return $signature->fresh();
        });
    }

    public function decline(ContractSignature $signature, string $reason, ?Request $request = null): ContractSignature
    {
        if ($signature->status !== 'pending') {
            throw new InvalidArgumentException(__('project_management.contracts.already_decided'));
        }

        if (trim($reason) === '') {
            // A refusal without a reason is one the other side cannot act on, so they resend the
            // same document and the loop repeats.
            throw new InvalidArgumentException(__('project_management.contracts.decline_reason_required'));
        }

        return DB::transaction(function () use ($signature, $reason, $request) {
            $signature->update([
                'status'         => 'declined',
                'decline_reason' => trim($reason),
                'signed_at'      => now(),
                'ip_address'     => $request?->ip(),
                'user_agent'     => $request?->userAgent(),
            ]);

            // Declining ends this round for everybody: asking the second party to sign a document
            // the first has refused is asking them to agree with nobody.
            ContractSignature::where('contract_id', $signature->contract_id)
                ->where('envelope_uuid', $signature->envelope_uuid)
                ->where('status', 'pending')
                ->update(['status' => 'withdrawn', 'updated_at' => now()]);

            $signature->contract->update(['status' => 'declined']);

            $this->audit->recordAction($signature->contract, 'contract.declined', [
                'party'  => $signature->party,
                'reason' => $reason,
            ]);

            return $signature->fresh();
        });
    }

    /**
     * A drawn signature, or null.
     *
     * Only a PNG data URI is accepted, and only within a size a signature could plausibly need.
     * The value is rendered back into an <img>, so anything else — an SVG carrying script, a
     * `javascript:` URI, a megabyte of who-knows-what — is refused rather than stored and trusted.
     */
    private static function cleanDrawing(?string $drawing): ?string
    {
        if ($drawing === null || trim($drawing) === '') {
            return null;
        }

        $drawing = trim($drawing);

        if (! preg_match('#^data:image/png;base64,[A-Za-z0-9+/=]+$#', $drawing)) {
            throw new InvalidArgumentException(__('project_management.contracts.bad_signature_image'));
        }

        // ~1.5 MB of base64 is far more than a signature pad produces and well under any limit
        // that would truncate the column.
        if (strlen($drawing) > 1_500_000) {
            throw new InvalidArgumentException(__('project_management.contracts.signature_too_large'));
        }

        return $drawing;
    }

    /** The signature this user is currently being asked for, if any. */
    public function pendingFor(ProjectContract $contract, ?int $userId): ?ContractSignature
    {
        if ($userId === null) {
            return null;
        }

        return $contract->signatures()
            ->where('status', 'pending')
            ->where('user_id', $userId)
            ->orderBy('sign_order')
            ->first();
    }

    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Signing order, enforced rather than merely recorded.
     *
     * The E-Sign module stores `signing_order` and then emails everybody at once, so either party
     * can go first. Here the earlier party must have signed before a later one may.
     */
    private function assertItIsTheirTurn(ContractSignature $signature): void
    {
        $earlierOutstanding = ContractSignature::where('contract_id', $signature->contract_id)
            ->where('envelope_uuid', $signature->envelope_uuid)
            ->where('sign_order', '<', $signature->sign_order)
            ->where('status', 'pending')
            ->exists();

        if ($earlierOutstanding) {
            throw new InvalidArgumentException(__('project_management.contracts.not_your_turn'));
        }
    }

    /** @return int how many were withdrawn */
    private function withdrawPendingSignatures(ProjectContract $contract): int
    {
        return ContractSignature::where('contract_id', $contract->id)
            ->where('status', 'pending')
            ->update(['status' => 'withdrawn', 'updated_at' => now()]);
    }

    private function writeVersion(
        ProjectContract $contract,
        int $versionNo,
        ?string $body,
        ?string $changeNote,
    ): ContractVersion {
        return ContractVersion::create([
            'uuid'           => (string) Str::uuid(),
            'contract_id'    => $contract->id,
            'version_no'     => $versionNo,
            'body'           => $body,
            'change_note'    => $changeNote,
            'content_sha256' => ContractVersion::hash($body),
            'created_by'     => auth()->id(),
        ]);
    }

    private function notifySignatory(ProjectContract $contract, ProjectMember $member, array $ourSignatory): void
    {
        $userId = $ourSignatory['user_id'] ?? null;

        if ($userId === null) {
            return;
        }

        \App\Core\User\Models\User::query()->find($userId)?->notify(
            new ContractAwaitingSignatureNotification(
                $contract,
                $contract->project,
                $member->display_name ?? '',
            )
        );
    }

    /** `PRJ-CODE/C001` — readable, and unique per project without a sequence table. */
    private function nextContractNumber(Project $project): string
    {
        $count = ProjectContract::withTrashed()->where('project_id', $project->id)->count();

        return sprintf('%s/C%03d', $project->code ?: 'PRJ', $count + 1);
    }

    /**
     * The handful of facts a template may ask for. Unknown placeholders are left alone.
     *
     * Every value is escaped: a template body is HTML, and a contractor's own name is text they
     * chose, on the register or through the portal. Sanitising on render is not enough on its own —
     * escape on write as well, or the stored document carries the payload.
     */
    private function fillPlaceholders(
        string $body,
        Project $project,
        ?ProjectMember $member,
        array $attributes,
    ): string {
        $escape = static fn (?string $value): string => htmlspecialchars(
            (string) $value,
            ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5,
            'UTF-8',
        );

        return strtr($body, [
            '{{contractor_name}}'  => $escape($member?->display_name),
            '{{contractor_email}}' => $escape($member?->email),
            '{{project_name}}'     => $escape($project->name),
            '{{project_code}}'     => $escape($project->code),
            '{{contract_value}}'   => $escape(Money::money($attributes['contract_value'] ?? '0')),
            '{{start_date}}'       => $escape($attributes['start_date'] ?? ''),
            '{{end_date}}'         => $escape($attributes['end_date'] ?? ''),
        ]);
    }
}
