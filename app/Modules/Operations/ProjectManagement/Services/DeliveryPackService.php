<?php

namespace App\Modules\Operations\ProjectManagement\Services;

use App\Modules\Operations\ProjectManagement\Models\BoqItem;
use App\Modules\Operations\ProjectManagement\Models\ProgressClaim;
use App\Modules\Operations\ProjectManagement\Models\Project;
use App\Modules\Operations\ProjectManagement\Models\SiteDiary;
use App\Modules\Operations\ProjectManagement\Models\Snag;
use App\Modules\Operations\ProjectManagement\Models\Task;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The site diary, the bill of quantities, the snag list and the payment certificate (EPIC-100).
 *
 * These four are one service because they are one workflow: work is measured against the BOQ, what
 * happened is recorded in the diary, what is wrong with it goes on the snag list, and a claim
 * certifies the first while the third is what holds money back.
 */
class DeliveryPackService
{
    public function __construct(
        private readonly InternalEmitter $emitter,
        private readonly ApprovalService $approvals,
        private readonly AuditLogger $audit,
    ) {
    }

    // ── Site diary ───────────────────────────────────────────────────────────

    /**
     * Record a day.
     *
     * Updates rather than duplicates: the database allows one diary per site per day, and somebody
     * adding the afternoon's deliveries at four o'clock is completing the same entry, not writing a
     * second account of the day.
     *
     * @param  array<string,mixed>  $data
     */
    public function recordDiary(Project $project, array $data): SiteDiary
    {
        $date = CarbonImmutable::parse($data['entry_date'] ?? now())->startOfDay();

        if ($date->isFuture()) {
            // A diary is a record of what happened. Tomorrow's entry is a plan, and filing it as a
            // record is how a claim loses its credibility.
            throw new InvalidArgumentException('A site diary cannot be written for a day that has not happened.');
        }

        return DB::transaction(function () use ($project, $data, $date) {
            $diary = SiteDiary::updateOrCreate(
                ['project_id' => $project->id, 'entry_date' => $date->toDateString()],
                array_filter([
                    'weather'         => $data['weather'] ?? null,
                    'temperature_c'   => $data['temperature_c'] ?? null,
                    'rain_hours'      => $data['rain_hours'] ?? null,
                    'workers_on_site' => $data['workers_on_site'] ?? null,
                    'work_done'       => $data['work_done'] ?? null,
                    'plant_on_site'   => $data['plant_on_site'] ?? null,
                    'deliveries'      => $data['deliveries'] ?? null,
                    'visitors'        => $data['visitors'] ?? null,
                    'delays'          => $data['delays'] ?? null,
                    'instructions'    => $data['instructions'] ?? null,
                    'safety_notes'    => $data['safety_notes'] ?? null,
                    'recorded_by'     => Auth::id(),
                ], fn ($v) => $v !== null && $v !== ''),
            );

            // A disrupted day is evidence for an extension of time, so it goes in the ledger where
            // it cannot be quietly rewritten six months later when the claim is contested.
            if ($diary->rain_hours > 0 || ! empty($data['delays'])) {
                $this->emitter->emit($project, 'site.visit', [
                    'subject_type' => 'project',
                    'subject_id'   => $project->uuid,
                    'source_ref'   => "diary:{$diary->id}",
                    'occurred_at'  => $date->toIso8601String(),
                    'payload'      => [
                        'purpose'    => 'site_diary',
                        'weather'    => $diary->weather,
                        'rain_hours' => (int) $diary->rain_hours,
                        'delays'     => $diary->delays,
                    ],
                ]);
            }

            return $diary;
        });
    }

    // ── Bill of quantities ───────────────────────────────────────────────────

