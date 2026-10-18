<?php

namespace App\Modules\Operations\Sheq\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\Sheq\Services\BulkImportService;
use App\Modules\Operations\Sheq\Services\InductionService;
use App\Modules\Operations\Sheq\Support\ResolvesCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Site inductions, and bulk onboarding.
 *
 * Together because they are the same job seen from two ends: getting a lot of people onto a site
 * with valid paperwork. One authors what they must be told; the other loads four hundred of them at
 * once.
 */
class InductionController extends Controller
{
    use ResolvesCompany;

    public function __construct(
        private readonly InductionService $inductions,
        private readonly BulkImportService $imports,
    ) {}

    public function index(Request $request): Response
    {
        $companyId = $this->companyId($request);

        return Inertia::render('Modules/Sheq/Inductions/Index', [
            'noCompany' => $companyId === null,
            'canManage' => (bool) $request->user()?->can('sheq.configure'),
            'sectors' => $this->inductions->sectors(),
            'inductions' => $companyId === null ? [] : $this->list($companyId),
            'expiring' => $companyId === null ? [] : $this->inductions->expiringWithin($companyId),
            'completions' => $companyId === null ? [] : $this->recentCompletions($companyId),
            'sites' => $companyId === null ? [] : DB::table('sheq_sites')
                ->where('company_id', $companyId)->where('is_active', true)
                ->orderBy('name')->get(['id', 'name'])->map(fn ($s) => (array) $s)->all(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $companyId = $this->requireCompany($request);
        abort_unless($request->user()?->can('sheq.configure'), 403,
            'ENTITLEMENT_REQUIRED: sheq.configure');

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:40'],
            'title' => ['required', 'string', 'max:200'],
            'audience' => ['required', Rule::in(['VISITOR', 'CONTRACTOR', 'EMPLOYEE', 'DRIVER'])],
            'site_id' => ['nullable', 'integer'],
            'validity_months' => ['nullable', 'integer', 'min:1', 'max:120'],
            'requires_nda' => ['nullable', 'boolean'],
            'nda_text' => ['nullable', 'string', 'max:10000'],
            'content' => ['required', 'array', 'min:1'],
            'content.*.heading' => ['required', 'string', 'max:200'],
            'content.*.body' => ['required', 'string', 'max:5000'],
            'questions' => ['nullable', 'array'],
            'questions.*.id' => ['required', 'string', 'max:20'],
            'questions.*.text' => ['required', 'string', 'max:500'],
            'questions.*.options' => ['required', 'array', 'min:2'],
            'questions.*.answer' => ['required', 'string', 'max:500'],
        ]);

        $result = $this->inductions->publish($companyId, $validated, $request->user()?->id);

        return back()->with('success',
            "{$result['code']} published as version {$result['version']}. Completions against the "
            .'previous version keep pointing at what those people were actually shown.');
    }

    // ── Bulk onboarding ───────────────────────────────────────────────────────────────────────

    /**
     * Download a template.
     *
     * Generated from the same definition the importer reads, so the file a user fills in can never
     * drift from what will be accepted.
     */
    public function template(Request $request, string $type): StreamedResponse
    {
        $rows = $this->imports->templateRows(strtoupper($type));
        $filename = strtolower($type).'-template.csv';

        return response()->streamDownload(function () use ($rows) {
            $handle = fopen('php://output', 'w');

            // A BOM, so Excel opens the file as UTF-8 rather than mangling every accented name.
            fwrite($handle, "\xEF\xBB\xBF");

            foreach ($rows as $row) {
                fputcsv($handle, $row);
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Validate an uploaded file without writing, or import it. */
    public function upload(Request $request): RedirectResponse
    {
        $companyId = $this->requireCompany($request);
        abort_unless($request->user()?->can('sheq.contractor.manage'), 403,
            'ENTITLEMENT_REQUIRED: sheq.contractor.manage');

        $validated = $request->validate([
            'import_type' => ['required', Rule::in(array_keys(BulkImportService::TEMPLATES))],
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:8192'],
            'commit' => ['nullable', 'boolean'],
        ]);

        $rows = $this->readCsv($request->file('file')->getRealPath());

        if (! ($validated['commit'] ?? false)) {
            $checked = $this->imports->validate($companyId, $validated['import_type'], $rows);

            return back()->with($checked['errors'] === [] ? 'success' : 'warning', sprintf(
                '%d row(s) ready, %d with problems. Nothing has been saved — fix the rows below '
                .'and upload again with "Import" ticked.',
                count($checked['valid']), count($checked['errors']),
            ))->with('importErrors', $checked['errors']);
        }

        $result = $this->imports->import($companyId, $validated['import_type'], $rows,
            $request->user()?->id);

        return back()->with('success', sprintf('%d imported, %d skipped with problems.',
            $result['imported'], $result['failed']))->with('importErrors', $result['errors']);
    }

    /** @return list<array<int,string>> */
    private function readCsv(string $path): array
    {
        $rows = [];
        $handle = fopen($path, 'r');

        while (($row = fgetcsv($handle)) !== false) {
            // Comment lines carry the instructions in the template and are not data.
            if (isset($row[0]) && str_starts_with(trim((string) $row[0]), '#')) {
                continue;
            }

            $rows[] = $row;
        }

        fclose($handle);

        return $rows;
    }

    /** @return list<array<string,mixed>> */
    private function list(int $companyId): array
    {
        return DB::table('sheq_inductions as i')
            ->leftJoin('sheq_sites as s', 's.id', '=', 'i.site_id')
            ->where(fn ($q) => $q->where('i.company_id', $companyId)->orWhereNull('i.company_id'))
            ->where('i.is_active', true)->whereNull('i.deleted_at')
            ->orderBy('i.audience')->orderBy('i.code')
            ->get(['i.uuid', 'i.code', 'i.title', 'i.audience', 'i.sector', 'i.basis',
                'i.version', 'i.validity_months', 'i.requires_nda', 'i.company_id',
                'i.content', 'i.questions', 's.name as site_name'])
            ->map(fn ($i) => [
                'uuid' => $i->uuid, 'code' => $i->code, 'title' => $i->title,
                'audience' => $i->audience, 'sector' => $i->sector, 'basis' => $i->basis,
                'version' => (int) $i->version, 'validity_months' => (int) $i->validity_months,
                'requires_nda' => (bool) $i->requires_nda,
                // A library induction belongs to everyone and must not look editable.
                'is_shared' => $i->company_id === null,
                'site_name' => $i->site_name,
                'section_count' => count(json_decode((string) $i->content, true) ?: []),
                'question_count' => count(json_decode((string) $i->questions, true) ?: []),
            ])->values()->all();
    }

    /** @return list<array<string,mixed>> */
    private function recentCompletions(int $companyId): array
    {
        return DB::table('sheq_induction_completions as c')
            ->join('sheq_inductions as i', 'i.id', '=', 'c.induction_id')
            ->where('c.company_id', $companyId)
            ->orderByDesc('c.completed_at')->limit(50)
            ->get(['c.uuid', 'c.person_name', 'c.subject_type', 'c.score', 'c.passed',
                'c.completed_at', 'c.expires_on', 'c.completed_via', 'i.title'])
            ->map(fn ($c) => (array) $c)->values()->all();
    }
}
