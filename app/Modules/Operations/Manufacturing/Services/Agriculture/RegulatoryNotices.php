<?php

namespace App\Modules\Operations\Manufacturing\Services\Agriculture;

use App\Modules\Agri\Models\Farm;
use App\Modules\Operations\Manufacturing\Models\Agriculture\Cycle;
use App\Modules\Operations\Manufacturing\Models\Agriculture\Publication;
use App\Modules\Operations\Manufacturing\Models\Agriculture\Recommendation;
use App\Modules\Operations\Manufacturing\Models\Agriculture\Source;

/**
 * Regulatory notices — a new movement-control order, a registration deadline, a changed withdrawal
 * period, a labelling rule — matched to the farms they apply to by province and kind of production,
 * and put in front of the people who run those farms to read and file. Matching is a suggestion;
 * the notice itself is the authority.
 */
class RegulatoryNotices
{
    public function __construct(private readonly Sources $sources) {}

    /** @return list<int> the farms it was put in front of */
    public function publish(Source $source, array $item): array
    {
        $pub = $this->sources->publish($source, ['kind' => 'notice'] + $item);
        if ($pub->flagged) {
            return [];
        }
        $provinces = array_filter((array) ($item['provinces'] ?? []));
        $categories = array_filter((array) ($item['categories'] ?? []));
        $farms = [];
        foreach (Farm::withoutGlobalScopes()->where('company_id', $source->company_id)->where('status', Farm::ACTIVE)->get() as $farm) {
            $where = mb_strtolower(($farm->location ?? '').' '.($farm->address ?? ''));
            if ($provinces && ! collect($provinces)->contains(fn ($p) => str_contains($where, mb_strtolower($p)))) {
                continue;
            }
            if ($categories && ! Cycle::query()->where('farm_id', $farm->id)->whereIn('status', [Cycle::ACTIVE, Cycle::PLANNED])
                ->whereHas('type', fn ($q) => $q->whereIn('category', $categories))->exists()) {
                continue;
            }
            $key = "regulatory:{$pub->id}:{$farm->id}";
            Recommendation::query()->where('dedupe_key', $key)->exists() || Recommendation::create(['company_id' => $source->company_id, 'farm_id' => $farm->id,
                'agent' => 'regulatory', 'title' => "Read and file: {$pub->title}", 'dedupe_key' => $key,
                'priority' => $this->sources->trusted($pub) ? 'high' : 'normal',
                'rationale' => mb_strimwidth((string) $pub->body, 0, 600, '…').' — from '.$source->name.($this->sources->trusted($pub) ? '' : ' (not yet verified)').'. Read the notice itself before acting on it.',
                'evidence' => ['publication' => $pub->external_id, 'source' => $source->name, 'trust' => $source->trust, 'url' => $pub->url, 'provinces' => $provinces, 'categories' => $categories],
                'action_kind' => 'task', 'action' => ['title' => "Regulatory notice: {$pub->title}", 'priority' => 'high', 'due_in_days' => 7], 'confidence' => 0.8]);
            $farms[] = $farm->id;
        }

        return $farms;
    }
}
