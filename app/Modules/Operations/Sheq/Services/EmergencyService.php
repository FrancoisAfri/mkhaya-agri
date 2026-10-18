<?php

namespace App\Modules\Operations\Sheq\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Emergency preparedness (§15 of the SHEQ scope).
 *
 * Plans, assembly points, the command structure, and drills.
 *
 * The drill record is the part with teeth. A plan that has never been rehearsed is a document, and
 * section 8 of the OHS Act requires the employer to take *reasonably practicable* steps — which a
 * court reads as including whether the arrangements were tested. So a drill records two numbers,
 * and the second is the one that matters: how long the evacuation took, **and how many people were
 * unaccounted for**. A four-minute evacuation with two people missing is a failed drill. Recording
 * only the time would let it read as a pass, which is precisely how organisations convince
 * themselves they are prepared.
 */
class EmergencyService
{
    public const TYPES = ['FIRE', 'MEDICAL', 'CHEMICAL_SPILL', 'BOMB_THREAT', 'STRUCTURAL',
        'FLOOD', 'POWER', 'SECURITY'];

    public function __construct(private readonly ActionService $actions) {}

    /** Publish a plan for a site. */
    public function createPlan(int $companyId, array $data, ?int $actorId = null): array
    {
        $type = strtoupper($data['emergency_type'] ?? '');

        if (! in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException("'{$type}' is not a known emergency type.");
        }

        if (empty($data['assembly_points'])) {
            throw new InvalidArgumentException(
                'A plan needs at least one assembly point. Without one there is nowhere to account '
                .'for people, and the evacuation has no end state.'
            );
        }

        $uuid = (string) Str::uuid();

        $id = DB::table('sheq_emergency_plans')->insertGetId([
            'uuid' => $uuid,
            'company_id' => $companyId,
            'site_id' => $data['site_id'] ?? null,
            'name' => $data['name'] ?? $type.' emergency plan',
            'emergency_type' => $type,
            'procedure' => $data['procedure'] ?? null,
            'assembly_points' => json_encode($data['assembly_points']),
            'emergency_contacts' => json_encode($data['emergency_contacts'] ?? []),
            'incident_command' => json_encode($data['incident_command'] ?? []),
            'document_id' => $data['document_id'] ?? null,
            'review_due' => $data['review_due'] ?? Carbon::today()->addYear()->toDateString(),
            'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return ['id' => $id, 'uuid' => $uuid, 'emergency_type' => $type];
    }

    /**
     * Record a drill.
     *
     * Where people were unaccounted for, a corrective action is raised automatically. That is not
     * bureaucratic tidiness: an unaccounted person in a drill is the single strongest predictor
     * that a real evacuation will go wrong, and leaving it as a note in a form is how it gets
     * forgotten before the next one.
     */
    public function recordDrill(int $companyId, array $data, ?int $actorId = null): array
    {
        $participants = (int) ($data['participants'] ?? 0);
        $unaccounted = (int) ($data['unaccounted'] ?? 0);

        if ($unaccounted > $participants) {
            throw new InvalidArgumentException(
                'More people were unaccounted for than took part, which cannot be right. Check the '
                .'roll call figures.'
            );
        }

        return DB::transaction(function () use ($companyId, $data, $participants, $unaccounted, $actorId) {
            $uuid = (string) Str::uuid();
            $actionId = null;

            if ($unaccounted > 0) {
                $action = $this->actions->create($companyId, [
                    'title' => sprintf('Evacuation drill: %d person(s) unaccounted for', $unaccounted),
                    'description' => sprintf(
                        "A drill on %s finished with %d of %d participants unaccounted for.\n\n"
                        .'Establish why the roll call failed before the next drill. An unaccounted '
                        .'person in a rehearsal is the clearest available warning that a real '
                        ."evacuation will not account for everybody.\n\nObservations: %s",
                        $data['drill_date'] ?? now()->toDateString(), $unaccounted, $participants,
                        $data['observations'] ?? 'none recorded',
                    ),
                    'source_type' => 'EMERGENCY_DRILL',
                    'source_id' => 0,
                    'priority' => 'HIGH',
                    'site_id' => $data['site_id'] ?? null,
                    'due_date' => Carbon::today()->addDays(30)->toDateString(),
                ], $actorId);

                $actionId = $action['id'] ?? null;
            }

            $id = DB::table('sheq_emergency_drills')->insertGetId([
                'uuid' => $uuid,
                'company_id' => $companyId,
                'plan_id' => $data['plan_id'] ?? null,
                'site_id' => $data['site_id'] ?? null,
                'drill_date' => $data['drill_date'] ?? now()->toDateString(),
                'drill_type' => strtoupper($data['drill_type'] ?? 'FIRE'),
                'evacuation_seconds' => $data['evacuation_seconds'] ?? null,
                'participants' => $participants,
                'unaccounted' => $unaccounted,
                'observations' => $data['observations'] ?? null,
                'improvements' => $data['improvements'] ?? null,
                'action_id' => $actionId,
                'conducted_by' => $actorId,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            if ($actionId !== null) {
                DB::table('sheq_actions')->where('id', $actionId)->update(['source_id' => $id]);
            }

            return ['id' => $id, 'uuid' => $uuid, 'action_raised' => $actionId !== null];
        });
    }

    /**
     * Sites whose plans have not been rehearsed within the period.
     *
     * Twelve months is the conventional interval and the one an inspector will ask about.
     */
    public function sitesOverdueForDrill(int $companyId, int $months = 12): array
    {
        $cutoff = Carbon::today()->subMonths($months)->toDateString();

        return DB::table('sheq_emergency_plans as p')
            ->leftJoin('sheq_sites as s', 's.id', '=', 'p.site_id')
            ->leftJoin(DB::raw('(select plan_id, max(drill_date) as last_drill
                from sheq_emergency_drills group by plan_id) d'), 'd.plan_id', '=', 'p.id')
            ->where('p.company_id', $companyId)->where('p.is_active', true)
            ->whereNull('p.deleted_at')
            ->where(fn ($q) => $q->whereNull('d.last_drill')->orWhere('d.last_drill', '<', $cutoff))
            ->get(['p.uuid', 'p.name', 'p.emergency_type', 's.name as site_name', 'd.last_drill'])
            ->map(fn ($p) => [
                'uuid' => $p->uuid,
                'plan' => $p->name,
                'emergency_type' => $p->emergency_type,
                'site' => $p->site_name ?? 'All sites',
                'last_drill' => $p->last_drill,
                'never_drilled' => $p->last_drill === null,
            ])->values()->all();
    }

    /** The plan somebody needs during an emergency, read in one query. */
    public function planFor(int $companyId, string $type, ?int $siteId = null): ?array
    {
        $plan = DB::table('sheq_emergency_plans')
            ->where('company_id', $companyId)->where('emergency_type', strtoupper($type))
            ->where('is_active', true)->whereNull('deleted_at')
            // A site-specific plan wins; the company-wide one is the fallback.
            ->orderByRaw('site_id is null')
            ->when($siteId !== null, fn ($q) => $q->where(fn ($w) => $w->whereNull('site_id')
                ->orWhere('site_id', $siteId)))
            ->first();

        if ($plan === null) {
            return null;
        }

        return [
            'uuid' => $plan->uuid,
            'name' => $plan->name,
            'emergency_type' => $plan->emergency_type,
            'procedure' => $plan->procedure,
            'assembly_points' => json_decode((string) $plan->assembly_points, true) ?: [],
            'emergency_contacts' => json_decode((string) $plan->emergency_contacts, true) ?: [],
            'incident_command' => json_decode((string) $plan->incident_command, true) ?: [],
            'review_due' => $plan->review_due,
        ];
    }

    /** Drill performance over time — the trend a management review looks at. */
    public function drillHistory(int $companyId, ?int $siteId = null, int $limit = 24): array
    {
        return DB::table('sheq_emergency_drills')
            ->where('company_id', $companyId)->whereNull('deleted_at')
            ->when($siteId !== null, fn ($q) => $q->where('site_id', $siteId))
            ->orderByDesc('drill_date')->limit($limit)
            ->get(['uuid', 'drill_date', 'drill_type', 'evacuation_seconds', 'participants',
                'unaccounted', 'observations'])
            ->map(fn ($d) => (array) $d + [
                // A drill is only a pass if everybody was accounted for, whatever the clock said.
                'passed' => (int) $d->unaccounted === 0,
            ])->values()->all();
    }
}
