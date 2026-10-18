<?php

namespace App\Modules\Operations\Sheq\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\Sheq\Services\ContractorDocumentEngine;
use App\Modules\Operations\Sheq\Services\ContractorService;
use App\Modules\Operations\Sheq\Services\CompetencyService;
use App\Modules\Operations\Sheq\Services\PpeService;
use App\Modules\Operations\Sheq\Services\DocumentService;
use App\Modules\Operations\Sheq\Services\FileService;
use App\Modules\Operations\Sheq\Services\PermitService;
use App\Modules\Operations\Sheq\Services\RiskAssessmentService;
use App\Modules\Operations\Sheq\Support\ResolvesCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use App\Modules\Operations\Sheq\Support\UploadedArchive;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Creating register entries from the browser.
 *
 * The registers had backends and screens but no way in: a practitioner could look at an empty risk
 * assessment list and had no button to fill it. Every action here delegates to the domain service
 * that already owns the rules — references, matrices, approval bands, compliance recomputation —
 * rather than inserting rows, so a record created here is identical to one created by the API or by
 * a field device.
 *
 * What is deliberately *not* here: approving a risk assessment, issuing a permit, closing an action.
 * Those are decisions with statutory weight and they keep their own endpoints with their own
 * checks; a "create" controller that could also approve would be the easiest place to bypass them.
 */
class RegisterEntryController extends Controller
{
    use ResolvesCompany;

    public function __construct(
        private readonly RiskAssessmentService $riskAssessments,
        private readonly PermitService $permits,
        private readonly DocumentService $documents,
        private readonly ContractorService $contractors,
        private readonly FileService $files,
        private readonly CompetencyService $competencies,
        private readonly PpeService $ppe,
        private readonly \App\Modules\Operations\Sheq\Services\AuditService $audits,
        private readonly \App\Modules\Operations\Sheq\Services\SupplierOnboardingService $suppliers,
        private readonly \App\Modules\Operations\Sheq\Services\EquipmentService $equipment,
        private readonly \App\Modules\Operations\Sheq\Services\HazardService $hazards,
    ) {}

