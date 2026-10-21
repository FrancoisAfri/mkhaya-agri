<?php

namespace App\Modules\Operations\Manufacturing\Services\Agriculture;

use App\Modules\Agri\Models\Farm;
use App\Modules\Operations\Manufacturing\Models\Agriculture\Cycle;
use App\Modules\Operations\Manufacturing\Models\Agriculture\Exposure;
use App\Modules\Operations\Manufacturing\Models\Agriculture\Outbreak;
use App\Modules\Operations\Manufacturing\Models\Agriculture\Recommendation;
use App\Modules\Operations\Manufacturing\Models\Agriculture\Source;
use Illuminate\Support\Facades\DB;

/**
 * Reported disease outbreaks and which farms are near them.
 *
 * An outbreak is the source's report, kept with its provenance and every correction: a second
 * report with the same external id is the same outbreak revised (status confirmed, location
 * moved, radius widened), never a second outbreak. After each change the exposures are worked out
 * again — farms that came into range are added, farms that fell out are closed — so a farm is
 * listed once per outbreak.
 *
 * An exposure is distance, not diagnosis. It says how far the farm is from the report and asks for
 * a biosecurity review. No farm, flock or herd is ever marked infected because of it; only a
 * veterinarian's finding recorded on the farm does that.
 */
class Outbreaks
{
    /** Bands, as multiples of the outbreak's radius. */
    public const BANDS = ['inside' => 1.0, 'near' => 2.0, 'watch' => 3.0];

    public const SPECIES_CATEGORIES = ['poultry' => ['poultry'], 'cattle' => ['livestock', 'dairy'], 'pigs' => ['livestock'], 'sheep' => ['livestock'],
        'goats' => ['livestock'], 'fish' => ['aquaculture'], 'crops' => ['crop', 'greenhouse']];

    public function __construct(private readonly Sources $sources, private readonly Incidents $incidents) {}

    /** @param  array{external_id: string, disease: string, species?: ?string, status: string, latitude?: ?float, longitude?: ?float, radius_km?: ?float, region?: ?string, reported_on?: ?string, title?: ?string, body?: ?string, url?: ?string}  $report */
    public function report(Source $source, array $report): Outbreak
    {
        if (! in_array($report['status'], ['suspected', 'confirmed', 'resolved', 'retracted'], true)) {
            throw new \InvalidArgumentException('An outbreak is suspected, confirmed, resolved or retracted.');
        }
        $publication = $this->sources->publish($source, ['external_id' => $report['external_id'], 'kind' => 'outbreak',
            'title' => $report['title'] ?? "{$report['disease']} — {$report['status']}", 'body' => $report['body'] ?? null, 'url' => $report['url'] ?? null,
            'published_at' => $report['reported_on'] ?? null]);

        return DB::transaction(function () use ($source, $report, $publication) {
            $fields = ['disease' => $this->sources->clean($report['disease'], 120), 'species' => $report['species'] ?? null, 'status' => $report['status'],
                'latitude' => $report['latitude'] ?? null, 'longitude' => $report['longitude'] ?? null, 'radius_km' => $report['radius_km'] ?? 10,
                'region' => isset($report['region']) ? $this->sources->clean($report['region'], 160) : null, 'reported_on' => $report['reported_on'] ?? today()->toDateString()];
            $o = Outbreak::query()->where('source_id', $source->id)->where('external_id', $report['external_id'])->lockForUpdate()->first();
            if ($o) {
                $changed = collect($fields)->filter(fn ($v, $k) => (string) $o->getRawOriginal($k) !== (string) $v && ! ($o->{$k} == $v))->keys()->all();
                if ($changed) {
                    $o->update($fields + ['revision' => $o->revision + 1, 'publication_id' => $publication->id,
                        'history' => [...($o->history ?? []), ['revision' => $o->revision + 1, 'at' => now()->toIso8601String(), 'changed' => $changed,
                            'before' => collect($o->getOriginal())->only($changed)->map(fn ($v) => $v instanceof \DateTimeInterface ? $v->format('Y-m-d') : $v)->all()]]]);
                }
            } else {
                $o = Outbreak::create($fields + ['company_id' => $source->company_id, 'source_id' => $source->id, 'publication_id' => $publication->id,
                    'external_id' => $report['external_id'], 'history' => [['revision' => 1, 'at' => now()->toIso8601String(), 'changed' => ['reported']]]]);
            }
            $this->assess($o->fresh());

            return $o->fresh();
        });
    }

