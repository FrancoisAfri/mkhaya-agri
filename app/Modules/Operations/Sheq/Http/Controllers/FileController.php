<?php

namespace App\Modules\Operations\Sheq\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\Sheq\Services\FileService;
use App\Modules\Operations\Sheq\Support\ResolvesCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * Uploading and serving SHEQ files (SHEQ-OFF-7, SHEQ-SEC-4).
 *
 * Bytes are never public. Every read goes through here so the tenant is checked before anything is
 * decoded — a file is 404 to another company rather than 403, because confirming it exists is
 * itself a leak.
 */
class FileController extends Controller
{
    use ResolvesCompany;

    public function __construct(private readonly FileService $files) {}

    public function store(Request $request): RedirectResponse
    {
        $companyId = $this->requireCompany($request);

        $validated = $request->validate([
            'file' => ['required', 'file', 'max:10240'],   // the trait refuses more; fail early
            'subject_type' => ['required', Rule::in([
                'INCIDENT', 'HAZARD', 'INSPECTION', 'CONTRACTOR_DOCUMENT',
                'DOCUMENT_VERSION', 'COMPETENCY', 'PERMIT', 'ACTION', 'RISK_ASSESSMENT',
            ])],
            'subject_id' => ['required', 'integer', 'min:1'],
            'category' => ['nullable', 'string', 'max:30'],
            'caption' => ['nullable', 'string', 'max:255'],
            'client_id' => ['nullable', 'uuid'],
        ]);

        $this->files->store($companyId, $request->file('file'), $validated, $request->user()?->id);

        return back()->with('success', 'File attached.');
    }

    public function download(Request $request, string $uuid): Response
    {
        return $this->files->download($this->requireCompany($request), $uuid);
    }

    /** Inline view — a data URI for an image, so evidence can be shown without a second request. */
    public function view(Request $request, string $uuid): JsonResponse
    {
        return response()->json($this->files->display($this->requireCompany($request), $uuid));
    }
}
