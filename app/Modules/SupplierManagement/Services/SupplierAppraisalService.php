<?php

namespace App\Modules\SupplierManagement\Services;

use App\Modules\SupplierManagement\Models\SupplierAppraisal;
use App\Modules\SupplierManagement\Models\SupplierRiskFlag;
use App\Modules\SupplierManagement\Models\SupplierQuestionLibrary;
use Illuminate\Support\Facades\DB;

class SupplierAppraisalService
{
    /**
     * Store an appraisal and its weighted responses.
     *
     * Each answer carries a 1-5 star rating and the template question's weight %.
     * Per-question score = (rating / 5) * 100 * (weight% / 100).
     * total_score = sum of per-question scores (0-100).
     * pass/fail recorded vs the question's minimum_threshold (rating-based).
     *
     * @param  array<int, array{question_library_id:int, rating:int, comment?:string}>  $answers
     */
    public function store(int $supplierId, ?int $industryId, ?int $templateId, ?int $userId, array $answers): SupplierAppraisal
    {
        return DB::transaction(function () use ($supplierId, $industryId, $templateId, $userId, $answers) {
            $appraisal = SupplierAppraisal::create([
                'supplier_id'    => $supplierId,
                'industry_id'    => $industryId,
                'template_id'    => $templateId,
                'user_id'        => $userId,
                'appraisal_date' => now()->toDateString(),
                'status'         => 'completed',
                'total_score'    => 0,
            ]);

            $questions = SupplierQuestionLibrary::whereIn(
                'id',
                collect($answers)->pluck('question_library_id')->filter()->all()
            )->get()->keyBy('id');

            $total = 0.0;

            foreach ($answers as $answer) {
                $questionId = $answer['question_library_id'] ?? null;
                $rating     = (int) ($answer['rating'] ?? 0);
                $question   = $questionId ? $questions->get($questionId) : null;

                $weight     = $question ? (float) $question->weight_percentage : 0.0;
                $percentage = ($rating / 5) * 100;
                $score      = ($percentage * $weight) / 100;
                $total     += $score;

                $threshold = $question?->minimum_threshold;
                $passed    = $threshold === null ? null : ($rating >= $threshold);

                $appraisal->responses()->create([
                    'question_library_id' => $questionId,
                    'supplier_id'         => $supplierId,
                    // The answer a person gave, kept alongside the score it produced. The score is
                    // derived from it and the weight, so it cannot be read back the other way — a
                    // question weighted at zero scores zero whatever was given — and the rating is
                    // the evidence behind a decision the score only summarises.
                    'rating'              => $rating,
                    'response'            => $passed === null ? null : ($passed ? 'pass' : 'fail'),
                    'score'               => round($score, 2),
                    'comment'             => $answer['comment'] ?? null,
                ]);
            }

            $appraisal->update(['total_score' => round($total, 2)]);

            $this->flagPoorPerformance($appraisal);

            return $appraisal;
        });
    }

    /**
     * Make a bad score mean something.
     *
     * Scores were stored and charted and had no consequence anywhere — a supplier appraised at 20%
     * received new orders on exactly the same terms as one appraised at 95%, so the appraisal was a
     * report about the relationship rather than an input to it.
     *
     * A flag rather than a suspension, deliberately. One poor quarter is a conversation, and cutting
     * a supplier off automatically would make buyers stop recording honest scores. What the flag
     * changes is that the next order becomes a decision somebody makes, visible on the supplier's
     * record and in the risk queue, instead of one that happens by default.
     */
    private function flagPoorPerformance(SupplierAppraisal $appraisal): void
    {
        $threshold = (float) config('suppliers.appraisal_review_threshold', 50);

        if ((float) $appraisal->total_score > $threshold) {
            return;
        }

        $open = SupplierRiskFlag::where('supplier_id', $appraisal->supplier_id)
            ->where('type', 'poor_appraisal')
            ->where('status', 'open')
            ->first();

        $message = __('supplier_management.appraisals.poor_score_flag', [
            'score'     => number_format((float) $appraisal->total_score, 1),
            'threshold' => number_format($threshold, 0),
        ]);

        // Re-stamp an existing flag rather than stacking a new one each cycle, so the risk queue
        // shows one live concern per supplier instead of a year of duplicates.
        if ($open) {
            $open->update(['message' => $message, 'context' => ['appraisal_id' => $appraisal->id]]);

            return;
        }

        SupplierRiskFlag::create([
            'supplier_id' => $appraisal->supplier_id,
            'type'        => 'poor_appraisal',
            'severity'    => 'medium',
            'status'      => 'open',
            'message'     => $message,
            'context'     => ['appraisal_id' => $appraisal->id, 'score' => $appraisal->total_score],
        ]);
    }
}
