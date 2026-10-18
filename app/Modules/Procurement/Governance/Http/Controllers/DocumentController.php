<?php

namespace App\Modules\Procurement\Governance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Procurement\Governance\Services\Capabilities;
use App\Modules\Procurement\Governance\Services\DocumentService;
use App\Modules\Procurement\Governance\Support\ResolvesCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The document register (§8): one library, linked into many contexts, versioned and access-logged.
 *
 * Downloads stream from the configured disk rather than being served by path. The stored path is
 * never exposed, and the access decision plus its log entry happen in the service before a byte
 * moves — including for a refusal, because an attempt to open a competitor's bid before the tender
 * closed is exactly what an investigation would look for.
 */
class DocumentController extends Controller
{
    use ResolvesCompany;

    public function __construct(
        private readonly DocumentService $documents,
        private readonly Capabilities $capabilities,
    ) {}

    public function index(Request $request): Response
    {
        $companyId = $this->companyId($request);

        $documents = $companyId === null ? collect() : DB::table('procgov_documents as d')
            ->leftJoin('procgov_document_categories as c', 'c.id', '=', 'd.category_id')
            ->leftJoin('users as o', 'o.id', '=', 'd.owner_id')
            ->leftJoin('hr_people as p', 'p.user_id', '=', 'o.id')
            ->where('d.company_id', $companyId)
            ->whereNull('d.deleted_at')
            ->when($request->string('category')->toString(), fn ($q, $v) => $q->where('c.code', $v))
            ->when($request->string('confidentiality')->toString(), fn ($q, $v) => $q->where('d.confidentiality', $v))
            ->when($request->string('search')->toString(), fn ($q, $v) => $q->where('d.title', 'ilike', "%{$v}%"))
            ->orderByDesc('d.created_at')
            ->limit(300)
            ->select([
                'd.id', 'd.uuid', 'd.reference', 'd.title', 'd.confidentiality', 'd.approval_status',
                'd.expires_at', 'd.version_count', 'd.requires_acknowledgement', 'd.owner_id',
                'c.name as category_name', 'c.code as category_code',
                DB::raw("trim(coalesce(p.first_name,'') || ' ' || coalesce(p.surname,'')) as owner"),
            ])
            ->selectSub(
                DB::table('procgov_document_links as l')
                    ->selectRaw('count(*)')
                    ->whereColumn('l.document_id', 'd.id'),
                'link_count'
            )
            ->get()
            // Filtered after the query rather than in it: the access decision combines
            // confidentiality, explicit grants and permissions, and only the service knows all three.
            ->filter(fn ($d) => $this->documents->canRead($request->user(), $d))
            ->values();

        return Inertia::render('Modules/ProcurementGovernance/Documents/Index', [
            'documents' => $documents,
            'filters' => $request->only(['category', 'confidentiality', 'search']),
            'categories' => $companyId ? DB::table('procgov_document_categories')
                ->where('company_id', $companyId)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['code', 'name', 'default_confidentiality']) : [],
            'levels' => ['PUBLIC', 'INTERNAL', 'CONFIDENTIAL', 'RESTRICTED'],
            'expiring' => $companyId ? $this->documents->expiring($companyId, 30) : [],
            'noCompany' => $companyId === null,
            'context' => $this->capabilities->forResponse(),
        ]);
    }

    public function show(Request $request, string $uuid): Response
    {
        $companyId = $this->requireCompany($request);
        $document = $this->find($companyId, $uuid);

        abort_unless($this->documents->canRead($request->user(), $document), 403);

        return Inertia::render('Modules/ProcurementGovernance/Documents/Show', [
            'document' => $document,
            'versions' => DB::table('procgov_document_versions')
                ->where('document_id', $document->id)
                ->orderByDesc('version_no')
                ->get(['uuid', 'version_no', 'original_filename', 'mime_type', 'size_bytes',
                    'checksum', 'change_note', 'is_current', 'created_at']),
            'links' => DB::table('procgov_document_links')
                ->where('document_id', $document->id)
                ->get(['link_role', 'source_module', 'entity_type', 'entity_id',
                    'entity_reference', 'is_required']),
            'access' => DB::table('procgov_document_access_log as a')
                ->leftJoin('users as u', 'u.id', '=', 'a.user_id')
                ->leftJoin('hr_people as p', 'p.user_id', '=', 'u.id')
                ->where('a.document_id', $document->id)
                ->orderByDesc('a.created_at')
                ->limit(200)
                ->get([
                    'a.action', 'a.created_at', 'a.ip', 'a.context',
                    DB::raw("trim(coalesce(p.first_name,'') || ' ' || coalesce(p.surname,'')) as user_name"),
                    'u.email as user_email',
                ]),
            'acknowledgements' => DB::table('procgov_document_acknowledgements as k')
                ->leftJoin('users as u', 'u.id', '=', 'k.user_id')
                ->leftJoin('hr_people as p', 'p.user_id', '=', 'u.id')
                ->where('k.document_id', $document->id)
                ->get([
                    'k.acknowledged_at', 'k.statement',
                    DB::raw("trim(coalesce(p.first_name,'') || ' ' || coalesce(p.surname,'')) as user_name"),
                    'u.email as user_email',
                ]),
            'context' => $this->capabilities->forResponse(),
        ]);
    }

    public function download(Request $request, string $uuid): StreamedResponse
    {
        $companyId = $this->requireCompany($request);
        $document = $this->find($companyId, $uuid);

        $file = $this->documents->open($companyId, $document, $request->user(), true);

        abort_if($file === null, 403, 'You do not have permission to download this document.');

        return Storage::disk($file['disk'])->download($file['path'], $file['filename']);
    }

    public function addVersion(Request $request, string $uuid): RedirectResponse
    {
        $companyId = $this->requireCompany($request);
        $document = $this->find($companyId, $uuid);

        $request->validate([
            'file' => ['required', 'file', 'max:20480'],
            'change_note' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->documents->addVersion(
            $companyId,
            (int) $document->id,
            $request->file('file'),
            $request->input('change_note'),
        );

        return back()->with('success', __('procurement_governance.flash.documents.version_uploaded'));
    }

    public function attach(Request $request, string $uuid): RedirectResponse
    {
        $companyId = $this->requireCompany($request);
        $document = $this->find($companyId, $uuid);

        $data = $request->validate([
            'link_role' => ['required', 'string', 'max:40'],
            'entity_type' => ['required', 'string', 'max:80'],
            'entity_id' => ['required', 'integer'],
            'entity_reference' => ['nullable', 'string', 'max:80'],
            'is_required' => ['boolean'],
        ]);

        $this->documents->attach($companyId, (int) $document->id, $data);

        return back()->with('success', __('procurement_governance.flash.documents.linked'));
    }

    public function acknowledge(Request $request, string $uuid): RedirectResponse
    {
        $companyId = $this->requireCompany($request);
        $document = $this->find($companyId, $uuid);

        $data = $request->validate(['statement' => ['nullable', 'string', 'max:500']]);

        $this->documents->acknowledge(
            $companyId,
            (int) $document->id,
            (int) $request->user()->id,
            $data['statement'] ?? null,
        );

        return back()->with('success', __('procurement_governance.flash.documents.acknowledged'));
    }

    public function grant(Request $request, string $uuid): RedirectResponse
    {
        $companyId = $this->requireCompany($request);
        $document = $this->find($companyId, $uuid);

        abort_unless($request->user()->can('procgov.document.manage'), 403);

        $data = $request->validate([
            'subject_type' => ['required', Rule::in(['user', 'role', 'committee'])],
            'subject_id' => ['required', 'integer'],
            'subject_label' => ['nullable', 'string', 'max:190'],
            'can_view' => ['boolean'],
            'can_download' => ['boolean'],
            'expires_at' => ['nullable', 'date'],
        ]);

        DB::table('procgov_document_permissions')->updateOrInsert(
            [
                'document_id' => $document->id,
                'subject_type' => $data['subject_type'],
                'subject_id' => $data['subject_id'],
            ],
            array_merge($data, [
                'company_id' => $companyId,
                'granted_by' => $request->user()->id,
                'created_at' => now(),
                'updated_at' => now(),
            ])
        );

        return back()->with('success', __('procurement_governance.flash.documents.access_granted'));
    }

    private function find(int $companyId, string $uuid): object
    {
        $document = DB::table('procgov_documents')
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->where('uuid', $uuid)
            ->first();

        abort_if(! $document, 404);

        return $document;
    }
}
