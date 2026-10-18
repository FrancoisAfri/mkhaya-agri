<?php

namespace App\Modules\Operations\Sheq\Console;

use App\Modules\Operations\Sheq\Events\EventPublisher;
use App\Modules\Operations\Sheq\Regulatory\ClassificationResult;
use App\Modules\Operations\Sheq\Regulatory\Classifier;
use App\Modules\Operations\Sheq\Regulatory\ObligationScheduler;
use App\Modules\Operations\Sheq\Regulatory\PackManifest;
use App\Modules\Operations\Sheq\Support\Timestamps;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Re-assess open incidents against the currently active pack (`sheq.incident.classified`).
 *
 * Classification is done once, at report time, against whichever pack version was active then —
 * and that is correct: a record must say what the law was when it was assessed, not what it became.
 * But a pack update is exactly the moment when an incident that was internal-only becomes
 * reportable, or a deadline moves. If nothing re-runs the rules, a regulation change silently fails
 * to reach the incidents already on the books, and the first anyone hears of it is an inspector
 * asking why a section 24 notification was never sent.
 *
 * So this is deliberately a command and not automatic. Reclassification changes statutory duties
 * and can create obligations with deadlines already running; that is a decision a compliance
 * manager makes when they activate a new pack, having read what changed. `--dry-run` exists so they
 * can see the consequences before accepting them.
 *
 * Only open incidents are touched. A closed record's classification is history — re-assessing it
 * would rewrite what the employer believed, and reported, at the time.
 */
class ReclassifyIncidentsCommand extends Command
{
    protected $signature = 'sheq:incidents:reclassify
                            {--company= : Restrict to one company id}
                            {--dry-run : Report what would change without changing it}';

    protected $description = 'Re-assess open incidents against the active regulatory pack';

    public function handle(TenantContext $tenants, Classifier $classifier,
        EventPublisher $publisher, ObligationScheduler $scheduler): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $only = $this->option('company') ? (int) $this->option('company') : null;
        $changed = 0;
        $examined = 0;

        $run = function (int $companyId) use ($classifier, $publisher, $scheduler, $dryRun, &$changed, &$examined): void {
            $pack = $this->activePack($companyId);

            if ($pack === null) {
                return;
            }

            $incidents = DB::table('sheq_incidents')
                ->where('company_id', $companyId)->whereNull('deleted_at')
                ->whereNotIn('status', ['CLOSED', 'REJECTED'])
                ->get();

            foreach ($incidents as $incident) {
                $examined++;

                // Already assessed against this version — nothing to say.
                if ($incident->regulatory_pack_id === $pack->packId
                    && $incident->regulatory_pack_version === $pack->version) {
                    continue;
                }

                $result = $classifier->classify($pack, $this->factsFrom($incident));

                $wasCategory = $incident->statutory_category;
                $nowCategory = $result->statutoryCategory;
                $wasReportable = (bool) $incident->is_reportable;
                $nowReportable = $result->reportable;

                if ($wasCategory === $nowCategory && $wasReportable === $nowReportable) {
                    // The pack moved but this incident's duties did not. Stamp the version so the
                    // record shows it was re-checked, and stay silent — announcing a non-change
                    // would bury the ones that matter.
                    if (! $dryRun) {
                        DB::table('sheq_incidents')->where('id', $incident->id)->update([
                            'regulatory_pack_id' => $pack->packId,
                            'regulatory_pack_version' => $pack->version,
                            'updated_at' => now(),
                        ]);
                    }

                    continue;
                }

                $changed++;

                $this->components->twoColumnDetail(
                    "  {$incident->reference}",
                    sprintf('%s → %s%s', $wasCategory ?: 'unclassified', $nowCategory ?: 'unclassified',
                        $nowReportable && ! $wasReportable ? ' <fg=red>(now reportable)</>' : ''),
                );

                if ($dryRun) {
                    continue;
                }

                DB::transaction(function () use ($incident, $result, $pack, $publisher, $scheduler,
                    $companyId, $wasCategory, $nowReportable) {
                    DB::table('sheq_incidents')->where('id', $incident->id)->update([
                        'statutory_category' => $result->statutoryCategory,
                        'is_reportable' => $result->reportable,
                        'required_forms' => json_encode($result->forms),
                        'classification_rule_id' => $result->ruleId,
                        'regulatory_pack_id' => $pack->packId,
                        'regulatory_pack_version' => $pack->version,
                        'updated_at' => now(),
                    ]);

                    // Newly reportable means new statutory deadlines, and they run from the
                    // incident, not from today — a duty that was always owed does not restart
                    // because we noticed it late.
                    if ($nowReportable) {
                        $this->scheduleObligations($companyId, $incident, $result, $pack, $scheduler);
                    }

                    $publisher->publish($companyId, 'sheq.incident.classified',
                        'INCIDENT', (int) $incident->id, [
                            'incident_id' => (int) $incident->id,
                            'statutory_category' => $result->statutoryCategory,
                            'obligations' => $result->obligations,
                            'previous_category' => $wasCategory,
                            'is_reportable' => $result->reportable,
                            'pack' => $pack->packId.'@'.$pack->version,
                        ]);
                });
            }
        };

