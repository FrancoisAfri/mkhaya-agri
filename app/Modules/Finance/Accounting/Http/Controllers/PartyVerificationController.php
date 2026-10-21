<?php

namespace App\Modules\Finance\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Accounting\Models\FinParty;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Someone checked who a customer is (registration, VAT, references); it shows wherever the customer is chosen. */
class PartyVerificationController extends Controller
{
    public function __invoke(Request $request, FinParty $party): RedirectResponse
    {
        $data = $request->validate(['note' => 'required|string|max:300']);
        $party->update(['verified_by' => $request->user()->id, 'verified_at' => now(), 'verification_note' => $data['note']]);

        return back()->with('success', "{$party->name} verified.");
    }
}