    /** Work out which farms are near the outbreak, adding, updating and closing exposures. */
    public function assess(Outbreak $o): array
    {
        $over = in_array($o->status, ['resolved', 'retracted'], true) || $o->latitude === null;
        $seen = [];
        if (! $over) {
            $categories = self::SPECIES_CATEGORIES[$o->species] ?? null;
            $farms = Farm::withoutGlobalScopes()->where('company_id', $o->company_id)->whereNotNull('latitude')->whereNotNull('longitude')->where('status', '!=', 'archived')->get();
            foreach ($farms as $farm) {
                $km = self::distanceKm((float) $o->latitude, (float) $o->longitude, (float) $farm->latitude, (float) $farm->longitude);
                $band = collect(self::BANDS)->filter(fn ($m) => $km <= $o->radius_km * $m)->keys()->first();
                if (! $band) {
                    continue;
                }
                // Only farms that keep what the disease affects; unknown species reaches every farm.
                if ($categories && ! $this->keeps($farm, $categories)) {
                    continue;
                }
                $seen[] = $farm->id;
                $statement = sprintf('A %s report of %s from %s is %.1f km from %s%s. This is not a diagnosis and the farm is not marked infected; it is a reason to review biosecurity%s.',
                    $o->status, $o->disease, $o->source?->name ?? 'a registered source', $km, $farm->name, $band === 'inside' ? ', inside the reported zone' : '',
                    $band === 'inside' ? ' now and follow any movement controls the authority issues' : '');
                $e = Exposure::query()->where('outbreak_id', $o->id)->where('farm_id', $farm->id)->first();
                $attrs = ['distance_km' => round($km, 2), 'band' => $band, 'statement' => $statement, 'assessed_at' => now()];
                if ($e) {
                    $e->update($attrs + ['status' => $e->status === 'closed' ? 'open' : $e->status]);
                } else {
                    $e = Exposure::create($attrs + ['company_id' => $o->company_id, 'outbreak_id' => $o->id, 'farm_id' => $farm->id, 'status' => 'open']);
                }
                $this->follow($o, $e, $farm, $band);
            }
        }
        Exposure::query()->where('outbreak_id', $o->id)->when($seen, fn ($q) => $q->whereNotIn('farm_id', $seen))->where('status', '!=', 'closed')
            ->update(['status' => 'closed', 'assessed_at' => now()]);

        return $seen;
    }

    /** Great-circle distance in kilometres. */
    public static function distanceKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $r * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    private function keeps(Farm $farm, array $categories): bool
    {
        return Cycle::query()->where('farm_id', $farm->id)->whereIn('status', [Cycle::ACTIVE, Cycle::PLANNED])
            ->whereHas('type', fn ($q) => $q->whereIn('category', $categories))->exists();
    }

    /** An incident for the farm and a biosecurity review proposed — once, however often the report is revised. */
    private function follow(Outbreak $o, Exposure $e, Farm $farm, string $band): void
    {
        if ($band === 'watch') {
            return;
        }
        // A community or unverified report is shown and proposed for verification, never alarmed on.
        $publication = $o->publication_id ? \App\Modules\Operations\Manufacturing\Models\Agriculture\Publication::query()->with('source')->find($o->publication_id) : null;
        if ($publication && ! $this->sources->trusted($publication)) {
            $key = "verify-outbreak:{$o->id}:{$farm->id}";
            $rec = Recommendation::query()->where('dedupe_key', $key)->first() ?? Recommendation::create(['company_id' => $o->company_id, 'farm_id' => $farm->id,
                'agent' => 'biosecurity', 'title' => "Verify a unverified report: {$o->disease} near {$farm->name}", 'dedupe_key' => $key, 'priority' => 'normal',
                'rationale' => "{$e->statement} The report comes from {$publication->source?->name}, which is not an official or trusted source. Check it with the state veterinarian or a second source, then verify it under Intelligence → Sources so it is assessed with alarms.",
                'evidence' => ['outbreak' => $o->uuid, 'source' => $publication->source?->name, 'trust' => $publication->source?->trust, 'distance_km' => $e->distance_km],
                'action_kind' => 'review', 'confidence' => 0.3]);
            $e->update(['recommendation_id' => $rec->id]);

            return;
        }
        $incident = $this->incidents->raise($o->company_id, "exposure:{$o->id}:{$farm->id}", ['kind' => 'outbreak_exposure',
            'severity' => $band === 'inside' ? 'critical' : 'warning', 'title' => "{$o->disease} reported {$e->distance_km} km from {$farm->name}",
            'detail' => $e->statement, 'farm_id' => $farm->id]);
        $rec = Recommendation::query()->where('dedupe_key', "biosecurity:{$o->id}:{$farm->id}")->first()
            ?? Recommendation::create(['company_id' => $o->company_id, 'farm_id' => $farm->id, 'incident_id' => $incident->id, 'agent' => 'biosecurity',
                'title' => "Review biosecurity at {$farm->name}", 'dedupe_key' => "biosecurity:{$o->id}:{$farm->id}",
                'rationale' => $e->statement.' Check visitor and vehicle control, footbaths, wild-bird and rodent exclusion, and mortality disposal. Report sick animals to the state veterinarian.',
                'evidence' => ['outbreak' => $o->uuid, 'source' => $o->source?->name, 'trust' => $o->source?->trust, 'distance_km' => $e->distance_km, 'band' => $band, 'revision' => $o->revision],
                'action_kind' => 'task', 'action' => ['title' => "Biosecurity review — {$o->disease} nearby", 'priority' => $band === 'inside' ? 'urgent' : 'high', 'due_in_days' => $band === 'inside' ? 0 : 2],
                'priority' => $band === 'inside' ? 'urgent' : 'high', 'confidence' => $o->status === 'confirmed' ? 0.9 : 0.6]);
        $e->update(['incident_id' => $incident->id, 'recommendation_id' => $rec->id]);
    }
}
