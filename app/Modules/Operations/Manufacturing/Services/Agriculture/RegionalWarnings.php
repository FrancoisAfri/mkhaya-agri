<?php

namespace App\Modules\Operations\Manufacturing\Services\Agriculture;

use App\Modules\Agri\Models\Farm;
use App\Modules\Agri\Models\WeatherWarning;
use App\Modules\Operations\Manufacturing\Models\Agriculture\Cycle;
use App\Modules\Operations\Manufacturing\Models\Agriculture\Recommendation;
use App\Modules\Operations\Manufacturing\Models\Agriculture\Source;
use Illuminate\Support\Carbon;

/**
 * A weather service's regional warning — a heatwave over a district, frost over a valley — matched
 * to the farms inside it.
 *
 * Each matched farm gets the warning on its own weather record (the farm's weather screen and
 * alerts already read those), and what it means for what is in production there: a heatwave over
 * a broiler house is a reason to check ventilation, cooling and water before the afternoon; frost
 * over a crop is a reason to protect it. Severe and extreme warnings open an incident.
 */
class RegionalWarnings
{
    public const ADVICE = [
        'heat' => ['poultry' => 'Check fans, cooling pads and drinker lines before the hottest hours; reduce stocking density if you can.',
            'greenhouse' => 'Open vents and run shade screens and misting early.', 'livestock' => 'Make sure shade and water reach every pen.',
            'crop' => 'Plan irrigation for early morning.'],
        'frost' => ['crop' => 'Protect sensitive crops; run frost irrigation if you have it.', 'greenhouse' => 'Close tunnels and check heaters.',
            'poultry' => 'Check brooders and heaters; close curtains for the night.'],
        'storm' => ['poultry' => 'Check generators and roof fixings; have a plan for a power cut.', 'crop' => 'Delay spraying and harvesting.',
            'greenhouse' => 'Secure plastic and close vents.', 'livestock' => 'Move animals off low ground.'],
        'rain' => ['crop' => 'Delay spraying; check drainage.', 'aquaculture' => 'Watch pond levels and water quality after heavy rain.'],
        'wind' => ['crop' => 'Do not spray.', 'greenhouse' => 'Secure plastic and close vents.'],
    ];

    public function __construct(private readonly Sources $sources, private readonly Incidents $incidents) {}

    /**
     * @param  array{external_id: string, kind: string, severity: string, latitude: float, longitude: float, radius_km: float, starts_at: string, ends_at?: ?string, message: string}  $w
     * @return list<int> the farms the warning covers
     */
    public function apply(Source $source, array $w): array
    {
        $pub = $this->sources->publish($source, ['external_id' => $w['external_id'], 'kind' => 'warning', 'title' => ucfirst($w['kind']).' warning ('.$w['severity'].')',
            'body' => $w['message'], 'published_at' => $w['starts_at']]);
        $message = $pub->body;
        $farms = Farm::withoutGlobalScopes()->where('company_id', $source->company_id)->whereNotNull('latitude')->where('status', Farm::ACTIVE)->get()
            ->filter(fn (Farm $f) => Outbreaks::distanceKm((float) $w['latitude'], (float) $w['longitude'], (float) $f->latitude, (float) $f->longitude) <= (float) $w['radius_km']);
        $trusted = $this->sources->trusted($pub->setRelation('source', $source));
        foreach ($farms as $farm) {
            WeatherWarning::withoutGlobalScopes()->updateOrCreate(['farm_id' => $farm->id, 'provider' => $source->code, 'external_id' => $w['external_id']],
                ['company_id' => $farm->company_id, 'kind' => $w['kind'], 'severity' => $w['severity'], 'starts_at' => Carbon::parse($w['starts_at']),
                    'ends_at' => ! empty($w['ends_at']) ? Carbon::parse($w['ends_at']) : null, 'message' => mb_strimwidth($message, 0, 1000), 'fetched_at' => now()]);
            // An unverified feed's warning is on the farm's weather record; it raises nothing until verified.
            if ($trusted) {
                $this->advise($source, $farm, $w, $pub->flagged);
            }
        }

        return $farms->pluck('id')->values()->all();
    }

    private function advise(Source $source, Farm $farm, array $w, bool $flagged): void
    {
        $categories = Cycle::query()->where('farm_id', $farm->id)->where('status', Cycle::ACTIVE)->with('type')->get()->pluck('type.category')->unique()->filter()->values();
        $advice = $categories->map(fn ($c) => self::ADVICE[$w['kind']][$c] ?? null)->filter()->unique()->values()->all();
        $serious = in_array(strtolower($w['severity']), ['severe', 'extreme', 'red', 'orange'], true);
        if ($serious) {
            $this->incidents->raise($farm->company_id, "weather:{$source->id}:{$w['external_id']}:{$farm->id}", ['kind' => 'weather',
                'severity' => in_array(strtolower($w['severity']), ['extreme', 'red'], true) ? 'critical' : 'warning',
                'title' => ucfirst($w['kind'])." warning for {$farm->name}", 'detail' => trim(mb_strimwidth($w['message'], 0, 400).' '.implode(' ', $advice)), 'farm_id' => $farm->id]);
        }
        if (! $advice || $flagged) {
            return;
        }
        $key = "weather:{$source->id}:{$w['external_id']}:{$farm->id}";
        Recommendation::query()->where('dedupe_key', $key)->exists() || Recommendation::create(['company_id' => $farm->company_id, 'farm_id' => $farm->id,
            'agent' => 'weather', 'title' => ucfirst($w['kind'])." warning — prepare {$farm->name}", 'dedupe_key' => $key,
            'rationale' => "{$source->name} issued a {$w['severity']} {$w['kind']} warning from ".Carbon::parse($w['starts_at'])->format('D j M H:i').'. '.implode(' ', $advice),
            'evidence' => ['source' => $source->name, 'trust' => $source->trust, 'warning' => $w['external_id'], 'severity' => $w['severity'], 'production' => $categories->all()],
            'action_kind' => 'task', 'action' => ['title' => ucfirst($w['kind']).' preparation', 'priority' => $serious ? 'urgent' : 'high', 'due_in_days' => 0],
            'priority' => $serious ? 'urgent' : 'high', 'confidence' => 0.8]);
    }
}
