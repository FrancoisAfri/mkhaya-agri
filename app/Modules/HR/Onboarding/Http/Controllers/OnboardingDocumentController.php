<?php

namespace App\Modules\HR\Onboarding\Http\Controllers;

use App\Core\User\Trait\FileStorage;
use App\Modules\HR\Onboarding\Http\Resources\OnboardingDocumentResource;
use App\Modules\HR\Onboarding\Models\OnboardingDocument;
use App\Modules\HR\PreBoarding\Models\HrPerson;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class OnboardingDocumentController
{
    use FileStorage;

    public function index(HrPerson $person)
    {
        $documents = $person->onboardingDocuments()->latest()->get();

        return response()->json([
            'documents' => OnboardingDocumentResource::collection($documents),
        ]);
    }

    public function store(Request $request, HrPerson $person)
    {
        $validated = $request->validate([
            'document_type' => ['required', 'string', 'in:id_document,passport,proof_of_address,banking_details,tax_number,emergency_contact,qualification,other'],
            'label'         => ['required', 'string', 'max:150'],
            'is_required'   => ['boolean'],
        ]);

        $document = OnboardingDocument::create([
            'hr_person_id'  => $person->id,
            'document_type' => $validated['document_type'],
            'label'         => $validated['label'],
            'is_required'   => $validated['is_required'] ?? true,
            'status'        => 'pending',
            'created_by'    => $request->user()->id,
        ]);

        return response()->json([
            'document' => (new OnboardingDocumentResource($document))->resolve(),
        ], 201);
    }

    public function upload(Request $request, HrPerson $person, OnboardingDocument $document)
    {
        abort_unless($document->hr_person_id === $person->id, 404);

        $request->validate([
            'file' => ['required', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,doc,docx'],
        ]);

        // Into the row, not onto a disk. These are ID copies, passports and banking details:
        // a file sitting outside the database is outside row-level security, outside the backup
        // set and outside the POPIA erasure flows, none of which is acceptable for this content.
        $blob = $this->saveFileToDatabase($request->file('file'));

        // A pre-migration document still points at a file on disk. Replacing it means clearing
        // that pointer too, or the row claims both and the stale copy is never removed.
        if ($document->file_path) {
            Storage::disk('local')->delete($document->file_path);
        }

        $document->writeBytes(base64_decode($blob['data']), pathinfo((string) $blob['original_name'], PATHINFO_EXTENSION));

        $document->update([
            'file_path' => null,
            'file_data' => null,
            'file_mime' => $blob['mime'],
            'file_type' => $blob['type'],
            'file_size' => $blob['size'],
            'file_name' => $blob['original_name'],
            'status'    => 'submitted',
        ]);

        return response()->json([
            'document' => (new OnboardingDocumentResource($document->fresh()))->resolve(),
        ]);
    }

    /**
     * Send the stored document back to the browser.
     *
     * Needed because the bytes are in the row now: there is no file path a link can point at, so
     * retrieval has to go through a route that can also check who is asking.
     */
    public function download(HrPerson $person, OnboardingDocument $document)
    {
        abort_unless($document->hr_person_id === $person->id, 404);
        abort_unless($document->hasBytes(), 404, 'No file has been uploaded against this requirement.');

        return $document->fileResponse(
            $document->file_name ?? 'document',
            $document->file_mime,
        );
    }

    public function approve(Request $request, HrPerson $person, OnboardingDocument $document)
    {
        abort_unless($document->hr_person_id === $person->id, 404);

        $document->update([
            'status'      => 'approved',
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
            'rejection_reason' => null,
        ]);

        return response()->json([
            'document' => (new OnboardingDocumentResource($document->fresh()))->resolve(),
        ]);
    }

    public function reject(Request $request, HrPerson $person, OnboardingDocument $document)
    {
        abort_unless($document->hr_person_id === $person->id, 404);

        $validated = $request->validate([
            'rejection_reason' => ['required', 'string', 'max:1000'],
        ]);

        $document->update([
            'status'           => 'rejected',
            'reviewed_by'      => $request->user()->id,
            'reviewed_at'      => now(),
            'rejection_reason' => $validated['rejection_reason'],
        ]);

        return response()->json([
            'document' => (new OnboardingDocumentResource($document->fresh()))->resolve(),
        ]);
    }

    public function destroy(HrPerson $person, OnboardingDocument $document)
    {
        abort_unless($document->hr_person_id === $person->id, 404);
        abort_unless($document->status->value === 'pending', 422);

        if ($document->file_path) {
            // Uploads land on the 'local' disk — delete from the same disk.
            Storage::disk('local')->delete($document->file_path);
        }

        $document->delete();

        return response()->json(['message' => 'Document deleted.']);
    }
}
