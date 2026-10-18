<?php

namespace App\Core\DocumentType\Controller;

use App\Core\DocumentType\Service\SystemDocTypeService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DocumentTypesController extends Controller
{
    protected $service;

    public function __construct(SystemDocTypeService $service)
    {
        $this->service = $service;
    }

    public function index()
    {
        $documentTypes = $this->service->getAllDocumentTypes();
        return Inertia::render('Core/DocumentTypes/Index', [
            'docTypes' => $documentTypes,
        ]);
    }



    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'is_active' => 'required|boolean',
        ]);

        $this->service->addDocumentType($request);

        return redirect()->back()->with('success', 'Document type created successfully.');
    }



    public function update(Request $request, $id)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'is_active' => 'required|boolean',
        ]);

        $this->service->update($id, $request);

        return redirect()->back()->with('success', 'Document type updated successfully.');
    }

public function destroy($id)
{
    $this->service->delete($id);

    return back();
}
}
