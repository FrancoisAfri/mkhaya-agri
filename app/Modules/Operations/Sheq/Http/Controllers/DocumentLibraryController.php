<?php

namespace App\Modules\Operations\Sheq\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\Sheq\Services\DocumentService;
use App\Modules\Operations\Sheq\Support\DocumentAudience;
use App\Modules\Operations\Sheq\Support\ResolvesCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Where everybody else reads the procedures (SHEQ-DOC-2, SHEQ-DOC-3).
 *
 * The documents register is a practitioner's screen: it needs `sheq.document.manage`, shows drafts
 * and withdrawn versions, and offers publishing. There was nowhere for the people a procedure is
 * actually written for. A controlled document nobody can find controls nothing, and the
 * acknowledgement that proves it reached them had no screen to be given on.
 *
 * Three rules this screen exists to keep:
 *
 * - **Only the published version.** A draft is not a procedure and a withdrawn one is not an
 *   answer; a reader sees the version in force or nothing.
 * - **Only what applies to them.** `applies_to` has been stored on every document since the table
 *   was written and read by nothing, so every published procedure was visible to anyone who could
 *   reach the register. It is honoured here.
 * - **Every copy is watermarked.** Rendered through the same path the practitioner's view uses, so
 *   a printout carries its version, its publish date and the word "uncontrolled".
 */
class DocumentLibraryController extends Controller
{
    use ResolvesCompany;

    public function __construct(private readonly DocumentService $documents) {}

    public function index(Request $request): Response
    {
        $companyId = $this->companyId($request);

        return Inertia::render('Modules/Sheq/Library/Index', [
            'noCompany' => $companyId === null,
            'documents' => $companyId === null ? [] : $this->readable($request, $companyId),
        ]);
    }

    /**
     * Record that this person has read and understood the version in force.
     *
     * Against the employee, not the login: a procedure governs a person, and "which user clicked"
     * is not the evidence an inspector asks for.
     */
    public function acknowledge(Request $request, string $uuid): RedirectResponse
    {
        $companyId = $this->requireCompany($request);

        // Readable to them, or they cannot acknowledge it — otherwise a uuid from elsewhere is an
        // acknowledgement of a document this person was never shown.
        abort_unless($this->mayRead($request, $companyId, $uuid), 403);

        $hrPersonId = DB::table('hr_people')->where('user_id', $request->user()?->id)->value('id');

        if ($hrPersonId === null) {
            return back()->with('error',
                'Your account has no employee record, so an acknowledgement cannot be recorded '
                .'against you. Ask an administrator to link your account to a personnel record.');
        }

        $this->documents->acknowledge($companyId, $uuid, (int) $hrPersonId, $request->user()?->id, []);

        return back()->with('success', 'Recorded. Thank you.');
    }

