<?php

namespace App\Modules\HR\Contracts\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\Contracts\Actions\CreateContractAction;
use App\Modules\HR\Contracts\Actions\SendContractAction;
use App\Modules\HR\Contracts\Http\Requests\CreateContractRequest;
use App\Modules\HR\Contracts\Http\Requests\SendContractRequest;
use App\Modules\HR\Contracts\Http\Resources\ContractResource;
use App\Modules\HR\Contracts\Models\Contract;
use App\Modules\HR\Offers\Models\Offer;
use App\Modules\HR\Recruitment\Models\Job;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Contracts hang off an offer, which hangs off a job.
 *
 * Every route here is job-scoped — `jobs/{job}/offers/{offer}/contracts/{contract}` — and these
 * methods used to declare only the offer and the contract. Laravel hands route parameters to a
 * controller positionally, so `{job}` arrived where `$offer` was expected and every one of these
 * endpoints answered 500 with a TypeError. Declaring the job fixes that and gives the scoping
 * check below something to compare against.
 */
class ContractController extends Controller
{
    public function show(Job $job, Offer $offer, Contract $contract): Response
    {
        // Each link of the chain is checked, so a valid id from one job cannot be read under
        // another's URL.
        abort_unless($offer->job_id === $job->id, 404);
        abort_unless($contract->offer_id === $offer->id, 404);

        $contract->load(['candidate', 'job', 'signatureRequests']);

        return Inertia::render('Modules/Recruitment/Contracts/Show', [
            'offer'    => ['id' => $offer->id],
            'contract' => (new ContractResource($contract))->resolve(),
        ]);
    }

    public function store(
        CreateContractRequest $request,
        Job $job,
        Offer $offer,
        CreateContractAction $action,
    ): RedirectResponse {
        abort_unless($offer->job_id === $job->id, 404);

        $contract = $action->execute(
            offer:     $offer,
            createdBy: $request->user()->id,
            content:   $request->validated('content'),
        );

        // The route is job-scoped; omitting the job left the generator one parameter short and
        // turned a successful save into a 500 on the redirect.
        return redirect()
            ->route('recruitment.offers.contracts.show', [$job->id, $offer->id, $contract->id])
            ->with('success', 'Contract created.');
    }

    public function send(
        SendContractRequest $request,
        Job $job,
        Offer $offer,
        Contract $contract,
        SendContractAction $action,
    ): RedirectResponse {
        abort_unless($offer->job_id === $job->id, 404);
        abort_unless($contract->offer_id === $offer->id, 404);

        $action->execute($contract, $request->validated('signatories'), $request->user()->id);

        return back()->with('success', 'Contract sent for signing.');
    }
}
