<?php

namespace App\Modules\Operations\Sheq\Services;

use App\Modules\Operations\Sheq\Events\EventPublisher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Contractor management (§5.9).
 *
 * **Access status is computed, never typed** (SHEQ-CON-2). A single value — COMPLIANT, EXPIRING or
 * BLOCKED — derived from the compliance file every time it is asked for. A stored status is a
 * status that goes stale overnight, and the gate at the boot of the site needs to be right today.
 *
 * **A missing mandatory document blocks exactly as an expired one does.** "We never received their
 * letter of good standing" and "their letter expired" put the host in the same position under
 * COIDA; treating absence as acceptable would make the register describe a state of affairs nobody
 * verified.
 *
 * **Contractor incidents roll up separately and in aggregate** (SHEQ-CON-4), because the OHS Act
 * s24 annual statistics include contractors on the employer's premises, and a report that cannot
 * separate them cannot be filed.
 */
class ContractorService
{
    public function __construct(
        private readonly EventPublisher $events,
        private readonly \App\Modules\Operations\Sheq\Approvals\ApprovalService $approvals,
    ) {}

    /** Days before expiry at which a document — and so the contractor — reads as EXPIRING. */
    private const EXPIRING_WINDOW = 30;

    /** @return array{id:int, uuid:string} */
    public function register(int $companyId, array $input, ?int $actorId = null): array
    {
        $uuid = (string) Str::uuid();

        $id = DB::table('sheq_contractors')->insertGetId([
            'uuid' => $uuid,
            'company_id' => $companyId,
            'name' => $input['name'],
            'registration_number' => $input['registration_number'] ?? null,
            'trade' => $input['trade'] ?? null,
            'contact_name' => $input['contact_name'] ?? null,
            'contact_email' => $input['contact_email'] ?? null,
            'contact_phone' => $input['contact_phone'] ?? null,
            'site_id' => $input['site_id'] ?? null,
            'supplier_id' => $input['supplier_id'] ?? null,
            // Blocked until the file proves otherwise — the safe default for a gate.
            'access_status' => 'BLOCKED',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->audit($companyId, $actorId, 'contractor.registered', $id, null, ['name' => $input['name']]);

        // Raised at registration, so an approver has the time before anybody arrives on site.
        $this->approvals->openIfConfigured($companyId, [
            'subject_type' => 'CONTRACTOR',
            'subject_id' => $id,
            'subject_label' => $input['name'],
            'subject_subtype' => $input['trade'] ?? null,
            'site_id' => $input['site_id'] ?? null,
            'requested_by' => (int) ($actorId ?? 0),
        ]);

        // The name comes back because a caller announcing "X registered" should not have to
        // reconstruct what this method just wrote — assuming it was there is what produced a 500.
        return ['id' => $id, 'uuid' => $uuid, 'name' => $input['name']];
    }

    /** Add or replace a document in the compliance file (SHEQ-CON-1). */
    public function attachDocument(int $companyId, string $contractorUuid, array $input, ?int $actorId = null): int
    {
        $contractor = $this->find($companyId, $contractorUuid);

        // Built as an array rather than inline, because what was just written has to be read
        // back below to close the previous version's chain (CON-24) — and re-deriving the
        // version number there would be a second opinion about the same thing.
        $values = [
            'uuid' => (string) Str::uuid(),
            'company_id' => $companyId,
            'contractor_id' => $contractor->id,
            'document_type' => $input['document_type'],
            'title' => $input['title'] ?? null,
            'reference' => $input['reference'] ?? null,
            'issuing_body' => $input['issuing_body'] ?? null,
            'issued_on' => $input['issued_on'] ?? null,
            'expires_on' => $input['expires_on'] ?? null,
            'file_path' => $input['file_path'] ?? null,
            'sha256' => $input['sha256'] ?? null,
            'is_mandatory' => $input['is_mandatory'] ?? true,
            /*
             * CON-8. Null warning days means the tenant's default applies, so moving the default
             * moves every document that never had an opinion of its own; a null site or activity
             * list means everywhere and all activities, which is what every document filed before
             * these columns existed carries.
             */
            'warning_days' => $input['warning_days'] ?? null,
            'site_id' => $input['site_id'] ?? null,
            'activities' => empty($input['activities']) ? null
                : json_encode(array_values(array_map(
                    fn ($a) => strtoupper((string) $a), (array) $input['activities']))),
            /*
             * ACCEPTED unless the caller says otherwise.
             *
             * The alternative — defaulting to SUPPLIED so everything is reviewed — would block
             * every contractor on this installation the day the column shipped, and would make
             * every existing upload path produce documents that satisfy nothing. A tenant that
             * wants documents checked before they count sets the status when filing.
             */
            'status' => strtoupper((string) ($input['status'] ?? ContractorDocumentEngine::ACCEPTED)),
            'version' => $this->nextVersion($companyId, (int) $contractor->id,
                (string) $input['document_type']),
            'replaces_document_id' => $this->currentDocumentId($companyId, (int) $contractor->id,
                (string) $input['document_type']),
            'created_at' => now(),
            'updated_at' => now(),
        ];

        $id = DB::table('sheq_contractor_documents')->insertGetId($values);

        /*
         * The version being replaced stops being in force, here, at this moment (CON-24).
         *
         * Without this both versions stay live and two things break. The compliance file has to
         * choose between them and chooses the furthest expiry — so a date typed as 2030 instead
         * of 2026 beats the correct certificate filed afterwards, and nothing short of deleting
         * the evidence corrects it. And "which version was in force in March" has no answer,
         * because nothing recorded when version 1 stopped counting.
         *
         * Only when the replacement actually counts. A renewal filed for review has not replaced
         * anything yet: superseding the live certificate the moment a contractor uploads its
         * successor would leave them with no valid document at all for however long the review
         * takes, which is a gate closing on somebody for being early.
         */
        $replaced = $values['replaces_document_id'] ?? null;

        if ($replaced !== null
            && $values['status'] === ContractorDocumentEngine::ACCEPTED) {
            DB::table('sheq_contractor_documents')
                ->where('company_id', $companyId)->where('id', $replaced)
                ->whereNull('superseded_at')
                ->update([
                    'superseded_at' => now(),
                    'superseded_by' => $actorId,
                    'supersede_reason' => sprintf('Replaced by version %d.', $values['version']),
                    'updated_at' => now(),
                ]);
        }

        $this->refreshAccessStatus($companyId, $contractorUuid, [], $actorId);

        $this->audit($companyId, $actorId, 'contractor.document_attached', $contractor->id, null, [
            'type' => $input['document_type'], 'expires_on' => $input['expires_on'] ?? null,
            'version' => $values['version'], 'replaces' => $replaced,
        ]);

        return $id;
    }

    /**
     * The state of the compliance file, and the single access value that follows from it.
     *
     * @param  list<string>  $requiredTypes  what this host demands before anyone comes on site
     * @return array{status:string, blocking:list<array<string,mixed>>, expiring:list<array<string,mixed>>, documents:list<array<string,mixed>>}
     */
    /**
     * What this host demands of a contractor before anybody comes on site, at the site where the
     * work is.
     *
     * Read here rather than passed in by every caller, because they did not pass it. `register()`
     * sets a contractor BLOCKED deliberately — deny by default is right — and `attachDocument()`
     * then recomputed the status with an empty required list, which would have read as compliant
     * the moment any document at all was attached. One caller passing the real list and another
     * passing none meant the gate answered differently depending on which screen you came from.
     *
     * An explicitly empty setting still means "nothing demanded": that is a decision a host may
     * make, and it is distinguishable from never having been asked because the column is seeded
     * with the usual four.
     *
     * @return list<string>
     */
    public function configuredRequiredTypes(int $companyId, ?int $siteId = null): array
    {
        if (! Schema::hasColumn('sheq_settings', 'contractor_required_documents')) {
            return [];
        }

        /*
         * A site's own list wins over the company's, and NULL is not an empty list.
         *
         * A refinery that demands a permit-to-work procedure has no business demanding it of a
         * contractor painting an office, so the bar is set per site. NULL means the site has not
         * been asked and inherits; `[]` means it has been asked and demands nothing. Collapsing
         * those two would make every new site demand nothing the moment it was created.
         */
        if ($siteId !== null && Schema::hasColumn('sheq_sites', 'contractor_required_documents')) {
            $site = DB::table('sheq_sites')->where('company_id', $companyId)->where('id', $siteId)
                ->first(['contractor_required_documents']);

            if ($site !== null && $site->contractor_required_documents !== null) {
                $own = json_decode((string) $site->contractor_required_documents, true);

                if (is_array($own)) {
                    return array_values(array_filter($own));
                }
            }
        }

        $row = DB::table('sheq_settings')->where('company_id', $companyId)
            ->first(['contractor_required_documents']);

        /*
         * A tenant with no settings row has not chosen nothing — they have not been asked.
         *
         * Returning an empty list for them made every contractor COMPLIANT the moment they were
         * registered, which is the opposite of the deny-by-default the register sets out with. The
         * shipped default stands until somebody says otherwise; an explicitly empty list saved
         * from the settings screen still means "nothing demanded", because that is a decision.
         */
        if ($row === null) {
            return self::DEFAULT_REQUIRED_DOCUMENTS;
        }

        $stored = json_decode((string) $row->contractor_required_documents, true);

        return is_array($stored)
            ? array_values(array_filter($stored))
            : self::DEFAULT_REQUIRED_DOCUMENTS;
    }

    /**
     * Which version of each document was in force at a moment (CON-24).
     *
     * The requirement a version chain exists to serve, and the one nothing could answer. A safety
     * file exported in March, an access decision taken in March, an auditor asking what the
     * contractor had on file in March — all of them could only ever be shown today's document.
     *
     * "In force" is a window, not a row: created on or before the moment, and either never
     * superseded or superseded after it. Both halves matter. Dropping the first shows a
     * certificate that did not exist yet; dropping the second shows the one that replaced it.
     *
     * @return list<array<string,mixed>>
     */
    public function documentsAsAt(int $companyId, int $contractorId, Carbon $at): array
    {
        return DB::table('sheq_contractor_documents')
            ->where('company_id', $companyId)->where('contractor_id', $contractorId)
            ->whereNull('deleted_at')
            ->where('created_at', '<=', $at)
            ->where(fn ($q) => $q->whereNull('superseded_at')->orWhere('superseded_at', '>', $at))
            ->orderBy('document_type')->orderByDesc('version')
            ->get(['uuid', 'document_type', 'title', 'reference', 'issuing_body', 'version',
                'issued_on', 'expires_on', 'status', 'created_at', 'superseded_at'])
            ->map(fn ($d) => array_merge((array) $d, [
                /*
                 * Whether it had expired BY THAT MOMENT rather than by now. A certificate that
                 * expired last week was perfectly valid in March, and a history that marks it
                 * expired is a history that makes the past look worse than it was.
                 */
                'expired_then' => $d->expires_on !== null
                    && Carbon::parse($d->expires_on)->lt($at),
            ]))->values()->all();
    }

    /**
     * The full version chain for a contractor, newest version of each type first.
     *
     * CON-24 says old versions remain available to authorised users. Availability here means
     * readable and identifiable — which version, when it was filed, when it stopped counting and
     * why — not merely still present in a table nobody queries.
     *
     * @return array<string, list<array<string,mixed>>>
     */
    public function documentVersions(int $companyId, int $contractorId): array
    {
        return DB::table('sheq_contractor_documents as d')
            ->leftJoin('users as u', 'u.id', '=', 'd.superseded_by')
            ->leftJoin('hr_people as p', 'p.user_id', '=', 'u.id')
            ->where('d.company_id', $companyId)->where('d.contractor_id', $contractorId)
            ->whereNull('d.deleted_at')
            ->orderBy('d.document_type')->orderByDesc('d.version')->orderByDesc('d.id')
            ->get(['d.uuid', 'd.document_type', 'd.title', 'd.reference', 'd.issuing_body',
                'd.version', 'd.issued_on', 'd.expires_on', 'd.status', 'd.file_path',
                'd.created_at', 'd.superseded_at', 'd.supersede_reason',
                'd.replaces_document_id', 'u.email as superseded_by_email',
                'p.first_name', 'p.surname'])
            ->map(fn ($d) => array_merge((array) $d, [
                'in_force' => $d->superseded_at === null,
                'has_file' => $d->file_path !== null,
                'superseded_by_name' => $this->personName($d),
                // Never the path: it is a storage location, and a screen that shows it invites
                // somebody to try reaching it directly.
                'file_path' => null,
                'first_name' => null,
                'surname' => null,
            ]))
            ->groupBy('document_type')
            ->map(fn ($group) => $group->values()->all())
            ->all();
    }

    private function personName(object $row): ?string
    {
        $name = trim(($row->first_name ?? '').' '.($row->surname ?? ''));

        return $name !== '' ? $name : ($row->superseded_by_email ?? null);
    }

    /**
     * The next version number for this document type.
     *
     * A renewal is version 2 of the same obligation rather than an unrelated second row. The
     * number is what lets a screen say "renewed twice" instead of listing three COID letters and
     * leaving the reader to work out the order.
     */
    private function nextVersion(int $companyId, int $contractorId, string $type): int
    {
        return 1 + (int) DB::table('sheq_contractor_documents')
            ->where('company_id', $companyId)->where('contractor_id', $contractorId)
            ->where('document_type', $type)->whereNull('deleted_at')
            ->max('version');
    }

    /** The document this one replaces: the live one of the same type, if there is one. */
    private function currentDocumentId(int $companyId, int $contractorId, string $type): ?int
    {
        $id = DB::table('sheq_contractor_documents')
            ->where('company_id', $companyId)->where('contractor_id', $contractorId)
            ->where('document_type', $type)
            ->whereNull('deleted_at')->whereNull('superseded_at')
            ->orderByRaw('expires_on desc nulls last')->orderByDesc('id')
            ->value('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * Accept or reject a supplied document (CON-8).
     *
     * A document that has arrived and not been checked satisfies nothing, so this is the act that
     * makes it count — or the act that refuses it, with the reason recorded on the row where
     * whoever supplied it can be told why. Access is recomputed immediately, because accepting the
     * last outstanding certificate is exactly the moment somebody is standing at the gate.
     */
    public function decideDocument(int $companyId, string $documentUuid, string $decision,
        ?string $reason = null, ?int $actorId = null): void
    {
        $decision = strtoupper($decision);

        if (! in_array($decision, [ContractorDocumentEngine::ACCEPTED,
            ContractorDocumentEngine::REJECTED], true)) {
            throw new RuntimeException("'{$decision}' is not a decision on a document.");
        }

        if ($decision === ContractorDocumentEngine::REJECTED && trim((string) $reason) === '') {
            throw new RuntimeException(
                'Rejecting a document needs a reason. The contractor has to be told what to fix, '
                .'and "rejected" on its own starts a phone call rather than a resubmission.'
            );
        }

        $document = DB::table('sheq_contractor_documents as d')
            ->join('sheq_contractors as c', 'c.id', '=', 'd.contractor_id')
            ->where('d.company_id', $companyId)->where('d.uuid', $documentUuid)
            ->whereNull('d.deleted_at')
            ->first(['d.id', 'd.status', 'd.document_type', 'c.id as contractor_id',
                'c.uuid as contractor_uuid']);

        if ($document === null) {
            throw new RuntimeException("No contractor document {$documentUuid} in this company.");
        }

        DB::table('sheq_contractor_documents')->where('id', $document->id)->update([
            'status' => $decision,
            'supersede_reason' => $decision === ContractorDocumentEngine::REJECTED
                ? $reason : null,
            'updated_at' => now(),
        ]);

        $this->audit($companyId, $actorId, 'contractor.document_'.strtolower($decision),
            (int) $document->contractor_id,
            ['status' => $document->status], ['status' => $decision], $reason);

        $this->refreshAccessStatus($companyId, $document->contractor_uuid, [], $actorId);
    }

    /**
     * Set a document aside without destroying it (rule 8).
     *
     * The row is kept, stays readable, and stops counting towards compliance — which is what an
     * auditor asking "what were you holding in March, and who decided it was wrong" needs. A
     * reason is required because the only legitimate uses are a correction and a withdrawal, and
     * both are statements somebody has to own.
     *
     * Access is recomputed immediately: setting aside a certificate that was holding a contractor
     * open must block them in the same breath, not at the next document upload.
     */
    public function supersedeDocument(int $companyId, string $documentUuid, string $reason,
        ?int $actorId = null): void
    {
        if (trim($reason) === '') {
            throw new RuntimeException('Setting a compliance document aside needs a reason.');
        }

        $document = DB::table('sheq_contractor_documents as d')
            ->join('sheq_contractors as c', 'c.id', '=', 'd.contractor_id')
            ->where('d.company_id', $companyId)->where('d.uuid', $documentUuid)
            ->whereNull('d.deleted_at')
            ->first(['d.id', 'd.document_type', 'd.expires_on', 'd.superseded_at',
                'c.id as contractor_id', 'c.uuid as contractor_uuid']);

        if ($document === null) {
            throw new RuntimeException("No contractor document {$documentUuid} in this company.");
        }

        if ($document->superseded_at !== null) {
            throw new RuntimeException('That document has already been set aside.');
        }

        DB::table('sheq_contractor_documents')->where('id', $document->id)->update([
            'superseded_at' => now(),
            'superseded_by' => $actorId,
            'supersede_reason' => $reason,
            'updated_at' => now(),
        ]);

        $this->audit($companyId, $actorId, 'contractor.document_superseded',
            (int) $document->contractor_id,
            ['document_type' => $document->document_type, 'expires_on' => $document->expires_on],
            ['superseded' => true],
            $reason);

        // The file changed, so the gate answer may have changed with it.
        $this->refreshAccessStatus($companyId, $document->contractor_uuid, [], $actorId);
    }

    /**
     * @param  ?int  $siteId  where the work is, so a site document counts at its own site only
     * @param  ?string  $activity  what the work is, so an activity document counts for that work
     */
    public function complianceFile(int $companyId, string $contractorUuid, array $requiredTypes = [],
        ?int $siteId = null, ?string $activity = null): array
    {
        $contractor = $this->find($companyId, $contractorUuid);

        /*
         * The site decides the bar, and when the caller did not name one the contractor's own site
         * does. Reading the company list regardless would have made the per-site requirement
         * invisible everywhere except the one screen that happens to pass a site in.
         */
        $requiredTypes = $requiredTypes === []
            ? $this->configuredRequiredTypes($companyId, $siteId ?? $contractor->site_id)
            : $requiredTypes;
        $today = Carbon::today();

        $documents = DB::table('sheq_contractor_documents')
            ->where('company_id', $companyId)->where('contractor_id', $contractor->id)
            ->whereNull('deleted_at')
            /*
             * Superseded rows stay in the table and leave the live file.
             *
             * The selection below keeps, per type, the document with the furthest expiry — right
             * for a renewal, and inescapable for a mistake: a date typed as 2030 instead of 2026
             * still wins over the correct certificate attached afterwards, so the contractor reads
             * COMPLIANT and nothing short of deleting the evidence could correct it.
             */
            ->whereNull('superseded_at')
            ->orderBy('document_type')
            ->get(['uuid', 'document_type', 'title', 'reference', 'issuing_body',
                'issued_on', 'expires_on', 'is_mandatory', 'file_path', 'status',
                'warning_days', 'site_id', 'activities', 'version']);

        /*
         * Only accepted documents count (CON-8).
         *
         * A document can be supplied and not yet checked, or checked and rejected. Counting either
         * as compliance is how an unverified certificate satisfies a requirement — and rejecting a
         * certificate would otherwise have no effect at all, because the row would keep satisfying
         * the section it was rejected from.
         */
        $pending = $documents->filter(fn ($d) => strtoupper((string) ($d->status ?? 'ACCEPTED'))
            === ContractorDocumentEngine::SUPPLIED);
        $rejected = $documents->filter(fn ($d) => strtoupper((string) ($d->status ?? 'ACCEPTED'))
            === ContractorDocumentEngine::REJECTED);
        $documents = $documents->filter(fn ($d) => strtoupper((string) ($d->status ?? 'ACCEPTED'))
            === ContractorDocumentEngine::ACCEPTED);

        /*
         * Where the work is and what it is (CON-8).
         *
         * A document scoped to one site does not satisfy a requirement at another, and a ticket
         * for lifting says nothing about painting. Without this the applicability columns were
         * recorded and never read: a contractor with a Refinery-only approval read as approved at
         * the Depot, which is the failure the columns exist to prevent.
         *
         * Asking without naming a site or an activity keeps everything, because "is this
         * contractor in order at all" is a different question from "may they do this here".
         */
        $engine = app(ContractorDocumentEngine::class);

        $documents = $documents->filter(
            fn ($d) => $engine->appliesTo($d, $siteId, $activity));

        // Latest per type: a renewed certificate supersedes its predecessor without deleting it.
        /*
         * The LIVE version per type, chosen by version number (CON-24).
         *
         * This used to choose by furthest expiry, and the comment above the query admitted what
         * that cost: a date typed as 2030 instead of 2026 beat the correct certificate attached
         * afterwards, so the contractor read COMPLIANT and nothing short of deleting the evidence
         * could correct it. Now that filing a replacement supersedes what it replaces, there is
         * normally exactly one live version per type and nothing to choose between — and where
         * older data still has several, the highest version is the right answer rather than the
         * most optimistic date in the set.
         */
        $latest = [];
        foreach ($documents as $document) {
            $current = $latest[$document->document_type] ?? null;

            if ($current === null || (int) $document->version > (int) $current->version) {
                $latest[$document->document_type] = $document;
            }
        }

        $blocking = [];
        $expiring = [];

        foreach ($latest as $type => $document) {
            if ($document->expires_on === null) {
                continue;   // does not expire
            }

            $daysLeft = (int) $today->diffInDays(Carbon::parse($document->expires_on), false);

            if ($daysLeft < 0) {
                $blocking[] = [
                    'type' => $type,
                    'reason' => 'EXPIRED',
                    'expired_on' => $document->expires_on,
                    'detail' => sprintf('%s expired on %s (%d days ago).',
                        $this->label($type), $document->expires_on, abs($daysLeft)),
                ];
            } elseif ($daysLeft <= self::EXPIRING_WINDOW) {
                $expiring[] = [
                    'type' => $type, 'expires_on' => $document->expires_on, 'days_left' => $daysLeft,
                    'detail' => sprintf('%s expires in %d days.', $this->label($type), $daysLeft),
                ];
            }
        }

        // Absence is not compliance: a mandatory document nobody supplied blocks like an expired one.
        foreach ($requiredTypes as $type) {
            if (isset($latest[$type])) {
                continue;
            }

            /*
             * Absence is not compliance — but "nothing was sent" and "it is sitting here
             * unchecked" are different conversations, and sending somebody to chase a document
             * that is already in the system is how a gate loses credibility.
             */
            $waiting = $pending->firstWhere('document_type', $type);
            $refused = $rejected->firstWhere('document_type', $type);

            $blocking[] = match (true) {
                $waiting !== null => [
                    'type' => $type, 'reason' => 'NOT_ACCEPTED',
                    'detail' => sprintf('%s has been supplied and not yet accepted.',
                        $this->label($type)),
                ],
                $refused !== null => [
                    'type' => $type, 'reason' => 'REJECTED',
                    'detail' => sprintf('%s was rejected and has not been resupplied.',
                        $this->label($type)),
                ],
                default => [
                    'type' => $type, 'reason' => 'MISSING',
                    'detail' => sprintf('%s has not been supplied.', $this->label($type)),
                ],
            };
        }

        /*
         * An approval workflow for contractors, where the tenant configured one.
         *
         * The Approvals screen offered this and nothing read it. It belongs on the access
         * decision rather than on registration: the question a workflow answers here is "may this
         * contractor's people come on site", which is the same question the document file answers,
         * so the two have to meet in one place or the gate contradicts itself.
         *
         * An outstanding approval blocks like a missing document, and says so in the same list —
         * somebody reading "BLOCKED" needs the reason whether it is a lapsed certificate or a
         * signature that has not happened.
         */
        if (! $this->approvals->isCleared($companyId, 'CONTRACTOR', (int) $contractor->id,
            $contractor->trade ?? null, $contractor->site_id ?? null)) {
            $blocking[] = [
                'type' => 'APPROVAL_OUTSTANDING',
                'reason' => 'APPROVAL',
                'detail' => $this->approvals->blockingReason($companyId, 'CONTRACTOR',
                    (int) $contractor->id, $contractor->trade ?? null, $contractor->site_id ?? null)
                    ?? 'An approval for this contractor has not been completed.',
            ];
        }

        /*
         * Whether this site has decided about them at all (CON-14).
         *
         * Only asked when a site is named, because the company-wide file has no site to be
         * decided about and `refreshAccessStatus` deliberately asks without one — a per-site
         * block must not become the contractor's company-wide status.
         *
         * A BLOCKED site refuses outright and is not routed through the blocking-rules switch:
         * the rule governs whether the ABSENCE of a decision refuses, not whether a decision
         * somebody actually took is honoured. A site that banned a contractor banned them for a
         * reason the company-wide file usually knows nothing about.
         */
        $site = $siteId === null ? null : app(SiteApprovalService::class)
            ->statusFor($companyId, (int) $contractor->id, $siteId, $today);

        if ($site !== null) {
            if ($site['status'] === SiteApprovalService::BLOCKED) {
                $blocking[] = [
                    'type' => 'SITE_BLOCKED',
                    'reason' => 'SITE_BLOCKED',
                    'detail' => 'Blocked at this site: '.($site['reason'] ?: 'no reason recorded.'),
                ];
            } elseif ($site['status'] === SiteApprovalService::APPROVED) {
                /*
                 * The decision is the authority, so it satisfies the requirement outright.
                 *
                 * A host that also demands a SITE_SPECIFIC document would otherwise refuse a
                 * contractor the site has explicitly approved, for want of a piece of paper the
                 * approval was presumably granted on the strength of.
                 */
                $blocking = array_values(array_filter($blocking,
                    fn ($entry) => ($entry['type'] ?? null) !== 'SITE_SPECIFIC'));
            } elseif (! $this->hasSiteNamingDocument($documents, $siteId)) {
                /*
                 * Nobody has decided, and there is no document naming this site either.
                 *
                 * The document path predates per-site decisions and still counts, but only when
                 * it NAMES the site: a SITE_SPECIFIC document with no `site_id` is evidence about
                 * the company rather than about this site, and letting one upload clear the whole
                 * estate is the hole per-site approval exists to close.
                 *
                 * Added only if the document loop has not already said the same thing, or a host
                 * who lists SITE_SPECIFIC among the required documents would see it twice.
                 */
                $already = array_filter($blocking,
                    fn ($entry) => ($entry['type'] ?? null) === 'SITE_SPECIFIC');

                if ($already === []) {
                    $blocking[] = [
                        'type' => 'SITE_SPECIFIC',
                        'reason' => $site['status'] === SiteApprovalService::EXPIRED
                            ? 'EXPIRED' : 'MISSING',
                        'expired_on' => $site['valid_until'],
                        'detail' => $site['status'] === SiteApprovalService::EXPIRED
                            ? 'Approval for this site lapsed on '.$site['valid_until'].'.'
                            : 'This site has not approved this contractor.',
                    ];
                }
            }
        }

        $status = match (true) {
            $blocking !== [] => 'BLOCKED',
            $expiring !== [] => 'EXPIRING',
            default => 'COMPLIANT',
        };

        /*
         * An override admits a contractor despite a lapse — on record, and only while it lasts
         * (CON-17).
         *
         * Read from `sheq_access_overrides` rather than from a flag on the contractor, because
         * the flag had no start, no scope and, fatally, nothing that ever switched it off. An
         * override now has a window with both ends and may be narrowed to one site or one kind
         * of work, so an afternoon's grant at the depot does not admit the same people to the
         * refinery.
         */
        $override = app(AccessOverrideService::class)->inForce($companyId,
            AccessOverrideService::CONTRACTOR, (int) $contractor->id, $siteId, $activity);

        if ($status === 'BLOCKED' && $override !== null) {
            $status = 'EXPIRING';   // never COMPLIANT: the file is still short
        }

        return ['status' => $status, 'blocking' => $blocking, 'expiring' => $expiring,
            // Carried so the gate can print the conditions of entry a site attached to its
            // approval — "escorted at all times" is useless if only the approver ever sees it.
            'site_approval' => $site,
            // Carried rather than re-derived, so the status evaluator honours the same override
            // this file already applied. Two readings of "is the override still live" would
            // eventually disagree, and the disagreement would be about who gets on site.
            'overridden' => $status === 'EXPIRING' && $override !== null,
            // The whole decision, so a gate can print who approved it and on what conditions.
            // An override nobody can see the terms of is one nobody can hold anybody to.
            'override' => $override,
            'documents' => $documents->map(fn ($d) => (array) $d)->all()];
    }

    /** Recompute and store the access value so a gate query is a single column read. */
    public function refreshAccessStatus(int $companyId, string $contractorUuid,
        array $requiredTypes = [], ?int $actorId = null): string
    {
        $file = $this->complianceFile($companyId, $contractorUuid, $requiredTypes);

        return DB::transaction(function () use ($companyId, $contractorUuid, $file, $actorId) {
            $before = DB::table('sheq_contractors')
                ->where('company_id', $companyId)->where('uuid', $contractorUuid)
                ->first(['id', 'name', 'access_status']);

            /*
             * The status is calculated from everything, not from the documents alone (CON-9).
             *
             * The document file answers "is the paperwork there and in date". It knows nothing
             * about whether anybody accepted the safety file, whether it was suspended, or whether
             * a named worker's medical lapsed — and each of those decides whether these people may
             * work today. Which of them actually *refuses* entry is the host's configuration
             * (CON-10), so the same facts produce a refusal on a mine and a recorded
             * non-compliance in an office park.
             */
            $verdict = app(ContractorComplianceStatus::class)->evaluate(
                $companyId, (int) $before->id, $file,
            );

            DB::table('sheq_contractors')
                ->where('company_id', $companyId)->where('uuid', $contractorUuid)
                ->update(['access_status' => $verdict['status'],
                    'access_status_computed_at' => DB::raw('now()'),
                    'updated_at' => now()]);

            /*
             * The transition itself, in the audit trail.
             *
             * Whether a contractor's people may come on site is the most consequential state this
             * record carries, and it changed with no entry: no actor, no previous status, no
             * reason. The event below is integration traffic — it reaches a webhook, fires only on
             * the way *into* BLOCKED, and is not a record anybody can read back. So the question
             * "who unblocked them, and on what evidence" had no answer at all, and unblocking is
             * the direction that matters: it is the one that puts somebody on site.
             *
             * Written only when the status actually moved. Recomputation runs on every document
             * touch, and logging an unchanged status would bury the transitions in noise.
             */
            if ($before !== null && $before->access_status !== $verdict['status']) {
                $this->audit($companyId, $actorId, 'contractor.access_status_changed',
                    (int) $before->id,
                    ['access_status' => $before->access_status],
                    ['access_status' => $verdict['status']],
                    $this->statusReason($verdict),
                );

                /*
                 * And tell the contractor (CON-19).
                 *
                 * At the moment the gate moves, not on the next sweep. A contractor who learns
                 * on Tuesday morning that they were blocked on Monday afternoon has already
                 * sent a crew to a boom and lost the shift — which is the entire cost this
                 * notice exists to avoid.
                 *
                 * Queued, never sent inline: this runs inside a transaction that can still
                 * roll back, and a message about a block that did not happen is worse than no
                 * message at all.
                 */
                $this->announceAccessChange($companyId, $before, $verdict);
            }

            /**
             * Only on the transition into BLOCKED. Recomputation runs whenever a document is
             * touched, and re-announcing an already-blocked contractor every time would train a
             * gate system to ignore the event.
             */
            if ($before !== null && $verdict['status'] === ContractorComplianceStatus::BLOCKED
                && $before->access_status !== ContractorComplianceStatus::BLOCKED) {
                $this->events->publish($companyId, 'sheq.contractor.blocked', 'CONTRACTOR',
                    (int) $before->id, [
                        'contractor_id' => (int) $before->id,
                        'name' => $before->name,
                        'reasons' => array_column($verdict['blocking'], 'reason'),
                        // The exact reasons, not only their codes: a webhook that says
                        // "MISSING_MANDATORY_DOCUMENT" tells a gate system nothing it can show
                        // the person standing in front of it.
                        'details' => array_column($verdict['blocking'], 'detail'),
                    ]);
            }

            /*
             * The counterpart (INT-5), and the one an access-control system needs most.
             *
             * Without it a gate is told to shut and never told to open: the contractor fixes the
             * paperwork, the system clears them, and the turnstile stays locked because nothing
             * ever retracted the block. A crew then stands at a boom that the system has already
             * decided to let through.
             */
            if ($before !== null
                && $before->access_status === ContractorComplianceStatus::BLOCKED
                && $verdict['status'] !== ContractorComplianceStatus::BLOCKED) {
                $this->events->publish($companyId, 'sheq.contractor.approved', 'CONTRACTOR',
                    (int) $before->id, [
                        'contractor_id' => (int) $before->id,
                        'name' => $before->name,
                        'status' => $verdict['status'],
                    ]);
            }

            /*
             * Any move, in either direction (INT-5's "compliance changed"). Broader than the two
             * above and useful earlier: a slide from COMPLIANT to NON_COMPLIANT matters to a
             * planner days before it matters to a gate.
             */
            if ($before !== null && $before->access_status !== $verdict['status']) {
                $this->events->publish($companyId, 'sheq.compliance.changed', 'CONTRACTOR',
                    (int) $before->id, [
                        'subject_type' => 'CONTRACTOR',
                        'subject_id' => (int) $before->id,
                        'name' => $before->name,
                        'from' => $before->access_status,
                        'to' => $verdict['status'],
                        'reasons' => array_column($verdict['blocking'] ?? [], 'detail'),
                    ]);
            }

            return $verdict['status'];
        });
    }

    /**
     * Why the status is what it is, in one line, for the audit entry.
     *
     * The blocking detail rather than the bare status: "BLOCKED" six months later tells a reviewer
     * nothing, and the documents behind it may since have been renewed or replaced.
     */
    private function statusReason(array $verdict): string
    {
        $blocking = $verdict['blocking'] ?? [];

        if ($blocking === []) {
            return $verdict['advisory'] === []
                ? 'Every required document is on file and current.'
                : 'Nothing refuses entry, but: '.implode(' ',
                    array_column($verdict['advisory'], 'detail'));
        }

        return implode(' ', array_map(
            fn (array $entry) => $entry['detail'] ?? ($entry['type'].': '.($entry['reason'] ?? 'blocked')),
            $blocking,
        ));
    }

    /**
     * One of the contractor's people (CON-2).
     *
     * Moved out of the controller so that registering somebody who will be issued a site pass is
     * audited like every other consequential change. It was a bare insert: a worker could appear
     * on a contractor's roster, be inducted, and walk through a turnstile with no record of who
     * added them.
     *
     * @param  array{name:string, trade?:?string, inducted_on?:?string, induction_expires?:?string}  $input
     * @return array{id:int, uuid:string}
     */
    public function registerWorker(int $companyId, string $contractorUuid, array $input,
        ?int $actorId = null): array
    {
        $contractor = $this->find($companyId, $contractorUuid);
        $uuid = (string) Str::uuid();

        $id = (int) DB::table('sheq_contractor_workers')->insertGetId([
            'uuid' => $uuid,
            'company_id' => $companyId,
            'contractor_id' => $contractor->id,
            'name' => $input['name'],
            'trade' => $input['trade'] ?? null,
            /*
             * Where they work, which the access card prints. Both fall back rather than defaulting
             * to nothing: a worker with no site of their own works on the contractor's site, which
             * is nearly always the truth and saves setting it on every one of a crew of forty.
             */
            'site_id' => $input['site_id'] ?? null,
            'project_id' => $input['project_id'] ?? null,
            'inducted_on' => $input['inducted_on'] ?? null,
            'induction_expires' => $input['induction_expires'] ?? null,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->audit($companyId, $actorId, 'contractor.worker_registered', (int) $contractor->id,
            null, ['worker_id' => $id, 'name' => $input['name'],
                'trade' => $input['trade'] ?? null,
                'site_id' => $input['site_id'] ?? null,
                'project_id' => $input['project_id'] ?? null,
                'induction_expires' => $input['induction_expires'] ?? null]);

        return ['id' => $id, 'uuid' => $uuid];
    }

    /**
     * May this contractor work today? The gate question, answered with its reasons.
     *
     * @return array{allowed:bool, status:string, reasons:list<string>}
     */
    /**
     * May these people work, and if not, exactly why (CON-9, CON-10).
     *
     * Asked of the calculated verdict rather than of the documents, so the answer accounts for a
     * suspended safety file and a worker whose medical lapsed — and so the reasons are the exact
     * ones CON-10 demands rather than a list of document types.
     *
     * A site and an activity may be named, because the honest answer is often "they may paint but
     * not lift": a site-specific approval and a rigging ticket are each about particular work, and
     * a single yes-or-no for the whole contractor refuses work that is perfectly in order.
     */
    public function mayWork(int $companyId, string $contractorUuid, array $requiredTypes = [],
        ?int $siteId = null, ?string $activity = null): array
    {
        // The site and the activity travel into the file too, or the documents it counts would be
        // every document the contractor holds while the verdict claims to be about this work.
        $file = $this->complianceFile($companyId, $contractorUuid, $requiredTypes, $siteId, $activity);

        $contractorId = (int) DB::table('sheq_contractors')
            ->where('company_id', $companyId)->where('uuid', $contractorUuid)->value('id');

        $verdict = app(ContractorComplianceStatus::class)
            ->evaluate($companyId, $contractorId, $file, $siteId, $activity);

        return [
            'allowed' => $verdict['blocking'] === []
                && $verdict['status'] !== ContractorComplianceStatus::SUSPENDED,
            'status' => $verdict['status'],
            'reasons' => array_column($verdict['blocking'], 'detail'),
            // Reported alongside: a host who chose not to block on something still needs to know
            // it is wrong, and hiding it would make the switch mean "stop checking".
            'advisory' => array_column($verdict['advisory'], 'detail'),
            'safety_file_state' => $verdict['safety_file_state'],
        ];
    }

    /**
     * Admit a contractor despite a lapsed file — recorded, time-boxed, never silent.
     *
     * Kept as the short form for the contractor's own screen, where the question is only "let
     * this firm in until this date". It writes a real override record through
     * `AccessOverrideService`, so it has a window with both ends, an approver and an entry in
     * the register — and so it expires, which the flag it replaced never did.
     *
     * The full form, with a start time, a scope and conditions, is the overrides screen.
     */
    public function overrideAccess(
        int $companyId, string $contractorUuid, string $reason, ?string $until, ?int $actorId = null
    ): void {
        if (trim($reason) === '') {
            throw new RuntimeException('Admitting a non-compliant contractor needs a reason.');
        }

        app(AccessOverrideService::class)->grant($companyId, [
            'subject_type' => AccessOverrideService::CONTRACTOR,
            'subject_uuid' => $contractorUuid,
            'reason' => $reason,
            // A date means the end of that day: an override "until Friday" that stops at
            // midnight on Thursday strands a shift that was told it had Friday.
            'ends_at' => $until === null
                ? Carbon::now()->addDays(AccessOverrideService::MAX_DAYS)->toDateTimeString()
                : Carbon::parse($until)->endOfDay()->toDateTimeString(),
        ], $actorId);
    }

    /**
     * Tell the contractor their gate has moved, in either direction (CON-19).
     *
     * Both directions matter and for different reasons. Closing is the one that costs them a
     * shift if they do not know. Opening is the one that otherwise never reaches them at all —
     * a contractor who sent a renewal on Friday has no way of knowing it was accepted, and
     * telephones on Monday to ask.
     *
     * Only on the transition. Recomputation runs on every document touch, and re-announcing an
     * already-blocked contractor each time would train them to ignore the message — which is
     * the same failure as not sending it, arrived at more expensively.
     */
    private function announceAccessChange(int $companyId, object $before, array $verdict): void
    {
        $contractor = DB::table('sheq_contractors')
            ->where('company_id', $companyId)->where('id', $before->id)
            ->first(['id', 'name', 'contact_email', 'contact_phone']);

        if ($contractor === null) {
            return;
        }

        $notifier = app(ContractorNotifier::class);
        $blocked = ContractorComplianceStatus::BLOCKED;

        if ($verdict['status'] === $blocked) {
            // The findings whole, not `array_column(..., 'detail')`. AUTO-3 asks the notice to
            // carry the evidence and the required corrective action as well as the reason, and
            // flattening to the detail string here was where both of those were thrown away.
            $notifier->blocked($companyId, $contractor, $verdict['blocking']);

            return;
        }

        if ($before->access_status === $blocked) {
            $notifier->unblocked($companyId, $contractor, $verdict['status']);
        }
    }

    /**
     * Incident statistics split host / contractor / combined (SHEQ-CON-4).
     *
     * The combined figure is what the s24 annual return needs; the split is what tells a host
     * whether its own people or its contractors are getting hurt — two different problems with two
     * different answers.
     *
     * @return array{employees:array<string,int>, contractors:array<string,int>, combined:array<string,int>, by_contractor:list<array<string,mixed>>}
     */
    public function incidentRollUp(int $companyId, Carbon $from, Carbon $to, ?int $siteId = null): array
    {
        $base = fn () => DB::table('sheq_incidents')
            ->where('company_id', $companyId)
            ->whereBetween('occurred_at', [$from, $to])
            ->when($siteId, fn ($q) => $q->where('site_id', $siteId))
            ->whereNull('deleted_at');

        $tally = function ($query): array {
            $rows = $query->get(['outcome', 'treatment_level', 'is_reportable', 'days_lost']);

            return [
                'total' => $rows->count(),
                'fatalities' => $rows->filter(fn ($r) => strtoupper((string) $r->outcome) === 'FATALITY')->count(),
                'lost_time' => $rows->filter(fn ($r) => strtoupper((string) $r->outcome) === 'LOST_TIME'
                    || strtoupper((string) $r->treatment_level) === 'LOST_TIME')->count(),
                'reportable' => $rows->where('is_reportable', true)->count(),
                'days_lost' => (int) $rows->sum('days_lost'),
            ];
        };

        $employees = $tally($base()->whereNull('contractor_id'));
        $contractors = $tally($base()->whereNotNull('contractor_id'));

        $combined = [];
        foreach ($employees as $key => $value) {
            $combined[$key] = $value + $contractors[$key];
        }

        $byContractor = DB::table('sheq_incidents as i')
            ->join('sheq_contractors as c', 'c.id', '=', 'i.contractor_id')
            ->where('i.company_id', $companyId)
            ->whereBetween('i.occurred_at', [$from, $to])
            ->when($siteId, fn ($q) => $q->where('i.site_id', $siteId))
            ->whereNull('i.deleted_at')
            ->groupBy('c.id', 'c.name')
            ->selectRaw('c.name, count(*) as incidents, count(*) filter (where i.is_reportable) as reportable')
            ->orderByDesc('incidents')
            ->get()
            ->map(fn ($row) => ['contractor' => $row->name, 'incidents' => (int) $row->incidents,
                'reportable' => (int) $row->reportable])->all();

        return ['employees' => $employees, 'contractors' => $contractors,
            'combined' => $combined, 'by_contractor' => $byContractor];
    }

    /** Documents expiring within the window, for the reminder run (SHEQ-CON-5 precursor). */
    public function expiringDocuments(int $companyId, int $withinDays = 30): array
    {
        return DB::table('sheq_contractor_documents as d')
            ->join('sheq_contractors as c', 'c.id', '=', 'd.contractor_id')
            ->where('d.company_id', $companyId)
            ->whereNotNull('d.expires_on')
            ->whereBetween('d.expires_on', [Carbon::today(), Carbon::today()->addDays($withinDays)])
            ->whereNull('d.deleted_at')
            ->orderBy('d.expires_on')
            ->get(['c.name as contractor', 'c.uuid as contractor_uuid', 'c.contact_email',
                'd.document_type', 'd.expires_on'])
            ->map(fn ($row) => (array) $row)->all();
    }

    /**
     * What a host demands before anybody comes on site, until it says otherwise.
     *
     * The four a South African host ordinarily insists on: proof of Compensation Fund standing,
     * public liability cover, the OHS Act section 37(2) mandatary agreement that fixes who is
     * responsible for what, and the contractor's own safety file.
     */
    /**
     * The shipped bar, for a tenant that has never been asked.
     *
     * Public so the settings screen can show a company with no settings row what it is currently
     * demanding, rather than an empty set of checkboxes that misrepresents the gate.
     *
     * @return list<string>
     */
    public static function defaultRequiredDocuments(): array
    {
        return self::DEFAULT_REQUIRED_DOCUMENTS;
    }

    private const DEFAULT_REQUIRED_DOCUMENTS = [
        'COIDA_LETTER', 'PUBLIC_LIABILITY', 'SECTION_37_2', 'SAFETY_FILE',
    ];

    /**
     * The contractor document types, as one vocabulary.
     *
     * This list was only ever a label lookup, so the codes existed in exactly one place and
     * everywhere else guessed. The required-documents setting was seeded with a *different* set —
     * `LETTER_OF_GOOD_STANDING` where this says `COIDA_LETTER` — which meant a correctly typed
     * document could never satisfy the requirement that asked for it: the host demanded a
     * certificate nobody could ever supply, and the contractor stayed blocked with a complete
     * file.
     *
     * Public so the screen's dropdown is filled from the same place the compliance check reads,
     * rather than the two drifting apart again.
     *
     * @return array<string,string> code => label
     */
    public static function documentTypes(): array
    {
        return [
            'COIDA_LETTER' => 'COIDA letter of good standing',
            'PUBLIC_LIABILITY' => 'Public liability insurance',
            'SECTION_37_2' => 'Section 37(2) mandatary agreement',
            'SAFETY_FILE' => 'Safety file',
            'METHOD_STATEMENT' => 'Method statement',
            'RISK_ASSESSMENT' => 'Risk assessment',
            'APPOINTMENT' => 'Legal appointment',
            'INDUCTION' => 'Site induction',
            'TAX_CLEARANCE' => 'Tax clearance certificate',
            'BBBEE' => 'B-BBEE certificate',
            'COMPANY_REGISTRATION' => 'Company registration (CIPC)',
            'PROOF_OF_INSURANCE' => 'Other proof of insurance',
            'MEDICAL_CERTIFICATE' => 'Medical certificate of fitness',
            'TRAINING_CERTIFICATE' => 'Training certificate',
            'EQUIPMENT_CERTIFICATE' => 'Equipment or load-test certificate',
            /*
             * The rest of what a safety file holds (CON-6).
             *
             * Added here rather than in the safety-file catalogue because this is the one
             * vocabulary: the upload dropdown, the compliance gate and the file's sections all read
             * it. Two lists of document types is a mistake this module has already made once — a
             * seeded `LETTER_OF_GOOD_STANDING` never matched the gate's `COIDA_LETTER`, so a
             * contractor's requirement could not be satisfied by the document that satisfied it.
             */
            'HS_POLICY' => 'Health and safety policy',
            'SAFE_WORK_PROCEDURE' => 'Safe work procedure',
            'PPE_REGISTER' => 'PPE register or issue record',
            'VEHICLE_DOCUMENT' => 'Vehicle licence, roadworthy or driver authorisation',
            'EMERGENCY_PLAN' => 'Emergency plan or contact list',
            'ENVIRONMENTAL_DOCUMENT' => 'Environmental permit or waste arrangement',
            'QUALITY_DOCUMENT' => 'Quality certification or inspection and test plan',
            'SITE_SPECIFIC' => 'Site-specific requirement',
            'PERMIT_DOCUMENT' => 'Permit or authorisation held by the contractor',
            'AUDIT_REPORT' => 'Audit report',
            'NCR' => 'Non-conformance report',
            'OTHER' => 'Other',
        ];
    }

    private function label(string $type): string
    {
        // Still tolerant of a type outside the list: an older record, or a host with a document
        // nobody anticipated, should read properly rather than be hidden.
        return self::documentTypes()[$type] ?? Str::headline(strtolower($type));
    }

    /**
     * Is there a live site-approval document that NAMES this site?
     *
     * The path that predates per-site decisions. A document with a null `site_id` does not count
     * here: it applies everywhere, which is exactly how one upload used to clear every site on
     * the estate. Naming the site is what makes it evidence about this site.
     *
     * @param  \Illuminate\Support\Collection<int,object>  $documents  already filtered for this site
     */
    private function hasSiteNamingDocument($documents, int $siteId): bool
    {
        return $documents->contains(fn ($document) => $document->document_type === 'SITE_SPECIFIC'
            && (int) ($document->site_id ?? 0) === $siteId
            && ($document->expires_on === null
                || Carbon::parse($document->expires_on)->gte(Carbon::today())));
    }

    private function find(int $companyId, string $uuid): object
    {
        $contractor = DB::table('sheq_contractors')
            ->where('company_id', $companyId)->where('uuid', $uuid)->whereNull('deleted_at')->first();

        if ($contractor === null) {
            throw new RuntimeException('Contractor not found.');
        }

        return $contractor;
    }

    private function audit(
        int $companyId, ?int $actorId, string $action, int $subjectId,
        ?array $before, ?array $after, ?string $reason = null
    ): void {
        DB::table('sheq_audit_log')->insert([
            'company_id' => $companyId,
            'actor_id' => $actorId,
            'actor_type' => 'USER',
            'action' => $action,
            'subject_type' => 'Contractor',
            'subject_id' => $subjectId,
            'before' => $before ? json_encode($before) : null,
            'after' => $after ? json_encode($after) : null,
            'reason' => $reason,
        ]);
    }
}