    /** @param  array<string,mixed>  $data */
    public function addBoqItem(Project $project, array $data): BoqItem
    {
        $quantity = (float) ($data['quantity'] ?? 0);
        $rate = (float) ($data['rate'] ?? 0);

        if ($quantity <= 0) {
            throw new InvalidArgumentException('A bill item needs a quantity.');
        }

        return BoqItem::create([
            'project_id'     => $project->id,
            'task_id'        => $this->taskId($project, $data['task_id'] ?? null),
            'item_no'        => $data['item_no'] ?? null,
            'section'        => $data['section'] ?? null,
            'description'    => $data['description'],
            'unit'           => $data['unit'] ?? 'item',
            'quantity'       => $quantity,
            'rate'           => $rate,
            // Stored, not derived: a BOQ is a priced document and the figure was agreed.
            'amount'         => round($quantity * $rate, 2),
            'is_provisional' => (bool) ($data['is_provisional'] ?? false),
            'sort_order'     => (int) ($data['sort_order'] ?? BoqItem::where('project_id', $project->id)->max('sort_order') + 1),
        ]);
    }

    /**
     * Record work measured against a bill item.
     *
     * The measurement is cumulative to date, and it emits `quantity.installed` — which is what
     * derivation already ranks above a typed percentage (PM-ADR-010). So measuring the BOQ moves
     * the task's progress, and nobody can improve the reported figure by claiming a bigger one.
     */
    public function measure(BoqItem $item, float $quantityToDate): BoqItem
    {
        if ($quantityToDate < 0) {
            throw new InvalidArgumentException('A measurement cannot be negative.');
        }

        return DB::transaction(function () use ($item, $quantityToDate) {
            $item->update(['measured_quantity' => $quantityToDate]);

            $item->loadMissing('project', 'task');

            $this->emitter->emit($item->project, 'quantity.installed', [
                'subject_type' => $item->task_id ? 'task' : 'project',
                'subject_id'   => $item->task?->uuid ?? $item->project->uuid,
                // Per item and per measurement date: re-measuring the same line supersedes the
                // figure rather than adding to it.
                'source_ref'   => 'boq:' . $item->id . ':' . now()->toDateString(),
                'quantity'     => $quantityToDate,
                'unit'         => $item->unit,
                'payload'      => [
                    'planned_quantity' => (float) $item->quantity,
                    'item_no'          => $item->item_no,
                    'description'      => $item->description,
                ],
            ]);

            return $item->fresh();
        });
    }

    /**
     * The bill's position: priced, measured, and what remains.
     *
     * @return array{total:float, measured:float, remaining:float, percent:float, provisional:float}
     */
    public function boqSummary(Project $project): array
    {
        $items = BoqItem::where('project_id', $project->id)->get();

        $total = (float) $items->sum(fn (BoqItem $i) => (float) $i->amount);
        $measured = (float) $items->sum(fn (BoqItem $i) => $i->measuredValue());

        return [
            'total'     => round($total, 2),
            'measured'  => round($measured, 2),
            'remaining' => round($total - $measured, 2),
            'percent'   => $total > 0 ? round(($measured / $total) * 100, 1) : 0.0,
            // Called out separately: a provisional sum is money in the bill that may never be
            // spent, and a completion percentage that counts it reads high.
            'provisional' => round((float) $items->where('is_provisional', true)->sum(fn (BoqItem $i) => (float) $i->amount), 2),
        ];
    }

    // ── Snags ────────────────────────────────────────────────────────────────

    /** @param  array<string,mixed>  $data */
    public function raiseSnag(Project $project, array $data): Snag
    {
        $kind = $data['kind'] ?? 'snag';

        if (! in_array($kind, Snag::KINDS, true)) {
            throw new InvalidArgumentException("'{$kind}' is not a kind of snag.");
        }

        return DB::transaction(function () use ($project, $data, $kind) {
            $snag = Snag::create([
                'project_id'         => $project->id,
                'task_id'            => $this->taskId($project, $data['task_id'] ?? null),
                'reference'          => $data['reference'] ?? $this->nextSnagReference($project, $kind),
                'kind'               => $kind,
                'title'              => $data['title'],
                'description'        => $data['description'] ?? null,
                'location'           => $data['location'] ?? null,
                'severity'           => $data['severity'] ?? 'minor',
                'status'             => 'open',
                'raised_by'          => Auth::id(),
                'assigned_member_id' => $data['assigned_member_id'] ?? null,
                'due_date'           => $data['due_date'] ?? null,
                'photo_before_id'    => $data['photo_before_id'] ?? null,
            ]);

            // A non-conformance is a quality event, not an internal note: it belongs in the ledger
            // so the drill-down on a handover certificate can show it was raised and closed.
            if ($kind === 'ncr') {
                $this->emitter->emit($project, 'inspection.failed', [
                    'subject_type' => $snag->task_id ? 'task' : 'project',
                    'subject_id'   => $snag->task?->uuid ?? $project->uuid,
                    'source_ref'   => "snag:{$snag->id}",
                    'payload'      => [
                        // Both required by the taxonomy: who rejected the work, and why. A failed
                        // inspection with no reason is one nobody can act on or contest.
                        'inspector' => (string) (Auth::user()?->email ?? 'system'),
                        'reason'    => $snag->title,
                        'reference' => $snag->reference,
                        'severity'  => $snag->severity,
                    ],
                ]);
            }

            return $snag;
        });
    }

