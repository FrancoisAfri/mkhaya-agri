<?php

namespace App\Modules\HR\LMS\Http\Controllers;

use App\Core\User\Models\User;
use App\Http\Controllers\Controller;
use App\Modules\HR\LMS\Models\LmsManualMarking;
use App\Modules\HR\LMS\Models\LmsQuestion;
use App\Modules\HR\LMS\Models\LmsQuestionnaireAttempt;
use App\Modules\HR\LMS\Models\LmsQuestionResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class LmsMarkingController extends Controller
{
    // ── Marking queue ────────────────────────────────────────────────────────

    public function index(): Response
    {
        $pending = LmsQuestionnaireAttempt::where('status', 'pending_review')
            ->with(['user.person', 'questionnaire'])
            ->withCount('manualMarkings as total_items')
            ->withCount(['manualMarkings as pending_count' => fn ($q) => $q->where('status', 'pending')])
            ->withCount(['manualMarkings as reviewed_count' => fn ($q) => $q->where('status', 'reviewed')])
            ->latest('completed_at')
            ->get()
            ->map(fn ($a) => $this->formatSummary($a));

        $recentlyFinalized = LmsQuestionnaireAttempt::whereIn('status', ['passed', 'failed', 'completed'])
            ->whereHas('manualMarkings')
            ->with(['user.person', 'questionnaire'])
            ->withCount('manualMarkings as total_items')
            ->withCount(['manualMarkings as pending_count' => fn ($q) => $q->where('status', 'pending')])
            ->withCount(['manualMarkings as reviewed_count' => fn ($q) => $q->where('status', 'reviewed')])
            ->latest('completed_at')
            ->limit(30)
            ->get()
            ->map(fn ($a) => $this->formatSummary($a));

        $markers = User::whereHas('person')
            ->get()
            ->map(fn ($u) => [
                'id'   => $u->id,
                'name' => optional($u->person)->first_name . ' ' . optional($u->person)->surname,
            ]);

        return Inertia::render('Modules/LMS/Admin/MarkingQueue', [
            'pending'           => $pending,
            'recentlyFinalized' => $recentlyFinalized,
            'markers'           => $markers,
        ]);
    }

    // ── Marking detail for one attempt ──────────────────────────────────────

    public function show(LmsQuestionnaireAttempt $attempt): Response
    {
        $attempt->load([
            'user.person',
            'questionnaire',
            'responses',
            'manualMarkings.question',
            'manualMarkings.markedBy.person',
            'manualMarkings.assignedTo.person',
        ]);

        $markings = $attempt->manualMarkings->map(function ($m) use ($attempt) {
            $response = $attempt->responses->firstWhere('question_id', $m->question_id);

            return [
                'id'               => $m->id,
                'status'           => $m->status,
                'max_points'       => $m->max_points,
                'points_awarded'   => $m->points_awarded,
                'marker_notes'     => $m->marker_notes,
                'marked_by_name'   => $m->markedBy?->person
                    ? $m->markedBy->person->first_name . ' ' . $m->markedBy->person->surname
                    : null,
                'assigned_to_name' => $m->assignedTo?->person
                    ? $m->assignedTo->person->first_name . ' ' . $m->assignedTo->person->surname
                    : null,
                'marked_at'        => $m->marked_at?->toDateTimeString(),
                'question'         => [
                    'id'            => $m->question->id,
                    'type'          => $m->question->type,
                    'type_label'    => LmsQuestion::TYPE_LABELS[$m->question->type] ?? $m->question->type,
                    'question_text' => $m->question->question_text,
                    'context_text'  => $m->question->context_text,
                    'points'        => $m->question->points,
                ],
                'response' => $response ? [
                    'id'            => $response->id,
                    'response_text' => $response->response_text,
                    'response_json' => $response->response_json,
                    'file_name'     => $response->file_name,
                    'has_file'      => !empty($response->file_path),
                    'download_url'  => !empty($response->file_path)
                        ? route('lms.admin.marking.response.download', [
                            'attempt'  => $attempt->id,
                            'response' => $response->id,
                        ])
                        : null,
                ] : null,
            ];
        });

        $markers = User::whereHas('person')
            ->get()
            ->map(fn ($u) => [
                'id'   => $u->id,
                'name' => optional($u->person)->first_name . ' ' . optional($u->person)->surname,
            ]);

        return Inertia::render('Modules/LMS/Admin/MarkingDetail', [
            'attempt' => [
                'id'                  => $attempt->id,
                'status'              => $attempt->status,
                'score'               => $attempt->score,
                'attempt_number'      => $attempt->attempt_number,
                'completed_at'        => $attempt->completed_at?->toDateTimeString(),
                'learner_name'        => $attempt->user->person
                    ? $attempt->user->person->first_name . ' ' . $attempt->user->person->surname
                    : '—',
                'questionnaire_title' => $attempt->questionnaire->title,
                'questionnaire_type'  => $attempt->questionnaire->type,
                'pass_score'          => $attempt->questionnaire->pass_score,
            ],
            'markings'   => $markings,
            'markers'    => $markers,
            'allReviewed'=> $attempt->manualMarkings->every(fn ($m) => $m->status === 'reviewed'),
        ]);
    }

    // ── Mark a single response ────────────────────────────────────────────────

    public function markResponse(Request $request, LmsQuestionnaireAttempt $attempt, LmsManualMarking $marking): RedirectResponse
    {
        abort_unless($marking->attempt_id === $attempt->id, 404);

        $request->validate([
            'points_awarded' => ['required', 'numeric', 'min:0', 'max:' . $marking->max_points],
            'marker_notes'   => ['nullable', 'string'],
        ]);

        $marking->update([
            'points_awarded' => $request->points_awarded,
            'marker_notes'   => $request->marker_notes,
            'status'         => 'reviewed',
            'marked_by'      => Auth::id(),
            'marked_at'      => now(),
        ]);

        // Mirror onto the question response so the auto-grade total is correct
        $marking->response->update([
            'is_correct'     => $request->points_awarded > 0,
            'points_awarded' => $request->points_awarded,
            'reviewed_by'    => Auth::id(),
            'reviewed_at'    => now(),
            'reviewer_notes' => $request->marker_notes,
        ]);

        return redirect()->back()->with('success', 'Response marked.');
    }

    // ── Assign all pending markings to a reviewer ────────────────────────────

    public function assign(Request $request, LmsQuestionnaireAttempt $attempt): RedirectResponse
    {
        $request->validate([
            'assigned_to' => ['required', 'integer', 'exists:users,id'],
        ]);

        LmsManualMarking::where('attempt_id', $attempt->id)
            ->whereIn('status', ['pending'])
            ->update([
                'assigned_to' => $request->assigned_to,
                'status'      => 'in_review',
            ]);

        return redirect()->back()->with('success', 'Attempt assigned to marker.');
    }

    // ── Finalise — compute total score including manual marks ─────────────────

    public function finalize(LmsQuestionnaireAttempt $attempt): RedirectResponse
    {
        $pendingCount = $attempt->manualMarkings()->where('status', '!=', 'reviewed')->count();

        if ($pendingCount > 0) {
            return redirect()->back()->withErrors([
                'finalize' => "{$pendingCount} question(s) are still pending review.",
            ]);
        }

        $attempt->finalizeScore(Auth::id());

        return redirect()->route('lms.admin.marking')->with('success', 'Marking finalised — learner result updated.');
    }

    // ── Download a submitted file (admin access) ─────────────────────────────

    public function downloadResponseFile(LmsQuestionnaireAttempt $attempt, LmsQuestionResponse $response)
    {
        abort_unless($response->attempt_id === $attempt->id, 404);
        abort_unless(!empty($response->file_path) && !empty($response->file_disk), 404);

        return Storage::disk($response->file_disk)->download(
            $response->file_path,
            $response->file_name ?? 'download'
        );
    }

    // ── Private helpers ──────────────────────────────────────────────────────

    private function formatSummary(LmsQuestionnaireAttempt $a): array
    {
        return [
            'id'                  => $a->id,
            'learner_name'        => $a->user->person
                ? $a->user->person->first_name . ' ' . $a->user->person->surname
                : '—',
            'questionnaire_title' => $a->questionnaire->title,
            'questionnaire_type'  => $a->questionnaire->type,
            'status'              => $a->status,
            'score'               => $a->score,
            'attempt_number'      => $a->attempt_number,
            'completed_at'        => $a->completed_at?->toDateTimeString(),
            'total_items'         => $a->total_items,
            'pending_count'       => $a->pending_count,
            'reviewed_count'      => $a->reviewed_count,
        ];
    }
}
