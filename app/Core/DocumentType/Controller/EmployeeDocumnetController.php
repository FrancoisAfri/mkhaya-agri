<?php

namespace App\Core\DocumentType\Controller;

use App\Core\DocumentType\Model\EmployeeDocuments;
use App\Core\UserApproval\Services\ProfileUpdateService;
use App\Modules\HR\Hr\Models\HRPerson;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class EmployeeDocumnetController extends Controller
{
    public function __construct(private ProfileUpdateService $service) {}

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'person_id'       => 'required|integer|exists:hr_people,id',
            'doc_description' => 'required|string|max:255',
            'date_from'       => 'nullable|date',
            'expirydate'      => 'nullable|date',
            'file'            => 'required|file|max:10240',
        ]);

        $person = HRPerson::findOrFail($request->person_id);
        $doc    = $this->service->storeDocument($person, $request);

        return response()->json($this->format($doc));
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'doc_description' => 'required|string|max:255',
            'date_from'       => 'nullable|date',
            'expirydate'      => 'nullable|date',
            'file'            => 'nullable|file|max:10240',
        ]);

        $doc = EmployeeDocuments::findOrFail($id);
        $doc = $this->service->updateDocument($doc, $request);

        return response()->json($this->format($doc));
    }

    public function destroy(int $id): JsonResponse
    {
        $doc = EmployeeDocuments::findOrFail($id);
        $this->service->deleteDocument($doc);

        return response()->json(['deleted' => true]);
    }

    public function download(int $id): \Illuminate\Http\Response
    {
        $doc    = EmployeeDocuments::findOrFail($id);
        $parsed = $this->service->getDocumentFileData($doc);

        return $this->service->downloadResponse(
            $parsed['data'],
            $parsed['mime']          ?? 'application/octet-stream',
            $parsed['original_name'] ?? 'document'
        );
    }

    private function format(EmployeeDocuments $doc): array
    {
        return [
            'id'              => $doc->id,
            'doc_description' => $doc->doc_description,
            'date_from'       => $doc->date_from,
            'expirydate'      => $doc->expirydate,
            'supporting_docs' => $doc->supporting_docs,
            'hr_person_id'    => $doc->hr_person_id,
            'hr_change_id'    => $doc->hr_change_id,
            'category_id'     => $doc->category_id,
            'doc_type_id'     => $doc->doc_type_id,
        ];
    }
}
