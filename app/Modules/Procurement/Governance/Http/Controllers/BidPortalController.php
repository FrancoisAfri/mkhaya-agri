<?php

namespace App\Modules\Procurement\Governance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Procurement\Governance\Services\BidIntakeService;
use App\Modules\Procurement\Governance\Support\Presenters\OptionLists;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The bidder's own screen: unauthenticated, reached by an invitation token (§1.1).
 *
 * Bidders are not platform users and must not be. An account for every company that ever bids turns
 * the supplier register into a directory of logins and puts a password reset between a bidder and a
 * closing deadline. The token is the whole credential, which is why it is 64 random characters,
 * unique across every tender, and rate-limited at the route.
 *
 * What this controller deliberately never returns: any other bidder's existence, any price, or
 * anything about the tender beyond what the invitation entitles this bidder to see. An unknown token
 * gets a flat 404 rather than "no such invitation", because distinguishing the two would let anyone
 * enumerate live tenders.
 */
class BidPortalController extends Controller
{
    public function __construct(private readonly BidIntakeService $intake) {}

    /**
     * Resolve the invitation and bind the tenant.
     *
     * The portal is unauthenticated, so no company is set and the tenant scope is inactive for the
     * token lookup itself. Binding immediately afterwards means every subsequent read and write is
     * correctly scoped and stamped — without it a portal-created row lands with a null company_id
     * and becomes invisible to the tenant under row-level security.
     */
    private function resolve(string $token): object
    {
        $invitation = $this->intake->invitationByToken($token);

        abort_if($invitation === null, 404);

        app(TenantContext::class)->apply((int) $invitation->company_id);

        return $invitation;
    }

    public function show(string $token): Response
    {
        $invitation = $this->resolve($token);

        $review = DB::table('procgov_tender_reviews')
            ->where('id', $invitation->review_id)
            ->first(['id', 'reference', 'tender_number', 'title', 'description', 'currency', 'tender_closing_at']);

        abort_if($review === null, 404);

        DB::table('procgov_bid_invitations')->where('id', $invitation->id)
            ->update(['last_seen_at' => Carbon::now()]);

        $submission = DB::table('procgov_bid_submissions')
            ->where('invitation_id', $invitation->id)
            ->first(['uuid', 'submitted_at', 'is_late', 'payload_digest', 'opened_at']);

        return Inertia::render('Modules/ProcurementGovernance/Portal/Bid', [
            'token' => $token,
            'tender' => [
                'reference' => $review->reference,
                'tender_number' => $review->tender_number,
                'title' => $review->title,
                'description' => $review->description,
                'currency' => $review->currency,
                'closing_at' => $review->tender_closing_at,
                'closed' => $review->tender_closing_at
                    ? Carbon::now()->greaterThan(Carbon::parse($review->tender_closing_at))
                    : false,
            ],
            'invitation' => [
                'supplier_name' => $invitation->supplier_name,
                'contact_person' => $invitation->contact_person,
                'status' => $invitation->status,
            ],
            /*
             * The receipt, and nothing more. Once sealed, a bidder can see *that* their bid is held
             * and prove *which* bid it was — the digest is theirs to keep — but the portal will not
             * read it back to them, because a screen that can display a sealed bid is a screen an
             * attacker with the token can display it on.
             */
            'submission' => $submission ? [
                'submitted_at' => $submission->submitted_at,
                'is_late' => (bool) $submission->is_late,
                'receipt' => substr($submission->payload_digest, 0, 16),
                'opened' => $submission->opened_at !== null,

                // Names only. The bidder knows what they sent; the portal does not read it back.
                'documents' => DB::table('procgov_bid_submission_documents')
                    ->where('submission_id', DB::table('procgov_bid_submissions')
                        ->where('invitation_id', $invitation->id)->value('id'))
                    ->orderBy('id')
                    ->pluck('original_name'),
            ] : null,
            'currencies' => array_column(OptionLists::JURISDICTIONS, 'currency'),
        ]);
    }

    public function submit(Request $request, string $token): RedirectResponse
    {
        $invitation = $this->resolve($token);

        $data = $request->validate([
            'bid_amount' => ['required', 'numeric', 'min:0.01'],
            'currency' => ['nullable', 'string', 'size:3'],
            'bid_reference' => ['nullable', 'string', 'max:80'],
            'registration_number' => ['nullable', 'string', 'max:80'],
            'tax_number' => ['nullable', 'string', 'max:80'],
            'bbbee_level' => ['nullable', 'integer', 'min:1', 'max:8'],
            'is_local_content_compliant' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],

            // A bidder confirms their own submission; the confirmation is part of the sealed record.
            'confirm' => ['accepted'],

            // Bid documents. Limited by type and size at the door, because this endpoint is
            // unauthenticated and the bytes end up in the database.
            'documents' => ['array', 'max:10'],
            'documents.*' => ['file', 'max:10240', 'mimes:pdf,doc,docx,xls,xlsx,jpg,jpeg,png'],
        ]);

        $submission = $this->intake->submit(
            (int) $invitation->company_id,
            $invitation,
            $data,
            $request->ip(),
        );

        foreach ($request->file('documents', []) as $file) {
            $this->intake->attachDocument((int) $invitation->company_id, $submission, $file);
        }

        return back()->with('success', __('procurement_governance.flash.portal.bid_sealed'));
    }
}
