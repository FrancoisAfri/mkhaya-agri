<?php

namespace App\Core\DocumentType\Service;
use App\Core\DocumentType\Model\SystemDocType;

class SystemDocTypeService
{
    public function getAllDocumentTypes()
    {
        return SystemDocType::all();
    }

    public function addDocumentType($request)
    {
        return SystemDocType::create($request->only(['name', 'description', 'is_active']));
    }

    public function update($id, $request)
    {
        $docType = SystemDocType::find($id);

        if (!$docType) {
            return null;
        }
        $docType->update($request->only(['name', 'description', 'is_active']));
        return $docType;
    }

    public function delete($id)
    {
        $docType = SystemDocType::find($id);

        if (!$docType) {
            return false;
        }

        return $docType->delete();
    }
}