        $only !== null
            ? $tenants->run($only, fn () => $run($only))
            : $tenants->eachCompany(fn ($id) => $id === null ? null : $run((int) $id));

        $this->newLine();
        $this->components->info(sprintf('%d incident(s) examined, %d reclassified%s.',
            $examined, $changed, $dryRun ? ' (dry run — nothing was written)' : ''));

        return self::SUCCESS;
    }

    /**
     * Create the statutory obligations a newly reportable incident now carries.
     *
     * Anchored on when the incident happened, never on when we noticed. A duty that was always
     * owed does not get a fresh deadline because the pack update arrived late — and a deadline
     * that is already past should show as breached immediately, which is the honest position.
     *
     * Existing obligations for the incident are left alone: one may already be discharged, and
     * re-creating it would reopen a duty somebody has evidence of having met.
     */
    private function scheduleObligations(int $companyId, object $incident,
        ClassificationResult $result,
        PackManifest $pack, ObligationScheduler $scheduler): void
    {
        $existing = DB::table('sheq_statutory_obligations')
            ->where('company_id', $companyId)
            ->where('subject_type', 'INCIDENT')->where('subject_id', $incident->id)
            ->pluck('obligation_def_id')->all();

        $drafts = $scheduler->schedule($pack, $result->obligations,
            Carbon::parse($incident->occurred_at));

        foreach ($drafts as $draft) {
            if (in_array($draft->definitionId, $existing, true)) {
                continue;
            }

            DB::table('sheq_statutory_obligations')->insert([
                'company_id' => $companyId,
                'uuid' => (string) Str::uuid(),
                'obligation_def_id' => $draft->definitionId,
                'label' => $draft->label,
                'subject_type' => 'INCIDENT',
                'subject_id' => $incident->id,
                'regulatory_pack_id' => $result->packId,
                'regulatory_pack_version' => $result->packVersion,
                'anchor_at' => Timestamps::utc($draft->anchorAt),
                'due_at' => Timestamps::utc($draft->dueAt),
                'reminders' => json_encode(array_map(fn ($r) => $r->toIso8601String(), $draft->reminderAt)),
                'escalations' => json_encode(array_map(fn ($e) => [
                    'after' => $e['after']->toIso8601String(),
                    'notify_role' => $e['notify_role'], 'severity' => $e['severity'],
                ], $draft->escalations)),
                'status' => 'PENDING',
                'responsible_role' => $draft->responsibleRole,
                'recipient' => $draft->recipient,
                'evidence_required' => $draft->evidenceRequired,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    private function activePack(int $companyId): ?PackManifest
    {
        $row = DB::table('sheq_regulatory_pack_activations as a')
            ->join('sheq_regulatory_pack_versions as v', 'v.id', '=', 'a.pack_version_id')
            ->join('sheq_regulatory_packs as p', 'p.id', '=', 'v.pack_id')
            ->where('a.company_id', $companyId)->where('a.status', 'ACTIVE')
            ->whereNull('a.deactivated_at')
            ->orderByDesc('a.activated_at')
            ->first(['p.pack_id', 'v.version', 'v.manifest']);

        return $row === null ? null
            : new PackManifest($row->pack_id, $row->version, json_decode($row->manifest, true) ?: []);
    }

    /** The facts the rules are evaluated against. */
    private function factsFrom(object $incident): array
    {
        return [
            'incident_type' => $incident->incident_type,
            'outcome' => $incident->outcome ?? null,
            'treatment_level' => $incident->treatment_level ?? null,
            'actual_severity' => (int) ($incident->actual_severity ?? 0),
            'potential_severity' => (int) ($incident->potential_severity ?? 0),
            'likely_incapacity_days' => (int) ($incident->likely_incapacity_days ?? 0),
            'days_lost' => (int) ($incident->days_lost ?? 0),
            'dangerous_substance_release' => (bool) ($incident->dangerous_substance_release ?? false),
            'machinery_failure_causing_danger' => (bool) ($incident->machinery_failure_causing_danger ?? false),
        ];
    }
}
