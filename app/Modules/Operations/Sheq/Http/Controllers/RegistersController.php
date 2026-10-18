<?php

namespace App\Modules\Operations\Sheq\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\Sheq\Services\CompetencyService;
use App\Modules\Operations\Sheq\Services\ContractorDocumentEngine;
use App\Modules\Operations\Sheq\Services\ContractorService;
use App\Modules\Operations\Sheq\Services\DocumentService;
use App\Modules\Operations\Sheq\Services\SiteProvisioner;
use App\Modules\Operations\Sheq\Support\DocumentAudience;
use App\Modules\Operations\Sheq\Support\ResolvesCompany;
use App\Modules\Operations\Sheq\Support\ScopesToPermittedSites;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The registers a practitioner works from: risk, permits, inspections, competency, documents,
 * contractors (§5.3–§5.9).
 *
 * These backends were built before their screens, so the work existed and nothing in the navigation
 * pointed at it. One controller rather than six because each is the same shape — a scoped list with
 * the few columns that decide whether somebody needs to act today — and six near-identical classes
 * would be six places to forget the company filter.
 *
 * Every query is explicitly company-scoped. RLS covers a tenant session, but a platform admin has no
 * session variable and an unscoped list would show them every tenant's records as if they were this
 * one's.
 */
class RegistersController extends Controller
{
    /**
     * SHEQ's own vocabulary for what a place is, mirroring SiteProvisioner's translation from HR's
     * unit types. A practitioner picks from these; nobody should have to know what HR calls it.
     */
    private const SITE_TYPES = ['REGION', 'SITE', 'AREA'];

    use ResolvesCompany;

    use ScopesToPermittedSites;

    public function __construct(
        private readonly DocumentService $documents,
        private readonly CompetencyService $competencies,
    ) {}

    /**
     * Sites for the create forms.
     *
     * Passed to every register page that can create something. The empty Site dropdown on the
     * hazard form is exactly what this prevents: a form field with no options is a form nobody can
     * complete, and it stayed broken because nothing supplied the list.
     *
     * @return list<array{id:int,name:string}>
     */
    private function canManage(Request $request, string $permission): bool
    {
        return (bool) $request->user()?->can($permission);
    }

    private function siteOptions(?int $companyId): array
    {
        if ($companyId === null) {
            return [];
        }

        return DB::table('sheq_sites')
            ->where('company_id', $companyId)->where('is_active', true)
            ->whereNull('deleted_at')->orderBy('path')
            ->get(['id', 'name'])->map(fn ($s) => ['id' => (int) $s->id, 'name' => $s->name])->all();
    }

    /**
     * Staff, for a picker that writes an `hr_person_id`.
     *
     * @return list<array{id:int,name:string}>
     */
    private function people(int $companyId): array
    {
        // No `deleted_at`: hr_people is not soft-deleted and has no such column.
        return DB::table('hr_people')
            ->where('company_id', $companyId)
            // Only an explicit 0 is "not an employee": the column is nullable and most creation
            // paths leave it unset, so `where('status', 1)` hides most of the staff list.
            ->where(fn ($q) => $q->whereNull('status')->orWhere('status', '<>', 0))
            ->orderBy('first_name')->orderBy('surname')->limit(2000)
            ->get(['id', 'first_name', 'surname'])
            ->map(fn ($p) => [
                'id' => (int) $p->id,
                'name' => trim(($p->first_name ?? '').' '.($p->surname ?? '')),
            ])->all();
    }

