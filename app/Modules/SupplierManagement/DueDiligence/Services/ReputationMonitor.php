<?php

namespace App\Modules\SupplierManagement\DueDiligence\Services;

use App\Modules\SupplierManagement\DueDiligence\Support\CheckType;
use App\Modules\SupplierManagement\DueDiligence\Support\VerificationStatus;
use App\Modules\SupplierManagement\Models\Supplier;
use App\Modules\SupplierManagement\Models\SupplierFinding;
use App\Modules\SupplierManagement\Models\SupplierHandle;
use App\Modules\SupplierManagement\Models\SupplierMonitoringRun;
use App\Modules\SupplierManagement\Models\SupplierPerson;
use App\Modules\SupplierManagement\Models\SupplierRiskFlag;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Watches what the world says about a supplier — news, the open web, and the social platforms the
 * tenant has licensed.
 *
 * Runs during onboarding as well as after approval, deliberately. The specification is explicit about
 * it and the reason is sound: the reputation of a company you are about to engage is exactly as
 * relevant as the reputation of one you already have, and a system that only starts watching after
 * approval has skipped the one moment when the finding could have changed the decision.
 *
 * The pipeline, and why it is in this order:
 *
 *   subjects → sources → mentions → ENTITY RESOLUTION → classification → dedupe → findings → alerts
 *
 * Entity resolution comes before classification because classifying a mention of a different company
 * wastes a model call, and because a mention that cannot be attributed must never reach the score at
 * all. Dedupe comes after classification so a re-seen article keeps its adjudication while still
 * picking up a better reading. Alerts come last, off findings that survived all of it.
 *
 * Every platform is asked separately and only if the tenant has enabled it — the terms differ per
 * platform, so a single switch would mean using the ones you may not to get the ones you may.
 */
class ReputationMonitor
{
    /** Severities that are worth waking somebody for. */
    private const ALERTING = ['high', 'critical'];

    public function __construct(
        private readonly ProviderRegistry $registry,
        private readonly EntityResolver $resolver,
        private readonly ReputationAnalyst $analyst,
        private readonly ReputationScore $reputation,
        private readonly VerificationEngine $engine,
    ) {}

    /**
     * Run a monitoring pass.
     *
     * @param  string  $scope  social | news | web | full
     * @param  string  $trigger  manual | onboarding | scheduled | event
     */
    public function run(Supplier $supplier, string $scope = 'full', string $trigger = 'manual'): SupplierMonitoringRun
    {
        $subjects = $this->subjects($supplier);
        $before = $this->reputation->score($supplier);

        $run = SupplierMonitoringRun::create([
            'supplier_id' => $supplier->id,
            'scope' => $scope,
            'trigger' => $trigger,
            'subjects' => $subjects->pluck('value')->all(),
            'reputation_before' => $before,
            'run_by' => Auth::id(),
            'started_at' => Carbon::now(),
        ]);

        $platforms = [];
        $queried = 0;
        $unavailable = 0;
        $seen = 0;
        $new = 0;
        $updated = 0;
        $belowFloor = 0;
        $alerts = 0;

        foreach ($this->sourcesFor($scope) as $checkType => $channel) {
            $provider = $this->registry->forCheck($checkType);

            if (! $provider) {
                // Recorded rather than skipped: a supplier nobody could search must not look like a
                // supplier nothing was found about.
                $this->engine->verify($supplier, $checkType);
                $unavailable++;

                continue;
            }

            $platforms[] = $provider->code();
            $queried++;

            $observation = $this->engine->verify(
                supplier: $supplier,
                checkType: $checkType,
                claimed: ['company_name' => $supplier->company_name],
                context: ['subjects' => $subjects->pluck('value')->all(), 'channel' => $channel],
            );

            foreach ($this->mentionsFrom($observation) as $mention) {
                $seen++;

                $outcome = $this->ingest($supplier, $mention, $channel, $provider->code(), $run);

                match ($outcome) {
                    'new' => $new++,
                    'updated' => $updated++,
                    'below_floor' => $belowFloor++,
                    default => null,
                };
            }
        }

        $after = $this->reputation->score($supplier->fresh());
        $alerts = $this->raiseAlerts($supplier);

        $run->forceFill([
            'platforms' => $platforms,
            'sources_queried' => $queried,
            'sources_unavailable' => $unavailable,
            'mentions_seen' => $seen,
            'findings_new' => $new,
            'findings_updated' => $updated,
            'below_confidence_floor' => $belowFloor,
            'alerts_raised' => $alerts,
            'reputation_after' => $after,
            'completed_at' => Carbon::now(),
        ])->save();

        return $run->fresh();
    }