    /**
     * The published documents this person may read.
     *
     * @return list<array<string,mixed>>
     */
    private function readable(Request $request, int $companyId): array
    {
        $roles = $this->rolesOf($request);
        $siteIds = $this->sitesOf($companyId, $request);

        $acknowledged = DB::table('sheq_document_acknowledgements as a')
            ->join('hr_people as p', 'p.id', '=', 'a.hr_person_id')
            ->where('a.company_id', $companyId)
            ->where('p.user_id', $request->user()?->id)
            ->pluck('a.document_version_id')
            ->all();

        return DB::table('sheq_documents as d')
            ->join('sheq_document_versions as v', function ($join) {
                $join->on('v.document_id', '=', 'd.id')->where('v.status', '=', 'PUBLISHED');
            })
            ->leftJoin('sheq_sites as s', 's.id', '=', 'd.site_id')
            ->where('d.company_id', $companyId)
            ->where('d.status', 'PUBLISHED')
            ->whereNull('d.deleted_at')
            ->orderBy('d.title')
            ->get([
                'd.uuid', 'd.doc_number', 'd.title', 'd.doc_type', 'd.applies_to',
                'd.requires_acknowledgement', 'd.site_id',
                'v.id as version_id', 'v.version', 'v.published_at',
            ])
            ->filter(fn ($row) => $this->appliesTo($row, $roles, $siteIds))
            ->map(fn ($row) => [
                'uuid' => $row->uuid,
                'doc_number' => $row->doc_number,
                'title' => $row->title,
                'doc_type' => $row->doc_type,
                'version' => $row->version,
                'published_at' => $row->published_at,
                'site_name' => DB::table('sheq_sites')->where('id', $row->site_id)->value('name'),
                'requires_acknowledgement' => (bool) $row->requires_acknowledgement,
                'acknowledged' => in_array($row->version_id, $acknowledged, true),
                /*
                 * The marking SHEQ-DOC-3 requires on every copy: version, publish date, and that a
                 * print is uncontrolled.
                 *
                 * Built by `DocumentService::watermark()` so the wording cannot drift between the
                 * viewer, the print stylesheet and a PDF. Nothing called it — the only caller was
                 * `readerView()`, which nothing called either — so the library showed a generic
                 * sentence about watermarking and no actual watermark. A reader could print a
                 * procedure and have nothing on the page saying which version it was.
                 *
                 * From the row already fetched, not a second query: a library of two hundred
                 * procedures would otherwise cost four hundred.
                 */
                'watermark' => $this->documents->watermark(
                    (object) ['doc_number' => $row->doc_number],
                    (object) ['version' => $row->version, 'published_at' => $row->published_at],
                ),
                'file_uuid' => DB::table('sheq_files')
                    ->where('company_id', $companyId)
                    ->where('subject_type', 'DOCUMENT_VERSION')->where('subject_id', $row->version_id)
                    ->whereNull('deleted_at')->orderByDesc('id')->value('uuid'),
            ])
            ->values()
            ->all();
    }

    private function mayRead(Request $request, int $companyId, string $uuid): bool
    {
        return collect($this->readable($request, $companyId))
            ->contains(fn (array $document) => $document['uuid'] === $uuid);
    }

    /**
     * Whether a document applies to this reader.
     *
     * Delegated to {@see DocumentAudience}, which also answers the inverse — who is expected to
     * have acknowledged a document — for the register. The same rule read from both ends has to
     * come from one place: two implementations would eventually disagree about who owes an
     * acknowledgement, and the percentage on the register would stop meaning anything.
     *
     * @param  list<string>  $roles
     * @param  list<int>  $siteIds
     */
    private function appliesTo(object $row, array $roles, array $siteIds): bool
    {
        return DocumentAudience::applies(
            $row->applies_to,
            $row->site_id === null ? null : (int) $row->site_id,
            $roles,
            $siteIds,
        );
    }

    /** @return list<string> */
    private function rolesOf(Request $request): array
    {
        return $request->user()?->getRoleNames()
            ?->map(fn ($role) => strtoupper(str_replace([' ', '-'], '_', (string) $role)))
            ->all() ?? [];
    }

    /**
     * The sites this person works at.
     *
     * Empty means "not tied to a site", which is the common case for office staff — they see the
     * company-wide documents and the site-specific ones they are attached to, which is none.
     *
     * @return list<int>
     */
    private function sitesOf(int $companyId, Request $request): array
    {
        $userId = $request->user()?->id;

        if ($userId === null) {
            return [];
        }

        /*
         * A person belongs to a company unit, and a site carries the unit it was provisioned from
         * (`sheq_sites.company_unit_id`), so that is the join.
         *
         * The pivot's column is named `company_id` and holds the *unit* id — a long-standing
         * naming trap in this schema. Reading it as a company would match every site in the
         * tenant, which for a restricted document means showing it to everybody: the failure would
         * look like the feature working.
         */
        $unitIds = DB::table('company_unit_user_pivots')
            ->where('user_id', $userId)
            ->pluck('company_id');

        if ($unitIds->isEmpty()) {
            return [];
        }

        return DB::table('sheq_sites')
            ->where('company_id', $companyId)
            ->whereIn('company_unit_id', $unitIds)
            ->whereNull('deleted_at')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
