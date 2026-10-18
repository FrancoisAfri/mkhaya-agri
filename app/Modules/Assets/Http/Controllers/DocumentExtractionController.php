<?php

namespace App\Modules\Assets\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Assets\Actions\ConfirmExtractionAction;
use App\Modules\Assets\Actions\UploadExtractionDocumentAction;
use App\Modules\Assets\Models\AssetDocumentExtraction;
use App\Modules\Assets\Models\Assets;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DocumentExtractionController extends Controller
{
    public function index(Request $request): Response
    {
        $extractions = AssetDocumentExtraction::with(['uploader', 'asset'])
            ->when($request->filled('status'), fn($q) => $q->where('extraction_status', $request->status))
            ->orderByDesc('created_at')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Modules/Assets/DocumentExtraction/Index', [
            'extractions' => $extractions,
            'filters'     => $request->only('status'),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Modules/Assets/DocumentExtraction/Create', [
            'assets' => Assets::orderBy('name')->get(['id', 'uuid', 'name', 'asset_tag']),
        ]);
    }

public function store(Request $request, UploadExtractionDocumentAction $action): RedirectResponse
{
    $request->merge([
        'asset_id' => $request->input('asset_id') ? (int) $request->input('asset_id') : null,
    ]);

    $request->validate([
        'file'     => 'required|file|mimes:pdf|max:10240',
        'asset_id' => 'nullable|integer',
    ]);

    $extraction = $action->execute(
        $request->file('file'),
        $request->user()->id,
        $request->input('asset_id')
    );

    return redirect()->route('assets.document-extractions.show', $extraction->uuid)
        ->with('success', 'Document uploaded and extraction started.');
}

    public function show(AssetDocumentExtraction $documentExtraction): Response
    {
        $documentExtraction->load(['uploader', 'asset', 'confirmedBy', 'rejectedBy']);

        return Inertia::render('Modules/Assets/DocumentExtraction/Show', [
            'extraction' => $documentExtraction,
            'assets'     => Assets::orderBy('name')->get(['id', 'uuid', 'name', 'asset_tag']),
        ]);
    }

    public function confirm(Request $request, AssetDocumentExtraction $documentExtraction, ConfirmExtractionAction $action): RedirectResponse
    {
        abort_unless($documentExtraction->extraction_status->value === 'extracted', 422, 'Can only confirm extracted documents.');

        $data = $request->validate([
            'confirmed_fields'           => 'required|array',
            'confirmed_fields.asset_name'      => 'nullable|string|max:255',
            'confirmed_fields.serial_number'   => 'nullable|string|max:255',
            'confirmed_fields.purchase_cost'   => 'nullable|numeric|min:0',
            'confirmed_fields.purchase_date'   => 'nullable|date',
            'confirmed_fields.vendor_name'     => 'nullable|string|max:255',
            'confirmed_fields.model_number'    => 'nullable|string|max:255',
            'confirmed_fields.warranty_expiry' => 'nullable|date',
            'asset_id'                         => 'nullable|integer|exists:assets,id',
        ]);

        if ($data['asset_id'] ?? null) {
            $documentExtraction->update(['asset_id' => $data['asset_id']]);
        }

        $action->execute($documentExtraction, $data['confirmed_fields'], $request->user()->id);

        return back()->with('success', 'Document confirmed and fields applied.');
    }

    public function reject(Request $request, AssetDocumentExtraction $documentExtraction): RedirectResponse
    {
        abort_unless($documentExtraction->extraction_status->value === 'extracted', 422, 'Can only reject extracted documents.');

        $data = $request->validate([
            'rejection_reason' => 'required|string|max:2000',
        ]);

        $documentExtraction->update([
            'extraction_status' => 'rejected',
            'rejection_reason'  => $data['rejection_reason'],
            'rejected_at'       => now(),
            'rejected_by'       => $request->user()->id,
        ]);

        return back()->with('success', 'Document rejected.');
    }
}
