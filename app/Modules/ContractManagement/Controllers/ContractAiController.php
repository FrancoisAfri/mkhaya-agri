<?php

namespace App\Modules\ContractManagement\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\ContractManagement\Models\Contract;
use App\Modules\ContractManagement\Services\ContractAiService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

class ContractAiController extends Controller
{
    public function __construct(private readonly ContractAiService $ai) {}

    /** Run AI analysis on a contract and store the extracted intelligence. */
    public function analyze(Contract $contract): RedirectResponse
    {
        $this->authorize('update', Contract::class);

        $result = $this->ai->analyze($contract, Auth::id());

        if ($result->status === 'failed') {
            return back()->withErrors(['ai' => $result->error ?: 'AI analysis failed.']);
        }

        return back()->with('success', 'AI analysis complete.');
    }

    /** Generate an AI renewal brief (renew / renegotiate / exit + rationale). */
    public function renewalBrief(Contract $contract): RedirectResponse
    {
        $this->authorize('update', Contract::class);

        $this->ai->draftRenewalBrief($contract);

        return back()->with('success', 'Renewal brief generated.');
    }
}