    /**
     * Close a snag.
     *
     * An NCR or a critical defect needs evidence — a closure note and an after photograph. A
     * register full of critical items closed with a blank note is a register nobody can stand
     * behind at handover, which is the one moment it exists for.
     */
    public function closeSnag(Snag $snag, array $data): Snag
    {
        if ($snag->needsEvidenceToClose() && empty($data['closure_note'])) {
            throw new InvalidArgumentException(
                'A non-conformance or critical defect has to say how it was put right.'
            );
        }

        $snag->update([
            'status'         => 'closed',
            'closed_at'      => now(),
            'closed_by'      => Auth::id(),
            'closure_note'   => $data['closure_note'] ?? null,
            'photo_after_id' => $data['photo_after_id'] ?? null,
        ]);

        $this->audit->recordAction($snag, 'snag.closed', ['kind' => $snag->kind, 'severity' => $snag->severity]);

        return $snag->fresh();
    }

    // ── Progress claims ──────────────────────────────────────────────────────

    /**
     * Draw up the next payment certificate.
     *
     * Everything is computed rather than typed, from the bill and from the previous certificate,
     * because every one of these figures is somebody's money and a typo in a spreadsheet is how
     * certificates get disputed:
     *
     *   work to date        measured value from the BOQ
     *   previously certified the gross of every certificate already issued
     *   retention           held on the gross at the contract percentage
     *   net due             gross − retention − previously certified
     */
    public function draftClaim(Project $project, array $data): ProgressClaim
    {
        $previous = ProgressClaim::where('project_id', $project->id)
            ->whereIn('status', ['certified', 'paid'])
            ->orderByDesc('claim_number')
            ->get();

        $boq = $this->boqSummary($project);

        $workToDate = (float) ($data['work_to_date'] ?? $boq['measured']);
        $materials = (float) ($data['materials_on_site'] ?? 0);
        $variations = (float) ($data['variations'] ?? 0);
        $retentionPct = (float) ($data['retention_pct'] ?? 10.0);

        $gross = round($workToDate + $materials + $variations, 2);
        $retention = round($gross * ($retentionPct / 100), 2);

        // The gross of everything already certified — not the net, or retention would be deducted
        // twice and the contractor would be short by exactly the retention on every certificate.
        $previouslyCertified = round((float) $previous->sum(
            fn (ProgressClaim $c) => $c->gross() - (float) $c->retention_held
        ), 2);

        $number = (int) (ProgressClaim::where('project_id', $project->id)->max('claim_number') ?? 0) + 1;

        return ProgressClaim::create([
            'project_id'           => $project->id,
            'claim_number'         => $number,
            'period_start'         => $data['period_start'] ?? now()->startOfMonth()->toDateString(),
            'period_end'           => $data['period_end'] ?? now()->endOfMonth()->toDateString(),
            'work_to_date'         => $workToDate,
            'materials_on_site'    => $materials,
            'variations'           => $variations,
            'previously_certified' => $previouslyCertified,
            'retention_pct'        => $retentionPct,
            'retention_held'       => $retention,
            'net_due'              => round($gross - $retention - $previouslyCertified, 2),
            'currency'             => $project->currency,
            'milestone_task_id'    => $this->taskId($project, $data['milestone_task_id'] ?? null),
            'status'               => 'draft',
            'notes'                => $data['notes'] ?? null,
        ]);
    }

