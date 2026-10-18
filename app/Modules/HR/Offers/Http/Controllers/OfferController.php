<?php

namespace App\Modules\HR\Offers\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\Offers\Actions\CreateOfferAction;
use App\Modules\HR\Offers\Actions\SendOfferAction;
use App\Modules\HR\Offers\Actions\WithdrawOfferAction;
use App\Modules\HR\Offers\Http\Requests\CreateOfferRequest;
use App\Modules\HR\Offers\Http\Resources\OfferResource;
use App\Modules\HR\Offers\Models\Offer;
use App\Modules\HR\Recruitment\Enums\OfferStatus;
use App\Modules\HR\Recruitment\Models\Application;
use App\Modules\HR\Recruitment\Models\Job;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OfferController extends Controller
{
    /**
     * Standalone offers index — all offers across jobs, shown on the Offers ribbon.
     */
    public function allOffers(Request $request): Response
    {
        $offers = Offer::with(['candidate:id,uuid,first_name,last_name,email', 'job:id,title', 'createdBy.person'])
            ->when($request->filled('status'), fn($q) => $q->where('status', $request->input('status')))
            ->latest()
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Modules/Offers/Index', [
            'offers'  => OfferResource::collection($offers),
            'filters' => $request->only('status'),
            'stats'   => [
                'total'    => Offer::count(),
                'draft'    => Offer::where('status', OfferStatus::Draft->value)->count(),
                'sent'     => Offer::where('status', OfferStatus::Sent->value)->count(),
                'accepted' => Offer::where('status', OfferStatus::Accepted->value)->count(),
            ],
        ]);
    }

    public function show(Job $job, Offer $offer): Response
    {
        abort_unless($offer->job_id === $job->id, 404);

        $offer->load(['candidate', 'job', 'createdBy', 'approvedBy', 'contract']);

        return Inertia::render('Modules/Offers/Show', [
            'offer' => (new OfferResource($offer))->resolve(),
        ]);
    }

    public function store(
        CreateOfferRequest $request,
        Job $job,
        Application $application,
        CreateOfferAction $action,
    ): RedirectResponse {
        abort_unless($application->job_id === $job->id, 404);

        $offer = $action->execute(
            application: $application,
            terms:       $request->validated(),
            createdBy:   $request->user()->id,
        );

        return redirect()->route('offers.show', [$job->id, $offer->id])
            ->with('success', 'Offer created.');
    }

    public function send(
        Job $job,
        Offer $offer,
        SendOfferAction $action,
        Request $request,
    ): RedirectResponse {
        abort_unless($offer->job_id === $job->id, 404);

        $action->execute($offer, $request->user()->id);

        return back()->with('success', 'Offer sent to candidate.');
    }

    public function withdraw(
        Request $request,
        Job $job,
        Offer $offer,
        WithdrawOfferAction $action,
    ): RedirectResponse {
        abort_unless($offer->job_id === $job->id, 404);

        $request->validate(['reason' => 'required|string|max:2000']);

        $action->execute($offer, $request->user()->id, $request->input('reason'));

        return back()->with('success', 'Offer withdrawn.');
    }

    public function downloadLetter(Job $job, Offer $offer): \Symfony\Component\HttpFoundation\Response
    {
        abort_unless($offer->job_id === $job->id, 404);

        $offer->load(['candidate', 'job']);

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('pdf.offer-letter', ['offer' => $offer]);
        $pdf->setPaper('A4', 'portrait');

        $filename = 'offer-letter-' . ($offer->uuid ?? $offer->id) . '.pdf';

        return $pdf->download($filename);
    }
}
