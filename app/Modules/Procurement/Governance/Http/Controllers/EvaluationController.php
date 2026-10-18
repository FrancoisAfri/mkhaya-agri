<?php

namespace App\Modules\Procurement\Governance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Procurement\Governance\Services\Capabilities;
use App\Modules\Procurement\Governance\Services\ConflictOfInterestService;
use App\Modules\Procurement\Governance\Services\EvaluationService;
use App\Modules\Procurement\Governance\Services\ModerationService;
use App\Modules\Procurement\Governance\Support\ResolvesCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The individual evaluator's workspace (§1.5) and their declarations (§1.3).
 *
 * Every action here is scoped to *the signed-in evaluator's own* seat on the review, resolved from
 * the user rather than accepted from the request. An evaluator id in a URL is a suggestion; the
 * authenticated user is the fact. Accepting the former would let one committee member open — or
 * worse, submit — another's evaluation, which would quietly destroy the independence the whole
 * committee model rests on.
 */
class EvaluationController extends Controller
{
    use ResolvesCompany;

    public function __construct(
        private readonly EvaluationService $evaluations,
        private readonly ConflictOfInterestService $coi,
        private readonly ModerationService $moderation,
        private readonly Capabilities $capabilities,
    ) {}

    /** "My evaluations" — everything this user has been asked to score. */
    public function index(Request $request): Response
    {
        $companyId = $this->companyId($request);

        return Inertia::render('Modules/ProcurementGovernance/Evaluations/Index', [
            'assignments' => $companyId
                ? $this->evaluations->myWork($companyId, (int) $request->user()->id)
                : [],
            'noCompany' => $companyId === null,
            'context' => $this->capabilities->forResponse(),
        ]);
    }

    /** The review as an evaluator sees it: the bidders they must score, and their own progress. */
    public function review(Request $request, string $uuid): Response
    {
        $companyId = $this->requireCompany($request);
        [$review, $evaluator] = $this->mySeat($companyId, $uuid, $request);

        $bidders = DB::table('procgov_bidders as b')
            ->leftJoin('procgov_evaluations as e', function ($join) use ($evaluator) {
                $join->on('e.bidder_id', '=', 'b.id')->where('e.evaluator_id', '=', $evaluator->id);
            })
            ->where('b.review_id', $review->id)
            ->whereNull('b.deleted_at')
            ->where('b.is_disqualified', false)
            ->orderBy('b.supplier_name')
            ->get([
                'b.id', 'b.uuid', 'b.supplier_name', 'b.bid_reference', 'b.submitted_at',
                // Deliberately absent: bid_amount on a two-envelope review, and every other
                // evaluator's scores. See the class docblock.
                'e.id as evaluation_id', 'e.status as evaluation_status',
                'e.total_weighted_score', 'e.submitted_at as evaluation_submitted_at',
            ])
            ->map(function ($b) use ($review) {
                // Under two-envelope the financial envelope is not open to technical evaluators
                // until the technical stage concludes, so the price is withheld rather than shown
                // greyed out — a number you can read is a number that anchors your judgement.
                if ($review->methodology === 'TWO_ENVELOPE' && ! $review->evaluation_closed_at) {
                    $b->bid_amount = null;
                }

                return $b;
            });

        return Inertia::render('Modules/ProcurementGovernance/Evaluations/Review', [
            'review' => $review,
            'evaluator' => $evaluator,
            'bidders' => $bidders,
            'mayEvaluate' => $this->coi->mayEvaluate($evaluator),
            'coiStatuses' => ConflictOfInterestService::STATUSES,
            'context' => $this->capabilities->forResponse(),
        ]);
    }

    /** Score one bidder. */
    public function workspace(Request $request, string $uuid, string $bidderUuid): Response
    {
        $companyId = $this->requireCompany($request);
        [$review, $evaluator] = $this->mySeat($companyId, $uuid, $request);

        $bidder = DB::table('procgov_bidders')
            ->where('review_id', $review->id)
            ->where('uuid', $bidderUuid)
            ->whereNull('deleted_at')
            ->first();

        abort_if(! $bidder, 404);

        try {
            $workspace = $this->evaluations->workspace(
                $companyId,
                (int) $review->id,
                (int) $evaluator->id,
                (int) $bidder->id,
            );
        } catch (\RuntimeException $e) {
            abort(403, $e->getMessage());
        }

        if ($review->methodology === 'TWO_ENVELOPE' && ! $review->evaluation_closed_at) {
            $workspace['bidder']->bid_amount = null;
        }

        return Inertia::render('Modules/ProcurementGovernance/Evaluations/Workspace', array_merge(
            $workspace,
            [
                'history' => $this->evaluations->history($companyId, (int) $workspace['evaluation']->id),
                'context' => $this->capabilities->forResponse(),
            ],
        ));
    }