    public function storeRiskAssessment(Request $request): RedirectResponse
    {
        $companyId = $this->requireCompany($request);
        $this->authorise($request, 'sheq.risk_assessment.author');

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'ra_type' => ['required', Rule::in(['BASELINE', 'ISSUE_BASED', 'CONTINUOUS', 'TASK'])],
            'scope' => ['nullable', 'string', 'max:2000'],
            'site_id' => ['nullable', 'integer', $this->siteRule($companyId)],
            'effective_from' => ['nullable', 'date'],
        ]);

        $created = $this->riskAssessments->create($companyId, $validated, $request->user()?->id);

        return redirect('/sheq/risk-assessments')
            ->with('success', "Risk assessment {$created['reference']} created as a draft.");
    }

    public function storePermit(Request $request): RedirectResponse
    {
        $companyId = $this->requireCompany($request);
        $this->authorise($request, 'sheq.permit.request');

        $validated = $request->validate([
            'permit_type' => ['required', 'string', 'max:40'],
            'work_description' => ['required', 'string', 'max:2000'],
            'site_id' => ['nullable', 'integer', $this->siteRule($companyId)],
            /*
             * Whose work this is (CON-20). Null for the host's own employees, which is most
             * permits on most sites. Validated against this company's live register rather than
             * accepted as a number, because a permit stamped with another tenant's contractor id
             * would attribute a high-risk job to a firm that has never been on the site.
             */
            'contractor_id' => ['nullable', 'integer', Rule::exists('sheq_contractors', 'id')
                ->where(fn ($q) => $q->where('company_id', $companyId)->whereNull('deleted_at'))],
            'location_text' => ['nullable', 'string', 'max:255'],
            'valid_from' => ['required', 'date'],
            'valid_to' => ['required', 'date', 'after:valid_from'],
            'workers' => ['nullable', 'array'],
            'workers.*.hr_person_id' => ['nullable', 'integer'],
            'workers.*.name' => ['nullable', 'string', 'max:255'],
        ]);

        $created = $this->permits->request($companyId, $validated,
            $validated['workers'] ?? [], $request->user()?->id);

        return redirect('/sheq/permits')
            ->with('success', "Permit {$created['reference']} requested. It is not valid until issued.");
    }

    /**
     * A controlled document, with the file and with who may read it.
     *
     * Three gaps. The dialog took one file through a plain field, so a folder of procedures went
     * up one at a time and a zip had to be unpacked by hand first. And `applies_to` had been
     * stored on every document since the table was written and read by nothing, so "who may see
     * this" was a column nobody filled and nothing honoured — every published procedure was
     * visible to everyone who could reach the register.
     *
     * Several files become several documents: each is a controlled document in its own right with
     * its own number, because one procedure carrying four unrelated PDFs is not a controlled
     * document, it is an envelope.
     */
    public function storeDocument(Request $request): RedirectResponse
    {
        $companyId = $this->requireCompany($request);
        $this->authorise($request, 'sheq.document.manage');

        $validated = $request->validate([
            'doc_number' => ['required', 'string', 'max:60'],
            'title' => ['required', 'string', 'max:255'],
            'doc_type' => ['nullable', 'string', 'max:40'],
            'site_id' => ['nullable', 'integer', $this->siteRule($companyId)],
            'review_due' => ['nullable', 'date'],
            'requires_acknowledgement' => ['nullable', 'boolean'],
            'summary_of_changes' => ['nullable', 'string', 'max:1000'],
            /*
             * Who may read it once published.
             *
             * `EVERYONE` is the default because a safety procedure nobody can find protects
             * nobody — restriction is the exception and has to be chosen. `ROLES` narrows it to
             * named roles, `SITE` to the people at the document's own site.
             */
            'audience' => ['nullable', Rule::in(['EVERYONE', 'ROLES', 'SITE'])],
            'audience_roles' => ['nullable', 'array'],
            'audience_roles.*' => ['string', 'max:120'],
            'files' => ['nullable', 'array', 'max:50'],
            'files.*' => ['file', 'max:25600'],
            'file' => ['nullable', 'file', 'max:25600'],
        ]);

        $uploads = $request->file('files', []);

        if ($request->hasFile('file')) {
            $uploads[] = $request->file('file');
        }

        $audience = [
            'mode' => $validated['audience'] ?? 'EVERYONE',
            'roles' => array_values($validated['audience_roles'] ?? []),
        ];

        $parts = [];

        foreach ($uploads as $upload) {
            foreach ($this->filesFrom($upload) as $part) {
                $parts[] = $part;
            }
        }

        // No file is still a legitimate document: a procedure being written, or one that lives on
        // paper and is only being tracked for its review date.
        if ($parts === []) {
            $this->createDocumentWith($companyId, $validated, $audience, null, $request);

            return redirect('/sheq/documents')->with('success',
                "{$validated['doc_number']} created as a draft. Publish it when it is ready to be followed.");
        }

        $created = 0;
        $rejected = [];

        foreach ($parts as $index => $part) {
            try {
                $this->createDocumentWith($companyId, array_merge($validated, [
                    // Each file is its own controlled document. A second one cannot reuse the
                    // number, so it is suffixed rather than colliding.
                    'doc_number' => $index === 0
                        ? $validated['doc_number']
                        : $validated['doc_number'].'-'.($index + 1),
                    'title' => $index === 0 ? $validated['title'] : pathinfo($part['name'], PATHINFO_FILENAME),
                ]), $audience, $part, $request);

                $created++;
            } catch (\Throwable $e) {
                $rejected[] = $part['name'];
            }
        }

        if ($created === 0) {
            return back()->with('error', 'None of those files could be stored: '.implode(', ', $rejected));
        }

        $message = $created === 1
            ? "{$validated['doc_number']} created as a draft. Publish it when it is ready to be followed."
            : "{$created} documents created as drafts. Publish each when it is ready to be followed.";

        return redirect('/sheq/documents')->with($rejected === [] ? 'success' : 'warning',
            $rejected === [] ? $message
                : $message.' These could not be stored: '.implode(', ', $rejected).'.');
    }

    /**
     * One document, its first draft version, and the file behind it.
     *
     * @param  array{file:\Illuminate\Http\UploadedFile, name:string}|null  $part
     */
    private function createDocumentWith(int $companyId, array $validated, array $audience,
        ?array $part, Request $request): void
    {
        $document = $this->documents->create($companyId,
            $validated + ['applies_to' => $audience], $request->user()?->id);

        // A document with no version is not readable by anyone, so the first draft is created with
        // it rather than leaving a shell that looks published in the register.
        $version = $this->documents->draftVersion($companyId, $document['uuid'], [
            'summary_of_changes' => $validated['summary_of_changes'] ?? 'First issue',
        ], $request->user()?->id);

        if ($part !== null) {
            $this->files->store($companyId, $part['file'], [
                'subject_type' => 'DOCUMENT_VERSION',
                'subject_id' => (int) ($version['id'] ?? $version['version_id'] ?? 0),
                'category' => 'PROCEDURE',
                'caption' => $validated['title'],
            ], $request->user()?->id);
        }
    }


    public function storeContractor(Request $request): RedirectResponse
    {
        $companyId = $this->requireCompany($request);
        $this->authorise($request, 'sheq.contractor.manage');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'trade' => ['nullable', 'string', 'max:120'],
            'registration_number' => ['nullable', 'string', 'max:60'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:40'],
            'site_id' => ['nullable', 'integer', $this->siteRule($companyId)],

            /*
             * The commercial record this contractor already is, chosen at registration.
             *
             * Scoped to the company and refused if another contractor already holds it: one
             * supplier is one contractor, and two safety files against one commercial record
             * would make "is this supplier cleared to work" unanswerable.
             */
            'supplier_id' => ['nullable', 'integer', Rule::exists('suppliers', 'id')
                ->where('company_id', $companyId),
                Rule::unique('sheq_contractors', 'supplier_id')
                    ->where(fn ($q) => $q->where('company_id', $companyId)->whereNull('deleted_at')),
            ],
        ]);

        $created = $this->contractors->register($companyId, $validated, $request->user()?->id);

        return redirect('/sheq/contractors')->with('success',
            "{$validated['name']} registered. They stay blocked until their compliance file is complete.");
    }

    /** A compliance document for a contractor — the thing that unblocks site access. */
    /**
     * Attach one or more documents to a contractor, with the file itself.
     *
     * Three things were wrong with the single-file version. The dialog offered no file field at
     * all, so a "document" was a row with a type and a date and nothing behind it — a compliance
     * file that proves nothing. Only one could be added at a time, when a contractor's safety file
     * arrives as a dozen. And a zip had to be unpacked by hand before any of it could be uploaded,
     * which is how a file ends up half captured.
     *
     * A zip is expanded into one document per file inside it. Nested archives are stored as files
     * rather than expanded — recursion is where the bomb limits stop being limits.
     */
    public function storeContractorDocument(Request $request, string $uuid): RedirectResponse
    {
        $companyId = $this->requireCompany($request);
        $this->authorise($request, 'sheq.contractor.manage');

        $validated = $request->validate([
            'document_type' => ['required', 'string', 'max:40'],
            'reference' => ['nullable', 'string', 'max:120'],
            'issuing_body' => ['nullable', 'string', 'max:255'],
            'issued_on' => ['nullable', 'date'],
            'expires_on' => ['nullable', 'date'],
            'is_mandatory' => ['nullable', 'boolean'],
            /*
             * CON-8. How long before expiry this document starts warning, where it applies and
             * what it is for — each a property of the document rather than of the system.
             *
             * `warning_days` null means the tenant's default, so changing the default moves every
             * document that never had an opinion of its own. A null site or activity list means
             * everywhere and all activities, which is what every document filed before these
             * columns existed has.
             */
            'warning_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'site_id' => ['nullable', 'integer', $this->siteRule($companyId)],
            'activities' => ['nullable', 'array', 'max:40'],
            'activities.*' => ['string', 'max:60'],
            'status' => ['nullable', Rule::in(ContractorDocumentEngine::STATUSES)],
            // 25 MB each, matching what the file service stores. `files` is the field the screen
            // sends; `file` stays accepted so the mobile client and the API keep working.
            'files' => ['nullable', 'array', 'max:50'],
            'files.*' => ['file', 'max:25600'],
            'file' => ['nullable', 'file', 'max:25600'],
        ]);

        $uploads = $request->file('files', []);

        if ($request->hasFile('file')) {
            $uploads[] = $request->file('file');
        }

        // No file at all is still allowed: a type and an expiry date is a legitimate placeholder
        // for a certificate somebody is waiting on, and it still blocks access until it arrives.
        if ($uploads === []) {
            $this->contractors->attachDocument($companyId, $uuid, $validated, $request->user()?->id);

            return back()->with('success', 'Document recorded. Attach the file when you have it.');
        }

        $stored = 0;
        $rejected = [];

        foreach ($uploads as $upload) {
            foreach ($this->filesFrom($upload) as $part) {
                /*
                 * One unusable file must not lose the rest.
                 *
                 * A safety file zip routinely carries a stray `Thumbs.db` or a `.txt` note beside
                 * the certificates, and the platform's storage refuses types it does not permit.
                 * Failing the whole upload for one of those means the eleven good certificates are
                 * discarded too, and somebody has to work out which file caused it by bisecting
                 * the archive.
                 */
                try {
                    $documentId = $this->contractors->attachDocument($companyId, $uuid, array_merge($validated, [
                        // Each file in an archive becomes its own document, titled by its own name
                        // — eight identically named rows would be worse than the zip.
                        'title' => $part['name'],
                    ]), $request->user()?->id);

                    $this->files->store($companyId, $part['file'], [
                        'subject_type' => 'CONTRACTOR_DOCUMENT',
                        'subject_id' => $documentId,
                        'category' => 'CERTIFICATE',
                        'caption' => $part['name'],
                    ], $request->user()?->id);

                    $stored++;
                } catch (\Throwable $e) {
                    // The document row is removed with it: a row pointing at a file that was
                    // refused is the "proves nothing" case again.
                    if (isset($documentId)) {
                        DB::table('sheq_contractor_documents')->where('id', $documentId)->delete();
                    }

                    $rejected[] = $part['name'];
                }
            }
        }

        if ($stored === 0) {
            return back()->with('error', $rejected === []
                ? 'Nothing was stored.'
                : 'None of those files could be stored: '.implode(', ', $rejected));
        }

        $message = $stored === 1
            ? 'Document attached and access status recomputed.'
            : "{$stored} documents attached and access status recomputed.";

        // Said plainly rather than counted silently: somebody who uploaded twelve and got eleven
        // needs to know which one is missing.
        return back()->with($rejected === [] ? 'success' : 'warning', $rejected === []
            ? $message
            : $message.' These could not be stored: '.implode(', ', $rejected).'.');
    }

    /**
     * One upload, as the list of files it actually represents.
     *
     * A zip becomes its contents; anything else is itself. The temporary files an archive produces
     * are wrapped as uploads so the file service stores them by exactly the same path as a direct
     * upload — encoding, compression, size limit and permitted types all stay the platform's.
     *
     * @return list<array{file:\Illuminate\Http\UploadedFile, name:string}>
     */
    private function filesFrom(\Illuminate\Http\UploadedFile $upload): array
    {
        if (! UploadedArchive::isArchive($upload)) {
            return [['file' => $upload, 'name' => $upload->getClientOriginalName()]];
        }

        return array_map(static fn (array $entry) => [
            'file' => new \Illuminate\Http\UploadedFile(
                $entry['path'], $entry['name'], null, null, true,
            ),
            'name' => $entry['name'],
        ], UploadedArchive::expand($upload));
    }


    /**
     * A certificate earned somewhere else (SHEQ-COMP-7).
     *
     * `recordManual()` existed with no route, so the only competency a person could hold was one
     * the LMS issued. A first-aid certificate from St John, a rigging ticket from a training
     * provider, a forklift licence — none of it could be captured, which means a worker who was
     * genuinely competent read as not, and a permit that gates on competency refused them.
     *
     * The same expiry machinery as an LMS-issued one: the validity period comes from the
     * competency, so a ticket captured here lapses and warns on the same gates as any other.
     */
    public function recordCompetency(Request $request): RedirectResponse
    {
        $companyId = $this->requireCompany($request);
        $this->authorise($request, 'sheq.competency.manage');

        $validated = $request->validate([
            'hr_person_id' => ['required', 'integer', $this->personRule($companyId)],
            'competency_code' => ['required', 'string', 'max:40', $this->competencyRule($companyId)],
            'achieved_at' => ['nullable', 'date'],
            // Left blank, the competency's own validity period decides — so a two-year ticket
            // expires in two years without anybody working out the date.
            'expires_at' => ['nullable', 'date', 'after:achieved_at'],
            'certificate_number' => ['nullable', 'string', 'max:120'],
            'issuing_body' => ['nullable', 'string', 'max:255'],
            'score' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'evidence' => ['nullable', 'file', 'max:25600'],
        ]);

        $recordId = $this->competencies->recordManual($companyId, (int) $validated['hr_person_id'],
            $validated['competency_code'], $validated, $request->user()?->id);

        // The certificate itself, through the module's one file store, so it lands in the database
        // encrypted like every other SHEQ attachment and is reachable from the download route. An
        // expiry date nobody can produce the paper for is not evidence of competency.
        if ($request->hasFile('evidence')) {
            $this->files->store($companyId, $request->file('evidence'), [
                'subject_type' => 'COMPETENCY',
                'subject_id' => $recordId,
                'category' => 'CERTIFICATE',
                'caption' => $validated['competency_code'],
            ], $request->user()?->id);
        }

        return back()->with('success', 'Certificate recorded.');
    }

    /**
     * A legal appointment — OHSA 16(2) and 17, the General Safety Regulations, MHSA 2.9.2 and the
     * Construction Regulations (SHEQ-COMP-8).
     *
     * CompetencyService has carried this since it was written, with the rules that matter already
     * in it: an appointment needs a scope, it needs its signed letter attached, and it is revoked
     * rather than deleted when somebody changes job. None of it could be reached — the competency
     * register could list and add records and nothing else — so a module built for South African
     * health and safety law could not record the appointments that law turns on.
     */
    public function appoint(Request $request): RedirectResponse
    {
        $companyId = $this->requireCompany($request);
        $this->authorise($request, 'sheq.competency.manage');

        $validated = $request->validate([
            'hr_person_id' => ['required', 'integer'],
            'competency_code' => ['required', 'string', 'max:40'],
            // The service refuses without these two; validating here means the person is told
            // which field to fill rather than being handed the refusal as a sentence.
            'scope' => ['required', 'string', 'max:1000'],
            'document_id' => ['required_without:evidence_path', 'nullable', 'integer'],
            'evidence_path' => ['required_without:document_id', 'nullable', 'string', 'max:500'],
            'effective_from' => ['nullable', 'date'],
            'expires_on' => ['nullable', 'date', 'after:effective_from'],
        ]);

        $this->competencies->appoint($companyId, (int) $validated['hr_person_id'],
            $validated['competency_code'], $validated, $request->user()?->id);

        return back()->with('success', 'Appointment recorded. It takes effect once the appointee signs it.');
    }

    /** An appointment is accepted by signing it — nobody is appointed without agreeing to be. */
    public function acceptAppointment(Request $request, int $record): RedirectResponse
    {
        $companyId = $this->requireCompany($request);

        $validated = $request->validate([
            'signature' => ['required', 'string', 'min:2', 'max:255'],
        ]);

        $this->competencies->acceptAppointment($companyId, $record,
            $validated['signature'], $request->user()?->id);

        return back()->with('success', 'Appointment accepted.');
    }

    /**
     * Revoke on a role change or a termination.
     *
     * An appointment that outlives the job is a liability: the person named on it is still legally
     * answerable for something they no longer do.
     */
    public function revokeAppointment(Request $request, int $record): RedirectResponse
    {
        $companyId = $this->requireCompany($request);
        $this->authorise($request, 'sheq.competency.manage');

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ]);

        $this->competencies->revoke($companyId, $record, $validated['reason'], $request->user()?->id);

        return back()->with('success', 'Appointment revoked.');
    }

    /**
     * Somebody has read and understood the version in force (SHEQ-DOC-2).
     *
     * The acknowledgement is the only evidence that a procedure reached the people it governs, and
     * it was unreachable: the document register could publish and list, and nothing could record
     * that anyone had read one.
     */
    public function acknowledgeDocument(Request $request, string $uuid): RedirectResponse
    {
        $companyId = $this->requireCompany($request);

        /*
         * The acknowledgement is against the person, not the login.
         *
         * A procedure governs an employee; "which user clicked" is not the evidence an inspector
         * asks for. Somebody with no personnel record cannot acknowledge, and is told so rather
         * than having it silently recorded against nobody.
         */
        $hrPersonId = DB::table('hr_people')->where('user_id', $request->user()?->id)->value('id');

        if ($hrPersonId === null) {
            return back()->with('error',
                'Your account has no employee record, so an acknowledgement cannot be recorded '
                .'against you. Ask an administrator to link your account to a personnel record.');
        }

        $this->documents->acknowledge($companyId, $uuid, (int) $hrPersonId,
            $request->user()?->id, []);

        return back()->with('success', 'Acknowledged.');
    }

    /**
     * Put a version in force (SHEQ-DOC-1).
     *
     * `DocumentService::publish()` was complete — it supersedes the previous version, invalidates
     * acknowledgements of superseded text, honours a configured approval chain and audits the
     * transition — and it had no route. So every controlled document created through the register
     * stayed DRAFT for ever: the reader library shows published documents only and was therefore
     * permanently empty, `requires_acknowledgement` could never be satisfied, and the review-due
     * reminder had nothing to find. The module could author procedures and could not issue one.
     *
     * The version is named rather than assumed. "Publish the newest draft" is the common case but
     * a document may carry several drafts, and publishing the wrong text is the one mistake this
     * screen must not make easy.
     */
    public function publishDocument(Request $request, string $uuid): RedirectResponse
    {
        $companyId = $this->requireCompany($request);
        $this->authorise($request, 'sheq.document.manage');

        $validated = $request->validate([
            'version' => ['required', 'integer', 'min:1'],
        ]);

        $this->documents->publish($companyId, $uuid, (int) $validated['version'],
            $request->user()?->id);

        return back()->with('success',
            'Version '.$validated['version'].' is now the one in force. Everybody it applies to '
            .'sees it in the document library, and any acknowledgement of the previous text no '
            .'longer counts.');
    }

    /** Take a procedure out of force; retrieval must not answer from a withdrawn one (DOC-4). */
    public function withdrawDocument(Request $request, string $uuid): RedirectResponse
    {
        $companyId = $this->requireCompany($request);
        $this->authorise($request, 'sheq.document.manage');

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ]);

        $this->documents->withdraw($companyId, $uuid, $validated['reason'], $request->user()?->id);

        return back()->with('success', 'Document withdrawn.');
    }

    /** PPE comes back. Issued stock that is never returned reads as permanently consumed. */
    public function returnPpe(Request $request, string $issue): RedirectResponse
    {
        $companyId = $this->requireCompany($request);
        $this->authorise($request, 'sheq.configure');

        // The condition it came back in is the point of recording a return: damaged kit that goes
        // back on the shelf as serviceable is issued again to somebody else.
        $validated = $request->validate([
            'condition' => ['required', 'string', 'max:40'],
        ]);

        $this->ppe->markReturned($companyId, $issue, $validated['condition']);

        return back()->with('success', 'PPE marked as returned.');
    }

    /**
     * Verify an audit finding's closure, or send it back (SHEQ-CAP-2 applied to audits).
     *
     * `AuditService::applyClosureDecision()` existed with nothing calling it, so a finding could be
     * raised and actioned and never verified closed — an audit whose findings cannot be signed off
     * never completes, and the programme behind it never shows as done.
     */
    public function decideAuditFinding(Request $request, int $finding): RedirectResponse
    {
        $companyId = $this->requireCompany($request);
        $this->authorise($request, 'sheq.action.verify');

        $validated = $request->validate([
            'approved' => ['required', 'boolean'],
            // A rejection without a note tells the person who did the work nothing about what is
            // still wrong with it.
            'note' => ['required_if:approved,false', 'nullable', 'string', 'max:2000'],
        ]);

        $this->audits->applyClosureDecision($companyId, $finding, (bool) $validated['approved'],
            (int) $request->user()?->id, $validated['note'] ?? null);

        return back()->with('success', $validated['approved']
            ? 'Finding verified closed.'
            : 'Finding sent back.');
    }

    /**
     * Decide a supplier assessment, with the conditions and the period it is good for.
     *
     * An approval with no expiry is a permanent one by accident: a supplier assessed once in 2019
     * is not an assessed supplier today.
     */
    public function decideSupplierAssessment(Request $request, int $assessment): RedirectResponse
    {
        $companyId = $this->requireCompany($request);
        $this->authorise($request, 'sheq.contractor.manage');

        $validated = $request->validate([
            'approved' => ['required', 'boolean'],
            'conditions' => ['nullable', 'string', 'max:2000'],
            'valid_months' => ['nullable', 'integer', 'min:1', 'max:60'],
        ]);

        $this->suppliers->applyDecision($companyId, $assessment, (bool) $validated['approved'],
            $validated['conditions'] ?? null, (int) ($validated['valid_months'] ?? 12));

        return back()->with('success', 'Decision recorded.');
    }

    /**
     * Take a piece of equipment out of service, or put it back.
     *
     * Equipment could be registered and never moved off IN_SERVICE, so a condemned ladder stayed
     * on the register as serviceable — which is what somebody checks before using it.
     */
    public function changeEquipmentStatus(Request $request, string $uuid): RedirectResponse
    {
        $companyId = $this->requireCompany($request);
        $this->authorise($request, 'sheq.configure');

        $validated = $request->validate([
            'status' => ['required', Rule::in(['IN_SERVICE', 'OUT_OF_SERVICE', 'CONDEMNED', 'MISSING'])],
            'reason' => ['required_unless:status,IN_SERVICE', 'nullable', 'string', 'max:1000'],
        ]);

        $this->equipment->changeStatus($companyId, $uuid, $validated['status'],
            $validated['reason'] ?? null);

        return back()->with('success', 'Equipment status updated.');
    }

    /**
     * A hazard that turned out to be an incident, joined to it (SHEQ-HAZ-4).
     *
     * Without this the two stay separate records of one event: the hazard is closed as "reported"
     * and the incident carries no sign that somebody had flagged the risk beforehand — which is
     * exactly the fact an investigation most wants.
     */
    public function linkHazardToIncident(Request $request, string $uuid): RedirectResponse
    {
        $companyId = $this->requireCompany($request);
        $this->authorise($request, 'sheq.hazard.triage');

        $validated = $request->validate([
            'incident_id' => ['required', 'integer'],
        ]);

        $this->hazards->linkToIncident($companyId, $uuid, (int) $validated['incident_id'],
            $request->user()?->id);

        return back()->with('success', 'Hazard linked to the incident.');
    }

    /**
     * A competency, and optionally a person's record against it.
     *
     * Straight to the table rather than through CompetencyService, which deals in records for a
     * person and has no notion of defining the competency itself. Kept explicit rather than
     * pretending otherwise.
     */
    public function storeCompetency(Request $request): RedirectResponse
    {
        $companyId = $this->requireCompany($request);
        $this->authorise($request, 'sheq.competency.manage');

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:40'],
            'title' => ['required', 'string', 'max:255'],
            'competency_type' => ['required', Rule::in(['TRAINING', 'LICENCE', 'APPOINTMENT', 'MEDICAL', 'OTHER'])],
            'validity_months' => ['nullable', 'integer', 'min:1', 'max:600'],
            'is_mandatory' => ['nullable', 'boolean'],
            'blocks_work_when_expired' => ['nullable', 'boolean'],
            'regulatory_reference' => ['nullable', 'string', 'max:255'],
        ]);

        $exists = DB::table('sheq_competencies')
            ->where('company_id', $companyId)->where('code', $validated['code'])
            ->whereNull('deleted_at')->exists();

        if ($exists) {
            return back()->withErrors(['code' => 'A competency with that code already exists.']);
        }

        DB::table('sheq_competencies')->insert([
            'uuid' => (string) Str::uuid(),
            'company_id' => $companyId,
            'code' => $validated['code'],
            'title' => $validated['title'],
            'competency_type' => $validated['competency_type'],
            'validity_months' => $validated['validity_months'] ?? null,
            'is_mandatory' => (bool) ($validated['is_mandatory'] ?? false),
            'blocks_work_when_expired' => (bool) ($validated['blocks_work_when_expired'] ?? false),
            'regulatory_reference' => $validated['regulatory_reference'] ?? null,
            'practical_required' => false,
            'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return redirect('/sheq/competency')->with('success', "{$validated['code']} added.");
    }

    /**
     * Permission, checked server-side.
     *
     * A user without the entitlement never sees the button, but the UI is a hint and not a control
     * (SHEQ-SEC-2) — the check that matters is this one.
     */
    private function authorise(Request $request, string $permission): void
    {
        abort_unless($request->user()?->can($permission), 403,
            "ENTITLEMENT_REQUIRED: {$permission}");
    }

    /** Same reasoning as siteRule: an id from another tenant must read as "does not exist". */
    private function personRule(int $companyId): \Closure
    {
        return function (string $attribute, $value, \Closure $fail) use ($companyId) {
            if ($value === null) {
                return;
            }

            // No `deleted_at` filter: hr_people is not soft-deleted — the column does not exist,
            // and asking for it is a 500 rather than a stricter check.
            $ok = DB::table('hr_people')
                ->where('company_id', $companyId)->where('id', $value)->exists();

            if (! $ok) {
                $fail('That person is not on this company\'s staff list.');
            }
        };
    }

    /**
     * The competency must be one this company defined.
     *
     * CompetencyService refuses an unknown code with a sentence; catching it here puts the message
     * on the field instead, which is the difference between "fix this" and "something went wrong".
     */
    private function competencyRule(int $companyId): \Closure
    {
        return function (string $attribute, $value, \Closure $fail) use ($companyId) {
            if ($value === null) {
                return;
            }

            $ok = DB::table('sheq_competencies')
                ->where('company_id', $companyId)->where('code', $value)
                ->whereNull('deleted_at')->exists();

            if (! $ok) {
                $fail('No competency with that code exists in this company.');
            }
        };
    }

    /** A site id must belong to this tenant; validating by existence alone would not check that. */
    private function siteRule(int $companyId): \Closure
    {
        return function (string $attribute, $value, \Closure $fail) use ($companyId) {
            if ($value === null) {
                return;
            }

            $ok = DB::table('sheq_sites')
                ->where('company_id', $companyId)->where('id', $value)->exists();

            if (! $ok) {
                $fail('That site does not exist in this company.');
            }
        };
    }
}
