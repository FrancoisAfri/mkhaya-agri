<?php

namespace App\Modules\Operations\Manufacturing\Services\Agriculture;

use App\Core\User\Models\User;
use App\Modules\Agri\Models\Farm;
use App\Modules\Operations\Manufacturing\Models\Agriculture\Cycle;
use App\Modules\Operations\Manufacturing\Models\Agriculture\Opportunity;
use App\Modules\Operations\Manufacturing\Models\Agriculture\OpportunityMatch;
use App\Modules\Operations\Manufacturing\Models\Agriculture\Source;
use Illuminate\Validation\ValidationException;

/**
 * Grants, loans, programmes, tenders and markets — and which farms they may suit.
 *
 * A match is a suggestion with its reasons and what is not yet known. It never says a farm
 * qualifies (the funder decides) and nothing here applies for anything: a person reads the call,
 * decides, and applies through the funder. The disclaimer goes with every match.
 */
class Opportunities
{
    public const DISCLAIMER = 'A suggestion to look at, not a finding that the farm qualifies. The funder decides eligibility; Mkhaya does not apply on your behalf.';

    public function __construct(private readonly Sources $sources) {}

    public function register(?Source $source, array $data): Opportunity
    {
        $pub = $source ? $this->sources->publish($source, ['external_id' => $data['external_id'] ?? md5($data['title']), 'kind' => 'funding',
            'title' => $data['title'], 'body' => $data['summary'] ?? null, 'url' => $data['url'] ?? null]) : null;
        $o = Opportunity::query()->when($pub, fn ($q) => $q->where('publication_id', $pub->id), fn ($q) => $q->where('title', $data['title']))->first();
        $fields = ['company_id' => $source?->company_id ?? $data['company_id'], 'source_id' => $source?->id, 'publication_id' => $pub?->id,
            'kind' => $data['kind'] ?? 'grant', 'title' => $pub?->title ?? $this->sources->clean($data['title'], 255), 'funder' => $data['funder'] ?? $source?->publisher,
            'summary' => $pub?->body ?? $this->sources->clean((string) ($data['summary'] ?? ''), 5000), 'amount_max' => $data['amount_max'] ?? null,
            'currency' => $data['currency'] ?? 'ZAR', 'opens_on' => $data['opens_on'] ?? null, 'closes_on' => $data['closes_on'] ?? null,
            'eligibility' => $data['eligibility'] ?? null, 'url' => $pub?->url ?? ($data['url'] ?? null), 'status' => 'open'];
        $o ? $o->update($fields) : $o = Opportunity::create($fields);
        $this->match($o->fresh());

        return $o->fresh();
    }

    /** Score every farm of the organisation against the call. */
    public function match(Opportunity $o): int
    {
        if ($o->closes_on && $o->closes_on->isPast()) {
            $o->update(['status' => 'closed']);

            return 0;
        }
        $n = 0;
        foreach (Farm::withoutGlobalScopes()->where('company_id', $o->company_id)->where('status', Farm::ACTIVE)->get() as $farm) {
            [$score, $reasons, $gaps, $excluded] = $this->score($o, $farm);
            $m = OpportunityMatch::query()->where('opportunity_id', $o->id)->where('farm_id', $farm->id)->first();
            if ($excluded || $score < 40) {
                $m?->status === 'suggested' && $m->delete();

                continue;
            }
            $attrs = ['score' => $score, 'reasons' => $reasons, 'gaps' => $gaps ?: null];
            $m ? $m->update($attrs) : OpportunityMatch::create($attrs + ['company_id' => $o->company_id, 'opportunity_id' => $o->id, 'farm_id' => $farm->id]);
            $n++;
        }

        return $n;
    }

    public function decide(OpportunityMatch $m, string $status, User $actor): OpportunityMatch
    {
        if (! in_array($status, ['reviewing', 'applying', 'dismissed', 'suggested'], true)) {
            throw ValidationException::withMessages(['status' => 'Choose reviewing, applying or dismissed.']);
        }
        $m->update(['status' => $status, 'reviewed_by' => $actor->id]);

        return $m;
    }

    /** @return array{0: int, 1: list<string>, 2: list<string>, 3: bool} */
    private function score(Opportunity $o, Farm $farm): array
    {
        $e = $o->eligibility ?? [];
        $reasons = [];
        $gaps = [];
        $points = 20;
        $max = 20;
        $categories = Cycle::query()->where('farm_id', $farm->id)->whereIn('status', [Cycle::ACTIVE, Cycle::PLANNED])->with('type')->get()->pluck('type.category')->unique()->filter()->values()->all();
        if (! empty($e['categories'])) {
            $max += 40;
            $hit = array_values(array_intersect($e['categories'], $categories));
            if (! $hit) {
                return [0, [], [], true];
            }
            $points += 40;
            $reasons[] = 'The farm runs '.implode(' and ', $hit).' production, which the call covers.';
        }
        if (! empty($e['provinces'])) {
            $max += 25;
            $where = mb_strtolower(trim(($farm->location ?? '').' '.($farm->address ?? '')));
            $hit = collect($e['provinces'])->first(fn ($p) => $where !== '' && str_contains($where, mb_strtolower($p)));
            if ($where === '') {
                $gaps[] = 'The farm has no location recorded; the call is limited to '.implode(', ', $e['provinces']).'.';
            } elseif (! $hit) {
                return [0, [], [], true];
            } else {
                $points += 25;
                $reasons[] = "The farm is in {$hit}.";
            }
        }
        $ha = $farm->area ? (float) $farm->area * (Farm::TO_HECTARES[$farm->area_unit ?? 'ha'] ?? 1) : null;
        if (isset($e['max_hectares']) || isset($e['min_hectares'])) {
            $max += 15;
            if ($ha === null) {
                $gaps[] = 'The farm area is not recorded.';
            } elseif ((isset($e['max_hectares']) && $ha > $e['max_hectares']) || (isset($e['min_hectares']) && $ha < $e['min_hectares'])) {
                return [0, [], [], true];
            } else {
                $points += 15;
                $reasons[] = sprintf('At %.0f ha the farm is within the size the call asks for.', $ha);
            }
        }
        foreach ((array) ($e['requires'] ?? []) as $req) {
            $gaps[] = "Needs: {$req} — check before applying.";
        }
        if ($o->closes_on) {
            $reasons[] = 'Closes '.$o->closes_on->toDateString().' ('.today()->diffInDays($o->closes_on).' days).';
        }

        return [(int) round($points / max($max, 1) * 100), $reasons, $gaps, false];
    }
}
