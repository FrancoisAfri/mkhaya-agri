<?php

namespace App\Core\License;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Licensing authority endpoint. Runs ONLY on the vendor-hosted instance
 * (LICENSE_AUTHORITY=true). Client installs heartbeat here so you can revoke
 * them remotely. Guarded by the shared LICENSE_REMOTE_SECRET, not user auth.
 */
class LicenseAuthorityController extends Controller
{
    public function validateInstall(Request $request): JsonResponse
    {
        abort_unless((bool) config('license.authority'), 404);

        $secret = config('license.remote_secret');
        if (! $secret || ! hash_equals((string) $secret, (string) $request->input('secret'))) {
            return response()->json(['error' => 'unauthorised'], 401);
        }

        $data = $request->validate([
            'client'      => 'nullable|string|max:191',
            'domain'      => 'nullable|string|max:191',
            'fingerprint' => 'required|string|max:191',
        ]);

        $record = LicenseRegistry::firstOrNew(['fingerprint' => $data['fingerprint']]);

        // First contact from a known-issued install auto-registers it (not revoked).
        if (! $record->exists) {
            $record->fill(['client' => $data['client'] ?? 'unknown', 'domain' => $data['domain'] ?? null]);
        }
        $record->last_seen_at = now();
        $record->save();

        return response()->json([
            'known'   => true,
            'revoked' => (bool) $record->revoked,
            'reason'  => $record->revoked_reason,
        ]);
    }
}
