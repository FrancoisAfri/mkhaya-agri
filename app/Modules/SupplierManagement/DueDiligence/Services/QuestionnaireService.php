<?php

namespace App\Modules\SupplierManagement\DueDiligence\Services;

use App\Modules\SupplierManagement\DueDiligence\Support\QuestionnaireTemplates;
use App\Modules\SupplierManagement\Models\DueDiligenceCase;
use App\Modules\SupplierManagement\Models\DueDiligenceQuestionnaire;
use App\Modules\SupplierManagement\Models\DueDiligenceQuestionnaireAnswer;
use App\Modules\SupplierManagement\Models\Supplier;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Sending questionnaire packs and scoring what comes back.
 *
 * The scoring rule is the whole point: a flagged answer is worth what the TEMPLATE says it is worth,
 * decided once when the pack was written, not judged by whoever happens to read it. A questionnaire
 * scored by reviewer opinion produces a number that varies with who was on shift, which is worse
 * than no number because it looks objective.
 *
 * Unanswered is not the same as answered well. A pack returned half-complete scores on what is
 * missing as well as on what is bad — otherwise the way to a clean questionnaire is to leave the
 * hard questions blank.
 */
class QuestionnaireService
{
    /** What a flagged answer contributes, by the severity the template gave it. */
    private const FLAG_WEIGHT = [
        'critical' => 100.0,
        'high' => 60.0,
        'medium' => 30.0,
        'low' => 12.0,
    ];

    /** Send a pack, or return the one already outstanding. */
    public function send(Supplier $supplier, string $pack, ?DueDiligenceCase $case = null, ?string $dueOn = null): DueDiligenceQuestionnaire
    {
        $template = QuestionnaireTemplates::get($pack);

        if (! $template) {
            throw new \InvalidArgumentException("There is no \"{$pack}\" questionnaire.");
        }

        $existing = DueDiligenceQuestionnaire::query()
            ->where('supplier_id', $supplier->id)
            ->where('pack', $pack)
            ->whereIn('status', [DueDiligenceQuestionnaire::STATUS_SENT, DueDiligenceQuestionnaire::STATUS_IN_PROGRESS])
            ->first();

        if ($existing) {
            return $existing;
        }

        return DB::transaction(function () use ($supplier, $pack, $template, $case, $dueOn) {
            $questionnaire = DueDiligenceQuestionnaire::create([
                'supplier_id' => $supplier->id,
                'case_id' => $case?->id,
                'pack' => $pack,
                'title' => $template['title'],
                'status' => DueDiligenceQuestionnaire::STATUS_SENT,
                'sent_by' => Auth::id(),
                'sent_at' => Carbon::now(),
                'due_on' => $dueOn ?? Carbon::now()->addDays(14)->toDateString(),
            ]);

            // The questions are copied onto the questionnaire rather than referenced, so a pack
            // answered in March still reads as the pack that was actually asked after somebody edits
            // the template in June.
            foreach ($template['questions'] as $question) {
                DueDiligenceQuestionnaireAnswer::create([
                    'questionnaire_id' => $questionnaire->id,
                    'code' => $question['code'],
                    'question' => $question['question'],
                    'type' => $question['type'],
                ]);
            }

            return $questionnaire;
        });
    }

    /** Send every pack this engagement's tier calls for. */
    public function sendForTier(Supplier $supplier, ?DueDiligenceCase $case = null): array
    {
        return collect(QuestionnaireTemplates::forTier($supplier->risk_tier))
            ->map(fn (string $pack) => $this->send($supplier, $pack, $case))
            ->all();
    }

    /**
     * Record answers and rescore.
     *
     * @param  array<string, array{answer:mixed, note?:string}>  $answers  keyed by question code
     */
    public function record(DueDiligenceQuestionnaire $questionnaire, array $answers, bool $submit = false): DueDiligenceQuestionnaire
    {
        $template = $questionnaire->template();
        $definitions = collect($template['questions'] ?? [])->keyBy('code');

        foreach ($answers as $code => $given) {
            $answer = $questionnaire->answers()->where('code', $code)->first();

            if (! $answer) {
                continue;
            }

            $value = is_array($given) ? ($given['answer'] ?? null) : $given;
            $definition = $definitions->get($code);

            $answer->update([
                'answer' => is_bool($value) ? ($value ? 'yes' : 'no') : (string) $value,
                'note' => is_array($given) ? ($given['note'] ?? null) : null,
                // Decided from the template, when the answer is recorded.
                'is_flag' => $this->isFlag($definition, $value),
                'flag_severity' => $this->isFlag($definition, $value) ? ($definition['severity'] ?? 'medium') : null,
            ]);
        }

        return $this->rescore($questionnaire->fresh(), $submit);
    }