    /** Risk assessments (§5.3). Ordered so what is out of date surfaces first. */
    public function riskAssessments(Request $request): Response
    {
        $companyId = $this->companyId($request);

        return Inertia::render('Modules/Sheq/Registers/RiskAssessments', [
            'noCompany' => $companyId === null,
            'sites' => $this->siteOptions($companyId),
            'canManage' => $this->canManage($request, 'sheq.risk_assessment.author'),
            'rows' => $companyId === null ? [] : DB::table('sheq_risk_assessments as ra')
                ->leftJoin('sheq_sites as s', 's.id', '=', 'ra.site_id')
                ->where('ra.company_id', $companyId)->whereNull('ra.deleted_at')

                // SEC-2: this register takes no site parameter, so without scoping it
                // shows the whole estate to a user entitled to one shaft. A broader
                // hole than filter-tampering, and a quieter one — nothing about the
                // screen suggests rows are missing, or present.
                ->tap(fn ($q) => $this->scopeToPermittedSites($q, $request, $companyId,
                    'ra.site_id'))
                ->orderByRaw('ra.review_due nulls last')->orderByDesc('ra.id')->limit(200)
                ->get([
                    'ra.uuid', 'ra.reference', 'ra.title', 'ra.ra_type', 'ra.status',
                    'ra.version', 'ra.review_due', 'ra.approved_at', 'ra.approval_band',
                    's.name as site_name',
                ])->map(fn ($r) => (array) $r + [
                    'review_overdue' => $r->review_due !== null && Carbon::parse($r->review_due)->isPast(),
                ])->values(),
        ]);
    }

    /** Permits to work (§5.5). Live permits first — those are people at risk right now. */
    public function permits(Request $request): Response
    {
        $companyId = $this->companyId($request);

        return Inertia::render('Modules/Sheq/Registers/Permits', [
            'noCompany' => $companyId === null,
            'sites' => $this->siteOptions($companyId),
            /*
             * So a permit can say whose work it is (CON-20). Without that a suspended permit
             * cannot be attributed to anybody, and permit violations stay uncountable on the
             * contractor score.
             */
            'contractors' => $companyId === null ? [] : DB::table('sheq_contractors')
                ->where('company_id', $companyId)->whereNull('deleted_at')
                ->where('is_active', true)->orderBy('name')
                ->get(['id', 'name'])->map(fn ($c) => (array) $c)->all(),
            'canManage' => $this->canManage($request, 'sheq.permit.request'),
            'rows' => $companyId === null ? [] : DB::table('sheq_permits as p')
                ->leftJoin('sheq_sites as s', 's.id', '=', 'p.site_id')
                ->leftJoin('sheq_contractors as c', 'c.id', '=', 'p.contractor_id')
                ->where('p.company_id', $companyId)->whereNull('p.deleted_at')
                ->tap(fn ($q) => $this->scopeToPermittedSites($q, $request, $companyId,
                    'p.site_id'))
                ->orderByRaw("case p.status when 'ACTIVE' then 0 when 'ISSUED' then 1 else 2 end")
                ->orderByDesc('p.id')->limit(200)
                ->get([
                    'p.uuid', 'p.reference', 'p.permit_type', 'p.status', 'p.work_description',
                    'p.valid_from', 'p.valid_to', 's.name as site_name',
                    'c.name as contractor_name',
                ])->map(fn ($r) => (array) $r + [
                    'expired' => $r->valid_to !== null && Carbon::parse($r->valid_to)->isPast(),
                ])->values(),
        ]);
    }

