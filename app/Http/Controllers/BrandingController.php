<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves the current company's white-label logo. The logo is stored IN the database
 * (base64 JSON blob — the app's standard FileStorage pattern), so this decodes and
 * streams it. Legacy filesystem-path logos are still served as a fallback.
 */
class BrandingController extends Controller
{
    public function logo(Request $request): Response
    {
        $companyId = $request->user()?->company_id;
        abort_unless($companyId, 404);

        $raw = DB::table('company_identities')->where('company_id', $companyId)->value('company_logo');
        abort_unless($raw, 404);

        $blob = json_decode($raw, true);
        if (is_array($blob) && ! empty($blob['data'])) {
            // In-DB base64 blob.
            $binary = base64_decode($blob['data']);
            $mime = $blob['mime'] ?? 'image/png';
        } elseif (Storage::disk('public')->exists($raw)) {
            // Legacy filesystem path.
            $binary = Storage::disk('public')->get($raw);
            $mime = Storage::disk('public')->mimeType($raw) ?: 'image/png';
        } else {
            abort(404);
        }

        return response($binary, 200)
            ->header('Content-Type', $mime)
            ->header('Cache-Control', 'private, max-age=3600');
    }
}