    /** Recompute completeness, score and flags. */
    public function rescore(DueDiligenceQuestionnaire $questionnaire, bool $submit = false): DueDiligenceQuestionnaire
    {
        $answers = $questionnaire->answers()->get();
        $total = $answers->count();
        $answered = $answers->filter->isAnswered()->count();
        $completeness = $total > 0 ? (int) round($answered / $total * 100) : 0;

        $flags = $answers->where('is_flag', true);

        // The worst flag sets the floor and the rest add a bounded tail — the same shape as the
        // reputation score, and for the same reason: one critical answer must outrank five minor
        // ones rather than being averaged away by them.
        $weights = $flags->map(fn ($a) => self::FLAG_WEIGHT[$a->flag_severity ?? 'medium'] ?? 30.0)->sortDesc()->values();
        $score = $weights->isEmpty() ? 0.0 : (float) $weights->first();

        foreach ($weights->slice(1) as $index => $weight) {
            $score += $weight * (0.4 ** ($index + 1));
        }

        // Unanswered questions score too. Otherwise the route to a clean questionnaire is to leave
        // the hard ones blank.
        $unanswered = $total - $answered;
        $score += min(30.0, $unanswered * 6.0);

        $questionnaire->forceFill([
            'completeness' => $completeness,
            'risk_score' => round(min(100.0, $score), 2),
            'flags' => $flags->map(fn ($a) => [
                'code' => $a->code,
                'question' => $a->question,
                'answer' => $a->answer,
                'severity' => $a->flag_severity,
            ])->values()->all(),
            'status' => $submit
                ? DueDiligenceQuestionnaire::STATUS_SUBMITTED
                : ($answered > 0 ? DueDiligenceQuestionnaire::STATUS_IN_PROGRESS : $questionnaire->status),
            'submitted_at' => $submit ? Carbon::now() : $questionnaire->submitted_at,
        ])->save();

        return $questionnaire->fresh();
    }

    /**
     * The combined questionnaire position for a supplier, for the risk engine.
     *
     * @return array{score:float, covered:bool, reasons:array<int,string>}
     */
    public function position(Supplier $supplier): array
    {
        $questionnaires = DueDiligenceQuestionnaire::query()
            ->where('supplier_id', $supplier->id)
            ->get();

        if ($questionnaires->isEmpty()) {
            return ['score' => 0.0, 'covered' => false, 'reasons' => ['No questionnaire has been sent.']];
        }

        $outstanding = $questionnaires->reject->isComplete();
        $scored = $questionnaires->filter->isComplete();

        $reasons = [];

        if ($outstanding->isNotEmpty()) {
            $reasons[] = $outstanding->count().' questionnaire(s) outstanding: '.$outstanding->pluck('title')->implode(', ').'.';
        }

        foreach ($scored as $questionnaire) {
            foreach ($questionnaire->flags ?? [] as $flag) {
                $reasons[] = $flag['question'].' — answered "'.$flag['answer'].'".';
            }
        }

        // An outstanding pack is not a clean one: a supplier who has not answered has not answered
        // well, and scoring silence as zero is how the questionnaire becomes optional in practice.
        $score = $scored->isEmpty()
            ? 45.0
            : (float) $scored->max('risk_score');

        $score += min(25.0, $outstanding->count() * 15.0);

        return [
            'score' => round(min(100.0, $score), 2),
            'covered' => $scored->isNotEmpty(),
            'reasons' => array_slice($reasons, 0, 6),
        ];
    }

    /** Does this answer trip the template's flag? */
    private function isFlag(?array $definition, mixed $value): bool
    {
        $flagWhen = $definition['flag_when'] ?? null;

        if ($flagWhen === null || $value === null || $value === '') {
            return false;
        }

        $normalised = is_bool($value) ? ($value ? 'yes' : 'no') : mb_strtolower(trim((string) $value));

        return $normalised === $flagWhen;
    }
}
