<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves the white-label logo of the company in play. The logo is stored IN the database
 * (base64 JSON blob — the app's standard FileStorage pattern), so this decodes and
 * streams it. Legacy filesystem-path logos are still served as a fallback.
 */
class BrandingController extends Controller
{
    public function logo(Request $request): Response
    {
        // The company in play (the one a platform administrator switched into), else the user's own.
        $branding = app(\App\Modules\Tenancy\Services\CompanyBranding::class);
        $companyId = $branding->companyIdFor($request->user());
        abort_unless($companyId, 404);

        $logo = $branding->logo($companyId);
        abort_unless($logo, 404);

        return response($logo['binary'], 200)
            ->header('Content-Type', $logo['mime'])
            ->header('Cache-Control', 'private, max-age=3600');
    }
}
