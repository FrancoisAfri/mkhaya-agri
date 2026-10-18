<?php

namespace App\Modules\Assets\Policies;

use App\Core\User\Models\User;
use App\Modules\Assets\Models\AssetDocument;

class AssetDocumentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('assets.view');
    }

    public function view(User $user, AssetDocument $document): bool
    {
        return $user->hasPermissionTo('assets.view');
    }

    public function upload(User $user): bool
    {
        return $user->hasPermissionTo('assets.documents')
            || $user->hasPermissionTo('assets.document-extractions');
    }

    public function confirmExtraction(User $user, AssetDocument $document): bool
    {
        return $user->hasPermissionTo('assets.document-extractions')
            && $document->extraction_status !== 'rejected';
    }

    public function delete(User $user, AssetDocument $document): bool
    {
        return $user->hasPermissionTo('assets.documents')
            && (int) $document->uploaded_by_user_id === (int) $user->id;
    }
}