    /** Inspections (§5.6): the schedule that must not slip, and what has been completed. */
    public function inspections(Request $request): Response
    {
        $companyId = $this->companyId($request);

        return Inertia::render('Modules/Sheq/Registers/Inspections', [
            'noCompany' => $companyId === null,
            'canManage' => $this->canManage($request, 'sheq.configure'),
            'canPerform' => $this->canManage($request, 'sheq.inspection.perform'),
            'sites' => $this->siteOptions($companyId),
            // Without a template there is nothing to schedule, so the page can say so rather than
            // offering a form that cannot be completed.
            'templates' => $companyId === null ? [] : DB::table('sheq_inspection_templates')
                ->where(fn ($q) => $q->where('company_id', $companyId)->orWhereNull('company_id'))
                ->whereNull('deleted_at')->where('status', 'PUBLISHED')
                ->orderBy('code')
                ->get(['uuid', 'code', 'title'])
                ->map(fn ($t) => ['uuid' => $t->uuid, 'label' => $t->code.' — '.$t->title])->all(),
            'schedules' => $companyId === null ? [] : DB::table('sheq_inspection_schedules as sc')
                ->leftJoin('sheq_inspection_templates as t', 't.id', '=', 'sc.template_id')
                ->leftJoin('sheq_sites as s', 's.id', '=', 'sc.site_id')
                ->where('sc.company_id', $companyId)->whereNull('sc.deleted_at')
                ->orderByRaw('sc.next_due nulls last')->limit(200)
                ->get([
                    'sc.uuid', 'sc.next_due', 'sc.last_completed', 'sc.is_active',
                    'sc.frequency_days', 't.title as template', 's.name as site_name',
                ])->map(fn ($r) => (array) $r + [
                    'overdue' => $r->is_active && $r->next_due !== null
                        && Carbon::parse($r->next_due)->isPast(),
                ])->values(),
            'completed' => $companyId === null ? [] : DB::table('sheq_inspections as i')
                ->leftJoin('sheq_inspection_templates as t', 't.id', '=', 'i.template_id')
                ->leftJoin('sheq_sites as s', 's.id', '=', 'i.site_id')
                ->where('i.company_id', $companyId)->whereNull('i.deleted_at')
                ->tap(fn ($q) => $this->scopeToPermittedSites($q, $request, $companyId,
                    'i.site_id'))
                ->orderByDesc('i.completed_at')->limit(50)
                ->get([
                    'i.uuid', 'i.reference', 'i.status', 'i.score_percent', 'i.passed',
                    'i.failed_count', 'i.completed_at', 't.title as template', 's.name as site_name',
                ])->values(),
        ]);
    }

    /**
     * Competency (§5.7).
     *
     * Sorted by expiry rather than by name: this register exists to answer "who is about to stop
     * being legally allowed to do their job", and an alphabetical list never answers that.
     */
    public function competencies(Request $request): Response
    {
        $companyId = $this->companyId($request);
        $today = Carbon::today();

        return Inertia::render('Modules/Sheq/Registers/Competency', [
            'noCompany' => $companyId === null,
            'sites' => $this->siteOptions($companyId),
            'canManage' => $this->canManage($request, 'sheq.competency.manage'),
            // The certificate dialog picks a person and a competency; without both lists it would
            // be two free-text boxes, and a mistyped code is a refusal rather than a record.
            'people' => $companyId === null ? [] : $this->people($companyId),
            'competencies' => $companyId === null ? [] : DB::table('sheq_competencies')
                ->where('company_id', $companyId)->whereNull('deleted_at')
                ->orderBy('code')->limit(200)
                ->get(['uuid', 'code', 'title', 'competency_type', 'validity_months',
                    'is_mandatory', 'blocks_work_when_expired', 'is_active'])->values(),
            'records' => $companyId === null ? [] : DB::table('sheq_competency_records as r')
                ->join('sheq_competencies as c', 'c.id', '=', 'r.competency_id')
                ->leftJoin('hr_people as p', 'p.id', '=', 'r.hr_person_id')
                ->where('r.company_id', $companyId)->whereNull('r.deleted_at')
                ->orderByRaw('r.expires_at nulls last')->limit(300)
                ->get([
                    'r.uuid', 'r.status', 'r.expires_at', 'r.achieved_at', 'r.revoked_at',
                    'r.appointment_scope', 'r.accepted_at', 'c.code', 'c.title',
                    'p.first_name', 'p.surname',
                ])->map(fn ($r) => (array) $r + [
                    'person' => trim(($r->first_name ?? '').' '.($r->surname ?? '')) ?: null,
                    'expired' => $r->expires_at !== null && Carbon::parse($r->expires_at)->isPast(),
                    'expiring' => $r->expires_at !== null
                        && ! Carbon::parse($r->expires_at)->isPast()
                        && Carbon::parse($r->expires_at)->lte($today->copy()->addDays(30)),
                ])->values(),
        ]);
    }

