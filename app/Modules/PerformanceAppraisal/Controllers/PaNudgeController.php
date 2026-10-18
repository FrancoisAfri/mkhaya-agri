<?php

namespace App\Modules\PerformanceAppraisal\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\PerformanceAppraisal\Models\PaNudge;
use App\Modules\PerformanceAppraisal\Models\PaPerformanceSnapshot;
use App\Modules\PerformanceAppraisal\Services\PaNudgeService;
use App\Modules\PerformanceAppraisal\Services\PaReviewDraftService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class PaNudgeController extends Controller
{
    public function __construct(
        private PaNudgeService $nudges,
        private PaReviewDraftService $drafts,
    ) {}

    /** Active nudges for the current user — consumed by a dashboard widget. */
    public function index(Request $request): JsonResponse
    {
        $items = PaNudge::active()
            ->where('user_id', $request->user()->id)
            ->latest()
            ->limit(25)
            ->get()
            ->map(fn ($n) => [
                'id'       => $n->id,
                'type'     => $n->type,
                'priority' => $n->priority,
                'title'    => $n->title,
                'body'     => $n->body,
                'route'    => $n->action_route ? route($n->action_route) : null,
                'at'       => $n->created_at?->diffForHumans(),
            ]);

        return response()->json(['nudges' => $items]);
    }

    public function dismiss(Request $request, PaNudge $nudge): RedirectResponse
    {
        abort_unless($nudge->user_id === $request->user()->id, 403);
        $nudge->update(['dismissed_at' => now()]);

        return back();
    }

    /** Manually trigger nudge generation (admin/HR). */
    public function generate(): RedirectResponse
    {
        $n = $this->nudges->generateAll();

        return back()->with('success', "{$n} nudges generated.");
    }

    /** AI-draft a manager review narrative for a snapshot. */
    public function draftReview(PaPerformanceSnapshot $snapshot): JsonResponse
    {
        $draft = $this->drafts->draft($snapshot);

        return response()->json(['draft' => $draft]);
    }
}
