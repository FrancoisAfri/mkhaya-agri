<?php

namespace App\Modules\Agri\Services;

use App\Core\User\Models\User;
use App\Modules\Agri\Models\AgriReference;
use App\Modules\Agri\Models\CropPlan;
use App\Modules\Agri\Models\CropStageEvent;
use App\Modules\Agri\Models\CropType;
use App\Modules\Agri\Models\Farm;
use Illuminate\Validation\ValidationException;

/**
 * Phase 2 §10.4: advice only from what the farm has approved.
 *
 * An advisory question ("what should I spray…", "when should I plant…") is answered from approved
 * agronomic references, farm procedures and regulations — quoted, with where they come from — and
 * from nothing else. When the crop, the place or the growth stage the advice depends on is not
 * known, the assistant asks for it instead of guessing; when no approved reference covers the
 * question, it says so rather than offering generic advice.
 */
class AgriAdvisor
{
    private const ADVISORY = ['should i', 'should we', 'how do i', 'how should', 'how much', 'when to', 'when should', 'what to spray', 'what can i use',
        'recommend', 'advice', 'advise', 'best way', 'how to treat', 'what rate', 'which product'];

    private const STOP = ['should', 'would', 'could', 'what', 'when', 'which', 'with', 'from', 'this', 'that', 'there', 'their', 'have', 'does', 'into', 'about', 'apply', 'farm', 'field', 'crop'];

    public function isAdvisory(string $question): bool
    {
        $q = mb_strtolower($question);

        return collect(self::ADVISORY)->contains(fn ($w) => str_contains($q, $w));
    }

    /**
     * @return array{advisory: true, needs: list<string>, questions: list<string>, context: array, references: list<array>, summary: string}
     */
    public function advise(string $question, User $user, ?CropPlan $plan = null, ?Farm $farm = null): array
    {
        $q = mb_strtolower($question);
        $farm ??= $plan?->farm;
        $crop = $plan?->cropType ?? CropType::query()->get()->first(fn ($t) => str_contains($q, mb_strtolower($t->name)) || ($t->code && str_contains($q, mb_strtolower($t->code))));
        $stage = $plan ? CropStageEvent::query()->where('crop_plan_id', $plan->id)->latest('observed_on')->value('stage') : null;
        $region = $farm ? trim(implode(', ', array_filter([$farm->region ?? null, $farm->province ?? null, $farm->location ?? null]))) : null;

        $needs = [];
        $questions = [];
        if (! $crop) {
            $needs[] = 'crop';
            $questions[] = 'Which crop is this about? (Or ask from the crop plan, so I know.)';
        }
        if (! $farm) {
            $needs[] = 'location';
            $questions[] = 'Which farm or area is it for? Advice depends on the region.';
        }
        if (! $stage) {
            $needs[] = 'growth_stage';
            $questions[] = $plan ? "No growth stage is recorded on {$plan->code}. What stage is the crop at (e.g. emergence, vegetative, flowering)?" : 'What growth stage is the crop at?';
        }
        $context = ['crop' => $crop?->name, 'plan' => $plan?->code, 'farm' => $farm?->name, 'region' => $region ?: null, 'growth_stage' => $stage];
        if ($needs) {
            return ['advisory' => true, 'needs' => $needs, 'questions' => $questions, 'context' => $context, 'references' => [],
                'summary' => 'I need a little more before I can point you to approved advice: '.implode(' ', $questions)];
        }

        $words = collect(preg_split('/[^a-z0-9]+/', $q))->filter(fn ($w) => mb_strlen($w) > 3 && ! in_array($w, self::STOP, true))->values();
        $refs = AgriReference::query()->where('status', 'approved')->where(fn ($w) => $w->whereNull('review_by')->orWhere('review_by', '>=', today()))
            ->where(fn ($w) => $w->whereNull('crop_type_id')->orWhere('crop_type_id', $crop->id))->get()
            ->filter(fn ($r) => empty($r->stages) || in_array($stage, $r->stages, true))
            ->filter(fn ($r) => ! $r->region || ($region && str_contains(mb_strtolower($region), mb_strtolower($r->region))))
            ->map(function ($r) use ($words, $crop) {
                $hay = mb_strtolower($r->title.' '.implode(' ', $r->topics ?? []).' '.$r->content);
                $hits = $words->filter(fn ($w) => str_contains($hay, $w))->count();

                return ['ref' => $r, 'score' => $hits + ($r->crop_type_id === $crop->id ? 1 : 0)];
            })->filter(fn ($x) => $x['score'] > ($x['ref']->crop_type_id ? 1 : 0))->sortByDesc('score')->take(3)->values();

        if ($refs->isEmpty()) {
            return ['advisory' => true, 'needs' => [], 'questions' => [], 'context' => $context, 'references' => [],
                'summary' => "No approved reference covers this for {$crop->name} at {$stage}. I do not give general farming advice; ask your agronomist, or have a reference approved for it."];
        }

        return ['advisory' => true, 'needs' => [], 'questions' => [], 'context' => $context,
            'references' => $refs->map(fn ($x) => ['title' => $x['ref']->title, 'kind' => $x['ref']->kind, 'source' => $x['ref']->source,
                'approved_on' => $x['ref']->approved_at?->toDateString(), 'excerpt' => $this->excerpt($x['ref']->content, $words->all())])->all(),
            'summary' => "From the farm's approved references for {$crop->name} at {$stage}".($region ? " ({$region})" : '').'. Check the label and your agronomist before applying anything.'];
    }

    public function save(array $data, User $actor, ?AgriReference $ref = null): AgriReference
    {
        $fields = array_intersect_key($data, array_flip(['title', 'kind', 'crop_type_id', 'stages', 'region', 'topics', 'content', 'source', 'review_by']));
        if ($ref) {
            // A changed reference goes back to draft: what was approved is not what it now says.
            $ref->update($fields + ['status' => 'draft', 'approved_by' => null, 'approved_at' => null]);

            return $ref;
        }

        return AgriReference::create($fields + ['company_id' => $actor->company_id, 'status' => 'draft', 'created_by' => $actor->id]);
    }

    public function approve(AgriReference $ref, User $actor): AgriReference
    {
        if ($ref->status !== 'draft') {
            throw ValidationException::withMessages(['reference' => "That reference is {$ref->status}."]);
        }
        if ((int) $ref->created_by === (int) $actor->id) {
            throw ValidationException::withMessages(['reference' => 'Someone other than who wrote it approves a reference.']);
        }
        $ref->update(['status' => 'approved', 'approved_by' => $actor->id, 'approved_at' => now()]);

        return $ref;
    }

    private function excerpt(string $content, array $words): string
    {
        $sentences = preg_split('/(?<=[.!?])\s+/', trim($content)) ?: [$content];
        $best = collect($sentences)->sortByDesc(fn ($s) => collect($words)->filter(fn ($w) => str_contains(mb_strtolower($s), $w))->count())->take(3)->implode(' ');

        return mb_strimwidth($best, 0, 600, '…');
    }
}