    /**
     * The competency matrix: who is qualified for what, as a grid (§5.6).
     *
     * `CompetencyService::matrix()` computes it — per person, the competencies required of them and
     * whether each is held, plus the column totals — and nothing called it. So the register could
     * show a flat list of records and could not answer the question the matrix exists for: where
     * are we short. "Thirty-four records expiring" is a number; "nobody on the night shift holds a
     * first-aid ticket" is a finding.
     *
     * Capped at 200 people. The grid is wide by nature and `matrix()` resolves requirements per
     * person, so an unbounded one on a large workforce is both unreadable and slow. The cap is
     * stated on the screen rather than silently applied.
     *
     * Context is left empty, deliberately: requirements attached to a role, department or site
     * resolve from a map SHEQ does not own — that lives in HR — and guessing at it would report
     * people as short of training they do not need. Those requirements surface where the context
     * is known.
     */
    public function competencyMatrix(Request $request): Response
    {
        $companyId = $this->companyId($request);

        if ($companyId === null) {
            return Inertia::render('Modules/Sheq/Registers/CompetencyMatrix', [
                'noCompany' => true, 'matrix' => null, 'people' => [], 'capped' => false,
                'total' => 0,
            ]);
        }

        $staff = $this->people($companyId);
        $shown = array_slice($staff, 0, 200);

        $matrix = $this->competencies->matrix(
            $companyId,
            // The shape `matrix()` expects: person id => the context its requirements resolve
            // against.
            array_fill_keys(array_column($shown, 'id'), []),
        );

        return Inertia::render('Modules/Sheq/Registers/CompetencyMatrix', [
            'noCompany' => false,
            'matrix' => $matrix,
            'people' => collect($shown)->keyBy('id')->map(fn ($p) => $p['name'])->all(),
            'capped' => count($staff) > count($shown),
            'total' => count($staff),
        ]);
    }

    /** Document control (§5.8). A published document past its review date is the thing to see. */
    public function documents(Request $request): Response
    {
        $companyId = $this->companyId($request);

        return Inertia::render('Modules/Sheq/Registers/Documents', [
            'noCompany' => $companyId === null,
            'sites' => $this->siteOptions($companyId),
            'canManage' => $this->canManage($request, 'sheq.document.manage'),
            'rows' => $companyId === null ? [] : DB::table('sheq_documents as d')
                ->leftJoin('sheq_sites as s', 's.id', '=', 'd.site_id')
                ->where('d.company_id', $companyId)->whereNull('d.deleted_at')
                ->tap(fn ($q) => $this->scopeToPermittedSites($q, $request, $companyId,
                    'd.site_id'))
                ->orderByRaw('d.review_due nulls last')->orderBy('d.doc_number')->limit(200)
                ->get([
                    'd.uuid', 'd.doc_number', 'd.title', 'd.doc_type', 'd.status',
                    'd.current_version', 'd.review_due', 'd.requires_acknowledgement',
                    'd.published_at', 's.name as site_name', 'd.applies_to', 'd.site_id',
                ])->map(fn ($r) => array_diff_key((array) $r, ['applies_to' => null]) + [
                    'review_overdue' => $r->review_due !== null && Carbon::parse($r->review_due)->isPast(),
                    'acknowledgement' => $this->acknowledgement($companyId, $r),
                ])->values(),
        ]);
    }

    /**
     * How far through its audience a document has got.
     *
     * `DocumentService::acknowledgementStatus()` computed this and nothing called it, because it
     * needs to be told who is expected — and nothing resolved a document's audience into a list of
     * people. So `requires_acknowledgement` was a flag the register displayed and could not act on:
     * a company could publish a procedure, require everybody to confirm they had read it, and have
     * no way to find out who had not. The only way to chase it was to ask people.
     *
     * Only computed where the document asks for acknowledgement and is published. A percentage
     * against a draft is a number with nothing behind it.
     *
     * @return array{expected:int, acknowledged:int, percent:?float, outstanding:int}|null
     */
    private function acknowledgement(int $companyId, object $document): ?array
    {
        if (! $document->requires_acknowledgement || $document->status !== 'PUBLISHED') {
            return null;
        }

        $expected = DocumentAudience::expected(
            $companyId,
            $document->applies_to,
            $document->site_id === null ? null : (int) $document->site_id,
        );

        $status = $this->documents->acknowledgementStatus($companyId, $document->uuid, $expected);

        return [
            'expected' => $status['expected'],
            'acknowledged' => $status['acknowledged'],
            'percent' => $status['percent'],
            // The count rather than the ids: this is a register row, and a name list belongs on
            // the document itself where somebody can act on it.
            'outstanding' => count($status['outstanding']),
        ];
    }

