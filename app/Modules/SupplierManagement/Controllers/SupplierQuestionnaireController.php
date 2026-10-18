<?php

namespace App\Modules\SupplierManagement\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\SupplierManagement\DueDiligence\Services\AlertRouter;
use App\Modules\SupplierManagement\DueDiligence\Services\DueDiligenceSummary;
use App\Modules\SupplierManagement\DueDiligence\Services\QuestionnaireService;
use App\Modules\SupplierManagement\DueDiligence\Services\RiskEngine;
use App\Modules\SupplierManagement\DueDiligence\Support\QuestionnaireTemplates;
use App\Modules\SupplierManagement\Models\DueDiligenceCase;
use App\Modules\SupplierManagement\Models\DueDiligenceQuestionnaire;
use App\Modules\SupplierManagement\Models\Supplier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * Questionnaire packs, alert routing and the written summary.
 *
 * Grouped because they are the three things that turn a file of checks into something a committee
 * can act on: what the supplier told us, who gets told when it changes, and the page somebody reads
 * before the meeting.
 */
class SupplierQuestionnaireController extends Controller
{
    public function __construct(
        private readonly QuestionnaireService $questionnaires,
        private readonly RiskEngine $risk,
    ) {}

    /** Send a pack, or every pack this engagement's tier calls for. */
    public function send(Request $request, Supplier $supplier): RedirectResponse
    {
        $this->authorize('update', $supplier);

        $data = $request->validate([
            'pack' => ['nullable', Rule::in(array_keys(QuestionnaireTemplates::all()))],
            'due_on' => ['nullable', 'date', 'after:today'],
        ]);

        $case = DueDiligenceCase::openFor($supplier->id);

        $sent = empty($data['pack'])
            ? $this->questionnaires->sendForTier($supplier, $case)
            : [$this->questionnaires->send($supplier, $data['pack'], $case, $data['due_on'] ?? null)];

        return back()->with('success', __('supplier_management.questionnaires.sent', ['n' => count($sent)]));
    }

    /**
     * Record answers.
     *
     * Answers can arrive part-complete and be finished later: a nine-question ESG pack that has to be
     * completed in one sitting is one that gets abandoned halfway and then submitted with guesses.
     */
    public function record(Request $request, Supplier $supplier, DueDiligenceQuestionnaire $questionnaire): RedirectResponse
    {
        $this->authorize('update', $supplier);

        abort_unless((int) $questionnaire->supplier_id === (int) $supplier->id, 404);

        $data = $request->validate([
            'answers' => ['required', 'array'],
            'answers.*' => ['nullable'],
            'submit' => ['nullable', 'boolean'],
        ]);

        $questionnaire = $this->questionnaires->record($questionnaire, $data['answers'], (bool) ($data['submit'] ?? false));
        $this->risk->assess($supplier->fresh(), 'event');

        // A flagged answer is a finding, and findings are routed rather than left on a screen.
        if ($questionnaire->isComplete() && ($questionnaire->flags ?? []) !== []) {
            $worst = collect($questionnaire->flags)->pluck('severity')->contains('critical') ? 'critical' : 'high';

            app(AlertRouter::class)->dispatch(
                $supplier,
                'questionnaire',
                $worst,
                sprintf('%s returned %d flagged answer(s).', $questionnaire->title, count($questionnaire->flags)),
                ['questionnaire_id' => $questionnaire->id],
            );
        }

        return back()->with('success', __('supplier_management.questionnaires.recorded', [
            'completeness' => $questionnaire->completeness,
        ]));
    }

    public function review(Request $request, Supplier $supplier, DueDiligenceQuestionnaire $questionnaire): RedirectResponse
    {
        $this->authorize('update', $supplier);

        abort_unless((int) $questionnaire->supplier_id === (int) $supplier->id, 404);

        $data = $request->validate(['note' => ['nullable', 'string', 'max:2000']]);

        $questionnaire->update([
            'status' => DueDiligenceQuestionnaire::STATUS_REVIEWED,
            'reviewed_by' => Auth::id(),
            'reviewed_at' => now(),
            'review_note' => $data['note'] ?? null,
        ]);

        return back()->with('success', __('supplier_management.questionnaires.reviewed'));
    }

    /** The written summary, assembled from the facts and labelled as AI-written where it is. */
    public function summary(Supplier $supplier)
    {
        $this->authorize('view', $supplier);

        return response()->json(app(DueDiligenceSummary::class)->for($supplier));
    }

    /** Who hears about what. */
    public function saveRouting(Request $request): RedirectResponse
    {
        $this->authorize('approve', Supplier::class);

        $data = $request->validate([
            'routing' => ['required', 'array'],
            'routing.*' => ['nullable', 'array'],
            'routing.*.*' => ['string', 'max:60'],
        ]);

        app(AlertRouter::class)->save($data['routing']);

        return back()->with('success', __('supplier_management.alerts.routing_saved'));
    }
}