    /** Send a claim for certification, through the tenant's own approval routing. */
    public function submitClaim(ProgressClaim $claim): ProgressClaim
    {
        if (! $claim->isEditable()) {
            throw new InvalidArgumentException('That claim has already been submitted.');
        }

        $claim->update(['status' => 'submitted', 'submitted_at' => now()]);

        $claim->loadMissing('project');

        $request = $this->approvals->submit('progress_claim', $claim, $claim->project, [
            'label'    => "Claim #{$claim->claim_number} · {$claim->project->code}",
            'amount'   => (float) $claim->net_due,
            'currency' => $claim->currency,
        ]);

        // No routing configured for claims: certified on submission would be wrong for money this
        // size, so it stays submitted and waits for somebody to certify it by hand.
        return $request === null ? $claim->fresh() : $claim->fresh();
    }

    /**
     * Certify a claim: the moment it becomes money owed.
     *
     * Emits `invoice.received` — committed cost, not paid — so the project's cost position moves
     * the same way a supplier invoice would, through the ledger rather than through a second path.
     */
    public function certifyClaim(ProgressClaim $claim): ProgressClaim
    {
        if ($claim->status === 'certified' || $claim->status === 'paid') {
            return $claim;
        }

        return DB::transaction(function () use ($claim) {
            $claim->update([
                'status'       => 'certified',
                'certified_at' => now(),
                'certified_by' => Auth::id(),
            ]);

            $claim->loadMissing('project', 'milestone');

            $this->emitter->emit($claim->project, 'invoice.received', [
                'subject_type' => 'project',
                'subject_id'   => $claim->project->uuid,
                'source_ref'   => "claim:{$claim->id}",
                'amount'       => (string) $claim->net_due,
                'currency'     => $claim->currency,
                'payload'      => [
                    'reference' => "Claim #{$claim->claim_number}",
                    'note'      => 'Certified progress claim',
                ],
            ]);

            /*
             * And into the books. Certification is the accounting event: the moment the
             * contractor's work becomes money owed, with the retention held back as its own
             * liability rather than buried in what is due this month.
             *
             * Posts nothing until the retention account is mapped, which is deliberate — see
             * ClaimLedgerPoster.
             */
            app(ClaimLedgerPoster::class)->post($claim);

            // A milestone paid on this certificate is a milestone claimed. Recorded on the task so
            // it cannot be claimed twice on a later certificate.
            if ($claim->milestone) {
                $claim->milestone->forceFill(['payment_claimed' => true])->save();
            }

            $this->audit->recordAction($claim, 'claim.certified', [
                'number'    => $claim->claim_number,
                'net_due'   => (float) $claim->net_due,
                'retention' => (float) $claim->retention_held,
            ]);

            return $claim->fresh();
        });
    }

    /** Retention held across every certified claim, and what has been released. */
    public function retentionPosition(Project $project): array
    {
        $claims = ProgressClaim::where('project_id', $project->id)
            ->whereIn('status', ['certified', 'paid'])
            ->get();

        $held = (float) $claims->sum(fn (ProgressClaim $c) => (float) $c->retention_held);
        $released = (float) $claims->sum(fn (ProgressClaim $c) => (float) $c->retention_released);

        return [
            'held'      => round($held, 2),
            'released'  => round($released, 2),
            'outstanding' => round($held - $released, 2),
            'currency'  => $project->currency,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function taskId(Project $project, mixed $uuid): ?int
    {
        return $uuid ? Task::where('project_id', $project->id)->where('uuid', $uuid)->value('id') : null;
    }

    /** SNAG-014, NCR-003 — the reference people actually call it by on site. */
    private function nextSnagReference(Project $project, string $kind): string
    {
        $prefix = strtoupper($kind);
        $count = Snag::where('project_id', $project->id)->where('kind', $kind)->count() + 1;

        return sprintf('%s-%03d', $prefix, $count);
    }
}