    /** Contractors (§5.9). Blocked first: those are people who must not be on site. */
    public function contractors(Request $request): Response
    {
        $companyId = $this->companyId($request);

        return Inertia::render('Modules/Sheq/Registers/Contractors', [
            'noCompany' => $companyId === null,
            'sites' => $this->siteOptions($companyId),
            'canManage' => $this->canManage($request, 'sheq.contractor.manage'),

            /*
             * Suppliers already on file, so a contractor who has been onboarded commercially is
             * picked rather than typed in again.
             *
             * Typing them in again is how the same company ends up in the system twice under two
             * spellings, with a safety file against one and a commercial record against the other
             * and nothing joining them. The two were always meant to be joined — `supplier_id` is
             * on the contractor row — but the only way to do it was a Link button *after* the
             * duplicate had already been created.
             *
             * Ones already linked to a SHEQ contractor are left out: they are not available to
             * register a second time.
             */
            'suppliers' => $this->linkableSuppliers($companyId),

            'rows' => $companyId === null ? [] : DB::table('sheq_contractors as c')
                ->leftJoin('sheq_sites as s', 's.id', '=', 'c.site_id')
                ->where('c.company_id', $companyId)->whereNull('c.deleted_at')
                /*
                 * Refusals first, then the ones that are wrong, then the rest.
                 *
                 * The order predated the richer status vocabulary and knew only BLOCKED and
                 * EXPIRING, so a SUSPENDED contractor — somebody a manager deliberately stopped —
                 * sorted below the compliant ones. This register exists to answer "who cannot
                 * work", and that answer has to be at the top.
                 */
                ->orderByRaw("case c.access_status
                    when 'BLOCKED' then 0
                    when 'SUSPENDED' then 1
                    when 'NON_COMPLIANT' then 2
                    when 'PENDING_APPROVAL' then 3
                    when 'EXPIRING' then 4
                    else 5 end")
                ->orderBy('c.name')->limit(200)
                ->get([
                    'c.uuid', 'c.name', 'c.trade', 'c.access_status', 'c.id',
                    'c.is_active', 'c.contact_email',
                    's.name as site_name',
                    /*
                     * When the override in force runs out, if there is one (CON-17).
                     *
                     * A correlated subquery rather than a join, because a contractor may hold
                     * several overrides at once — one per site — and a join would list them
                     * twice in a register that is meant to have one row per contractor. The
                     * column answers "is anything propping this gate open, and until when",
                     * which is what the register is for; the overrides screen has the detail.
                     */
                    DB::raw('(select max(o.ends_at) from sheq_access_overrides o
                        where o.company_id = c.company_id
                          and o.subject_type = \'CONTRACTOR\' and o.subject_id = c.id
                          and o.revoked_at is null and o.starts_at <= now()
                          and o.ends_at > now()) as override_until'),
                ])
                ->map(fn ($row) => array_merge((array) $row, [
                    'access_overridden' => $row->override_until !== null,
                ]))->values(),
            'expiring' => $companyId === null ? [] : DB::table('sheq_contractor_documents as d')
                ->join('sheq_contractors as c', 'c.id', '=', 'd.contractor_id')
                ->where('d.company_id', $companyId)->whereNull('d.deleted_at')
                ->tap(fn ($q) => $this->scopeToPermittedSites($q, $request, $companyId,
                    'd.site_id'))
                // A document set aside, or one nobody accepted, is not a renewal somebody needs
                // chasing — it left the live file, and listing it sends people after paperwork
                // that has already been dealt with.
                ->whereNull('d.superseded_at')
                ->where('d.status', ContractorDocumentEngine::ACCEPTED)
                ->whereNotNull('d.expires_on')
                ->whereBetween('d.expires_on', [Carbon::today(), Carbon::today()->addDays(30)])
                ->orderBy('d.expires_on')->limit(50)
                ->get(['d.document_type', 'd.expires_on', 'c.name as contractor'])->values(),
        ]);
    }

    /**
     * Sites (§2). Visible because a tenant needs to see what the structure produced, and because a
     * site with no timezone silently computes statutory deadlines in the wrong one (SHEQ-NFR-2).
     */
    public function sites(Request $request): Response
    {
        $companyId = $this->companyId($request);

        return Inertia::render('Modules/Sheq/Registers/Sites', [
            'noCompany' => $companyId === null,
            'rows' => $companyId === null ? [] : DB::table('sheq_sites as s')
                ->leftJoin('company_units as u', 'u.id', '=', 's.company_unit_id')
                ->where('s.company_id', $companyId)->whereNull('s.deleted_at')
                ->orderBy('s.path')->limit(500)
                ->get([
                    's.uuid', 's.code', 's.name', 's.site_type', 's.timezone', 's.is_active',
                    's.path', 's.external_reference', 's.company_unit_id',
                    's.contractor_required_documents',
                    'u.name as unit_name', 'u.type as unit_type',
                ])->map(fn ($r) => (array) $r + [
                    /*
                     * NULL is not an empty list. NULL means this site has never been asked and
                     * takes the company's bar; [] means it has been asked and demands nothing.
                     * The screen has to keep them apart or "inherits" and "demands nothing" would
                     * look identical to a safety manager.
                     */
                    'required_documents' => $r->contractor_required_documents === null
                        ? null
                        : (json_decode((string) $r->contractor_required_documents, true) ?: []),
                    // Depth drives the indent, so the list reads as the tree it mirrors.
                    'depth' => $r->path === null ? 0 : substr_count((string) $r->path, '.'),
                    /*
                     * Whether the structure owns this row.
                     *
                     * It decides what the screen may offer: renaming a mirrored site is undone by
                     * the next resync, so the form says so rather than letting somebody type into
                     * a field that will quietly revert.
                     */
                    'from_structure' => $r->company_unit_id !== null,
                ])->values(),
            'siteTypes' => self::SITE_TYPES,
            'canManage' => (bool) $request->user()?->can('sheq.configure'),

            // The same vocabulary the compliance gate reads, and the company bar a site inherits
            // when it has none of its own — shown so "inherits" can say what it inherits.
            'documentTypes' => collect(ContractorService::documentTypes())
                ->map(fn (string $label, string $code) => ['code' => $code, 'label' => $label])
                ->values()->all(),
            'companyRequiredDocuments' => $companyId === null
                ? ContractorService::defaultRequiredDocuments()
                : app(ContractorService::class)->configuredRequiredTypes($companyId),
        ]);
    }

    /**
     * Suppliers that could be registered as a contractor, with what the form can fill from them.
     *
     * Empty when Supplier Management is not installed, which leaves the picker out of the form
     * rather than offering an empty dropdown nobody can explain.
     *
     * @return list<array<string,mixed>>
     */
    private function linkableSuppliers(?int $companyId): array
    {
        if ($companyId === null
            || ! Schema::hasTable('suppliers')
            || ! Schema::hasColumn('sheq_contractors', 'supplier_id')) {
            return [];
        }

        $alreadyContractors = DB::table('sheq_contractors')
            ->where('company_id', $companyId)->whereNull('deleted_at')
            ->whereNotNull('supplier_id')->pluck('supplier_id')->all();

        return DB::table('suppliers')
            ->where('company_id', $companyId)
            ->when(Schema::hasColumn('suppliers', 'deleted_at'), fn ($q) => $q->whereNull('deleted_at'))
            ->whereNotIn('id', $alreadyContractors)
            ->orderBy('company_name')->limit(500)
            ->get(['id', 'company_name', 'trading_name', 'company_registration_number',
                'email', 'phone_number'])
            ->map(fn ($s) => [
                'id' => (int) $s->id,
                'name' => $s->company_name,
                'trading_name' => $s->trading_name,
                'registration_number' => $s->company_registration_number,
                'contact_email' => $s->email,
                'contact_phone' => $s->phone_number,
            ])->all();
    }

    /**
     * What this site demands of a contractor, which may be more or less than the company does.
     *
     * Separate from updateSite because the two obey different rules: a site mirrored from the
     * company structure cannot be renamed here — the next resync would undo it — but it can
     * perfectly well set its own bar, and folding this into the site form would have denied that.
     */
    public function updateSiteRequirements(Request $request, string $uuid): RedirectResponse
    {
        $companyId = $this->requireCompany($request);
        $this->authoriseConfigure($request);

        $data = $request->validate([
            // Absent means "go back to inheriting", which is not the same as an empty list.
            'required_documents' => ['nullable', 'array'],
            'required_documents.*' => ['string', Rule::in(array_keys(ContractorService::documentTypes()))],
        ]);

        $site = DB::table('sheq_sites')->where('company_id', $companyId)->where('uuid', $uuid)
            ->whereNull('deleted_at')->first(['id', 'name', 'contractor_required_documents']);

        abort_if($site === null, 404);

        $value = array_key_exists('required_documents', $data) && $data['required_documents'] !== null
            ? json_encode(array_values(array_unique($data['required_documents'])))
            : null;

        DB::table('sheq_sites')->where('id', $site->id)
            ->update(['contractor_required_documents' => $value, 'updated_at' => now()]);

        /*
         * Audited because it moves a gate. Raising the bar blocks contractors who were working
         * yesterday and lowering it lets through people who were refused, and either way somebody
         * will ask who changed it.
         */
        DB::table('sheq_audit_log')->insert([
            'company_id' => $companyId, 'actor_id' => $request->user()?->id, 'actor_type' => 'USER',
            'action' => 'sheq_site.requirements_changed',
            'subject_type' => 'SheqSite', 'subject_id' => $site->id,
            'before' => json_encode(['required_documents' => $site->contractor_required_documents]),
            'after' => json_encode(['required_documents' => $value]),
        ]);

        return back()->with('success', 'sheq.sites.flash.requirements_saved');
    }

    /**
     * A site the tenant types, rather than one mirrored from the company structure.
     *
     * A depot rented for eight months, a client's premises, a stretch of road under contract —
     * places where work happens and where an incident gets reported, none of them a line on an HR
     * org chart. Without this people filed against the nearest department and the site on the
     * record was a fiction.
     */
    public function storeSite(Request $request): RedirectResponse
    {
        $companyId = $this->requireCompany($request);
        $this->authoriseConfigure($request);

        $data = $this->validateSite($request);

        app(SiteProvisioner::class)->createManualSite($companyId, $data, $request->user()?->id);

        return back()->with('success', __('sheq.sites.flash.created'));
    }

    public function updateSite(Request $request, string $uuid): RedirectResponse
    {
        $companyId = $this->requireCompany($request);
        $this->authoriseConfigure($request);

        app(SiteProvisioner::class)->updateSite(
            $companyId, $uuid, $this->validateSite($request), $request->user()?->id,
        );

        return back()->with('success', __('sheq.sites.flash.updated'));
    }

    /**
     * Retire a site rather than delete it: every incident, permit and inspection points at one, and
     * deleting would orphan the record an inspector asks for.
     */
    public function toggleSite(Request $request, string $uuid): RedirectResponse
    {
        $companyId = $this->requireCompany($request);
        $this->authoriseConfigure($request);

        app(SiteProvisioner::class)->deactivateSite(
            $companyId, $uuid, $request->boolean('is_active'), $request->user()?->id,
        );

        return back()->with('success', __('sheq.sites.flash.updated'));
    }

    /** @return array<string, mixed> */
    private function validateSite(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:190'],
            // Optional: generated from the name when it is left blank, and made unique either way.
            'code' => ['nullable', 'string', 'max:40'],
            'site_type' => ['required', Rule::in(self::SITE_TYPES)],
            'timezone' => ['required', 'timezone'],
            'parent_uuid' => ['nullable', 'uuid'],
            'external_reference' => ['nullable', 'string', 'max:120'],
            'is_active' => ['nullable', 'boolean'],
        ]);
    }

    private function authoriseConfigure(Request $request): void
    {
        abort_unless(
            $request->user()?->can('sheq.configure'),
            403,
            'ENTITLEMENT_REQUIRED: sheq.configure',
        );
    }
}
