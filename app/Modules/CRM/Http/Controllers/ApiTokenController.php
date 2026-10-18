<?php

namespace App\Modules\CRM\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Issue / revoke Sanctum personal access tokens for the CRM REST API
 * (spec section 5).
 */
class ApiTokenController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => 'required|string|max:120']);

        $token = $request->user()->createToken('crm:' . $data['name']);

        // Plain-text token is shown once.
        return back()->with('apiToken', $token->plainTextToken)
            ->with('success', 'API token created. Copy it now — it will not be shown again.');
    }

    public function destroy(Request $request, int $token): RedirectResponse
    {
        $request->user()->tokens()->where('id', $token)->delete();

        return back()->with('success', 'API token revoked.');
    }
}