    public function saveDraft(Request $request, string $uuid, string $bidderUuid): RedirectResponse
    {
        $companyId = $this->requireCompany($request);
        [$review, $evaluator] = $this->mySeat($companyId, $uuid, $request);

        $data = $request->validate([
            'scores' => ['required', 'array'],
            'scores.*.score' => ['nullable', 'numeric'],
            'scores.*.passed' => ['nullable', 'boolean'],
            'scores.*.comment' => ['nullable', 'string', 'max:2000'],
            'scores.*.flagged' => ['nullable', 'boolean'],
            'overall_comment' => ['nullable', 'string', 'max:5000'],
            'has_concerns' => ['nullable', 'boolean'],
            'concern_note' => ['nullable', 'string', 'max:2000'],
        ]);

        $evaluation = $this->myEvaluation($companyId, $review, $evaluator, $bidderUuid);

        try {
            $this->evaluations->saveDraft(
                $companyId,
                (int) $evaluation->id,
                $data['scores'],
                $request->only(['overall_comment', 'has_concerns', 'concern_note']),
            );
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', __('procurement_governance.flash.evaluations.draft_saved'));
    }

    public function submit(Request $request, string $uuid, string $bidderUuid): RedirectResponse
    {
        $companyId = $this->requireCompany($request);
        [$review, $evaluator] = $this->mySeat($companyId, $uuid, $request);

        $evaluation = $this->myEvaluation($companyId, $review, $evaluator, $bidderUuid);

        $problems = $this->evaluations->submit($companyId, (int) $evaluation->id);

        if ($problems !== []) {
            return back()->with('error', implode(' ', $problems));
        }

        return redirect()
            ->route('procgov.evaluations.review', $review->uuid)
            ->with('success', __('procurement_governance.flash.evaluations.submitted'));
    }

    /**
     * §1.7 — answering a moderator's clarification.
     *
     * A moderator raising CLARIFY, RE_EVALUATE or ADJUST moves the item to AWAITING_RESPONSE, and
     * approving the committee's scores refuses while anything is OPEN or AWAITING_RESPONSE. This is
     * the only thing that moves an item out of that state, and it had no route — so raising a
     * clarification stopped the tender for good: it could not reach §1.8 without a database edit.
     *
     * The seat is checked first. Answering a clarification is evaluating, so only somebody appointed
     * to this panel may do it — the permission says a user may score at all, `mySeat` says they may
     * score THIS tender.
     */
    public function respondToModeration(Request $request, string $uuid, int $itemId): RedirectResponse
    {
        $companyId = $this->requireCompany($request);
        [$review, $evaluator] = $this->mySeat($companyId, $uuid, $request);

        $data = $request->validate([
            // A reply is the record of why a score stands or changed, so an empty one is worse than
            // none: it closes the item and leaves the audit trail saying nothing.
            'response' => ['required', 'string', 'min:3', 'max:2000'],
        ]);

        /*
         * The item has to belong to this tender and be addressed to the person answering. Without
         * both checks the route lets any appointed evaluator close any other evaluator's
         * clarification on any tender — which is the record of why a score stands.
         *
         * An item carries no review of its own; it hangs off the moderation, which is the thing tied
         * to the review. Scoped by company as well, because the id comes from the URL.
         */
        $item = DB::table('procgov_moderation_items as i')
            ->join('procgov_moderations as m', 'm.id', '=', 'i.moderation_id')
            ->where('i.id', $itemId)
            ->where('i.company_id', $companyId)
            ->where('m.review_id', $review->id)
            ->first(['i.id', 'i.status', 'i.target_evaluator_id']);

        if (! $item) {
            abort(404);
        }

        if ((int) $item->target_evaluator_id !== (int) $evaluator->id) {
            return back()->with('error', __('procurement_governance.flash.evaluations.not_your_clarification'));
        }

        try {
            $this->moderation->respond($companyId, $itemId, $data['response']);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', __('procurement_governance.flash.evaluations.clarification_answered'));
    }

    // ── §1.3 declarations ────────────────────────────────────────────────────────────────────────

    public function declare(Request $request, string $uuid): RedirectResponse
    {
        $companyId = $this->requireCompany($request);
        [, $evaluator] = $this->mySeat($companyId, $uuid, $request);

        $data = $request->validate([
            'has_conflict' => ['required', 'boolean'],
            'nature_of_interest' => ['nullable', 'string', 'max:2000'],
            'related_bidder_id' => ['nullable', 'integer'],
            'declaration_statement' => ['nullable', 'string', 'max:2000'],
            'accept_confidentiality' => ['nullable', 'boolean'],
        ]);

        try {
            $this->coi->declare($companyId, (int) $evaluator->id, $data);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with(
            'success',
            $data['has_conflict']
                ? __('procurement_governance.flash.evaluations.conflict_declared')
                : __('procurement_governance.flash.evaluations.declaration_recorded')
        );
    }

    public function acceptConfidentiality(Request $request, string $uuid): RedirectResponse
    {
        $companyId = $this->requireCompany($request);
        [, $evaluator] = $this->mySeat($companyId, $uuid, $request);

        $this->coi->acceptConfidentiality($companyId, (int) $evaluator->id);

        return back()->with('success', __('procurement_governance.flash.evaluations.confidentiality_accepted'));
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * The review, and *this user's* seat on it. 403 rather than 404 when they are not on the
     * committee: the review exists, they simply are not part of it, and pretending otherwise makes
     * a genuine access problem look like a broken link.
     *
     * @return array{0:object, 1:object}
     */
    private function mySeat(int $companyId, string $uuid, Request $request): array
    {
        $review = DB::table('procgov_tender_reviews')
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->where('uuid', $uuid)
            ->first();

        abort_if(! $review, 404);

        $evaluator = DB::table('procgov_review_evaluators')
            ->where('company_id', $companyId)
            ->where('review_id', $review->id)
            ->where('user_id', $request->user()->id)
            ->whereNotIn('status', ['REMOVED'])
            ->first();

        abort_if(! $evaluator, 403, 'You are not an evaluator on this tender review.');

        return [$review, $evaluator];
    }

    private function myEvaluation(int $companyId, object $review, object $evaluator, string $bidderUuid): object
    {
        $bidder = DB::table('procgov_bidders')
            ->where('review_id', $review->id)
            ->where('uuid', $bidderUuid)
            ->first();

        abort_if(! $bidder, 404);

        $evaluation = DB::table('procgov_evaluations')
            ->where('evaluator_id', $evaluator->id)
            ->where('bidder_id', $bidder->id)
            ->first();

        abort_if(! $evaluation, 404, 'Open the evaluation workspace before saving.');

        return $evaluation;
    }
}
