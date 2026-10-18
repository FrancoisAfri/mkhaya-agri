<?php

namespace App\Modules\HR\LMS\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\LMS\Models\LmsCourseMaterial;
use App\Modules\HR\LMS\Models\LmsEnrollment;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LmsMaterialController extends Controller
{
    /**
     * Serve a course material file.
     *
     * Documents stored in the database (disk='database') are decoded from the
     * base64 JSON blob and streamed directly.  Video files on the private local
     * disk are streamed via Storage::disk('local').
     *
     * Access rules:
     *  - Admin / HR / super admin  → unrestricted.
     *  - Everyone else             → must be enrolled, or lesson must be a free preview.
     */
    public function serve(LmsCourseMaterial $material): Response|StreamedResponse
    {
        /** @var \App\Core\User\Models\User $user */
        $user = Auth::user();

        if (!$user->hasAnyRole(['administrator', 'super admin', 'HR', 'executive'])) {
            $isPreview = $material->lesson?->is_preview ?? false;

            if (!$isPreview) {
                $enrolled = LmsEnrollment::where('user_id', $user->id)
                    ->where('course_id', $material->course_id)
                    ->exists();
                abort_unless($enrolled, 403);
            }
        }

        if ($material->disk === 'database') {
            return $material->toResponse(inline: true);
        }

        // Private local disk — stream without exposing the real path
        /** @var \Illuminate\Filesystem\FilesystemAdapter $disk */
        $disk = Storage::disk($material->disk);
        abort_unless($disk->exists($material->path), 404);

        return $disk->response(
            $material->path,
            $material->file_name,
            ['Content-Type' => $material->mime_type ?? 'application/octet-stream'],
        );
    }
}