    /**
     * Everything worth searching for.
     *
     * The registered name is rarely what a story uses. Trading names, brands, the website and the
     * per-platform handles are all searched, and so are the current directors and beneficial owners —
     * a director's conduct is the supplier's problem, and the specification asks for exactly this.
     *
     * @return \Illuminate\Support\Collection<int, array{kind:string, value:string, person_id:int|null}>
     */
    public function subjects(Supplier $supplier): \Illuminate\Support\Collection
    {
        $subjects = collect([
            ['kind' => 'company', 'value' => (string) $supplier->company_name, 'person_id' => null],
        ]);

        if (filled($supplier->trading_name)) {
            $subjects->push(['kind' => 'brand', 'value' => (string) $supplier->trading_name, 'person_id' => null]);
        }

        SupplierHandle::query()
            ->where('supplier_id', $supplier->id)
            ->active()
            ->get()
            ->each(fn (SupplierHandle $handle) => $subjects->push([
                'kind' => $handle->kind,
                'value' => (string) $handle->value,
                'person_id' => $handle->person_id,
            ]));

        SupplierPerson::query()
            ->where('supplier_id', $supplier->id)
            ->screenable()
            ->get()
            ->each(fn (SupplierPerson $person) => $subjects->push([
                'kind' => 'person',
                'value' => $person->name(),
                'person_id' => $person->id,
            ]));

        return $subjects
            ->filter(fn (array $subject) => trim($subject['value']) !== '' && $subject['value'] !== '—')
            ->unique('value')
            ->values();
    }

    /**
     * Take one mention through resolution, classification and storage.
     *
     * @return string new | updated | below_floor | skipped
     */
    private function ingest(Supplier $supplier, array $mention, string $channel, string $providerCode, SupplierMonitoringRun $run): string
    {
        $url = trim((string) ($mention['url'] ?? ''));

        if ($url === '' && trim((string) ($mention['title'] ?? '')) === '') {
            return 'skipped';
        }

        // Is it about our supplier? Asked before anything expensive happens, and before anything is
        // allowed to touch the score.
        $entity = $this->resolver->resolve($supplier, $mention);

        $hash = $url !== ''
            ? SupplierFinding::hashUrl($url)
            : hash('sha256', Str::lower(trim((string) $mention['title'])).'|'.($mention['source_name'] ?? ''));

        $existing = SupplierFinding::query()
            ->where('supplier_id', $supplier->id)
            ->where('url_hash', $hash)
            ->first();

        if ($existing) {
            // Seen again. The verdict a person reached is left exactly as it was — re-raising a
            // cleared finding every month is how monitoring becomes noise — but corroboration and the
            // last-seen date are worth updating.
            $existing->forceFill([
                'last_seen_at' => Carbon::now(),
                'corroborations' => $existing->corroborations + 1,
                'entity_confidence' => max($existing->entity_confidence, $entity['confidence']),
            ])->save();

            return 'updated';
        }

        $analysis = $entity['attributable']
            ? $this->analyst->classify($mention)
            // Not attributable: do not spend a model call deciding how bad a story about somebody
            // else is. It is stored for a person to look at, unclassified.
            : ['category' => null, 'severity' => null, 'sentiment' => null, 'is_positive' => false,
                'confidence' => null, 'rationale' => 'Not classified — the mention could not be tied to this supplier.', 'ai' => false];

        SupplierFinding::create([
            'supplier_id' => $supplier->id,
            'channel' => $channel,
            'platform' => $mention['platform'] ?? null,
            'source_name' => Str::limit((string) ($mention['source_name'] ?? ''), 145, '') ?: null,
            'url' => $url ?: null,
            'url_hash' => $hash,
            'external_id' => $mention['external_id'] ?? null,
            'published_at' => $this->parseDate($mention['published_at'] ?? null),
            'author' => Str::limit((string) ($mention['author'] ?? ''), 185, '') ?: null,
            'title' => Str::limit((string) ($mention['title'] ?? ''), 495, '') ?: null,
            'excerpt' => $mention['excerpt'] ?? null,
            'language' => $mention['language'] ?? null,
            'category' => $analysis['category'],
            'severity' => $analysis['severity'],
            'sentiment' => $analysis['sentiment'],
            'is_positive' => (bool) $analysis['is_positive'],
            'analysis_confidence' => $analysis['confidence'],
            'ai_generated' => (bool) $analysis['ai'],
            'analysis_note' => $analysis['rationale'],
            'entity_confidence' => $entity['confidence'],
            'entity_basis' => $entity['basis'],
            'matched_subject' => $mention['matched_subject'] ?? null,
            'status' => $entity['attributable'] ? SupplierFinding::STATUS_OPEN : SupplierFinding::STATUS_NEEDS_REVIEW,
            'monitoring_run_id' => $run->id,
            'provider_code' => $providerCode,
        ]);

        return $entity['attributable'] ? 'new' : 'below_floor';
    }

    /**
     * Raise a risk flag for serious findings nobody has looked at yet.
     *
     * One flag rather than one per finding: a queue of forty flags is a queue nobody works. The flag
     * says how many and how bad, and points at the panel where they can be read.
     */
    private function raiseAlerts(Supplier $supplier): int
    {
        $serious = SupplierFinding::query()
            ->where('supplier_id', $supplier->id)
            ->unadjudicated()
            ->negative()
            ->whereIn('severity', self::ALERTING)
            ->get();

        if ($serious->isEmpty()) {
            // Nothing outstanding — clear any flag a previous run raised, so a supplier that has been
            // worked through stops looking like one that has not.
            SupplierRiskFlag::query()
                ->where('supplier_id', $supplier->id)
                ->where('type', 'reputation_findings')
                ->open()
                ->update(['status' => 'resolved', 'resolved_at' => Carbon::now()]);

            return 0;
        }

        $worst = $serious->contains(fn (SupplierFinding $f) => $f->severity === 'critical') ? 'critical' : 'high';

        SupplierRiskFlag::query()->updateOrCreate(
            ['supplier_id' => $supplier->id, 'type' => 'reputation_findings', 'status' => 'open'],
            [
                'severity' => $worst,
                'message' => sprintf(
                    '%d reputation finding(s) awaiting review, worst severity %s: %s',
                    $serious->count(),
                    $worst,
                    Str::limit($serious->sortByDesc('severity')->first()?->title ?? '', 200, '')
                ),
                'context' => [
                    'finding_ids' => $serious->pluck('id')->all(),
                    'categories' => $serious->pluck('category')->unique()->values()->all(),
                ],
            ]
        );

        return $serious->count();
    }

    /** Which checks a scope covers, and what channel each one's results are. */
    private function sourcesFor(string $scope): array
    {
        $news = [CheckType::MEDIA_ADVERSE => 'news'];
        $social = [CheckType::SOCIAL_REPUTATION => 'social'];

        return match ($scope) {
            'social' => $social,
            'news' => $news,
            default => $news + $social,
        };
    }

    /**
     * The mentions a provider returned.
     *
     * Providers hand back a payload; turning it into findings happens here, so every finding — from
     * any platform, or recorded by a person — goes through the same resolution and classification.
     */
    private function mentionsFrom($observation): array
    {
        if (in_array($observation->status, [VerificationStatus::UNAVAILABLE, VerificationStatus::ERROR], true)) {
            return [];
        }

        $observed = $observation->observed ?? [];

        return array_values(array_filter(
            (array) ($observed['mentions'] ?? $observed['results'] ?? $observed['articles'] ?? []),
            fn ($mention) => is_array($mention) && (! empty($mention['url']) || ! empty($mention['title']))
        ));
    }

    private function parseDate(?string $value): ?Carbon
    {
        if (! $value) {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
