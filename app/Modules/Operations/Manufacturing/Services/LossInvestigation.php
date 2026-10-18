<?php

namespace App\Modules\Operations\Manufacturing\Services;

use App\Core\User\Models\User;
use App\Modules\Operations\Manufacturing\Models\LossEvent;
use App\Modules\Operations\Manufacturing\Models\LossFinding as Finding;
use App\Modules\Operations\Manufacturing\Support\RoleCatalog;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Working a loss finding through (MFG-012).
 *
 *   DETECTED -> INVESTIGATING -> VERIFIED -> ACTIONED -> CLOSED
 *                            \-> DISMISSED
 *
 * The detected impact is an estimate and stays one. A verified loss is a separate amount, entered
 * with the evidence for it. A saving is realised only when the result of the corrective action is
 * recorded with its evidence -- and a claimed saving, like a verified loss above the rule's limit,
 * waits for management approval by somebody other than the people who investigated it.
 */
class LossInvestigation
{
    /** Days between escalations of an overdue finding. */
    private const ESCALATE_EVERY_DAYS = 3;

    private const MAX_ESCALATION = 3;

    public function __construct(private readonly LossRules $rules) {}

    public function assign(Finding $f, User $owner, ?string $dueDate, ?User $actor): Finding
    {
        $this->assertOpen($f);

        return $this->step($f, 'ASSIGNED', $f->status, $actor, null, ['owner' => $this->name($owner), 'due_date' => $dueDate],
            ['owner_id' => $owner->id, 'due_date' => $dueDate ?? $f->due_date?->toDateString()]);
    }

    public function investigate(Finding $f, ?User $actor, ?string $notes = null): Finding
    {
        $this->assertStatus($f, [Finding::DETECTED], 'started');

        return $this->step($f, 'INVESTIGATING', Finding::INVESTIGATING, $actor, $notes, [],
            ['owner_id' => $f->owner_id ?? $actor?->id, 'investigation_notes' => $this->append($f->investigation_notes, $notes)]);
    }

    public function note(Finding $f, string $notes, ?User $actor): Finding
    {
        $this->assertOpen($f);
        if (blank($notes)) {
            throw new DomainException('Write the note.');
        }

        return $this->step($f, 'NOTE', $f->status, $actor, $notes, [], ['investigation_notes' => $this->append($f->investigation_notes, $notes)]);
    }

    /** It is a real loss, of this much, and this is how we know. */
    public function verify(Finding $f, float $amount, string $evidence, ?User $actor): Finding
    {
        $this->assertStatus($f, [Finding::INVESTIGATING], 'verified');
        if ($amount <= 0) {
            throw new DomainException('A verified loss is an amount greater than zero. If there is no loss, dismiss the finding.');
        }
        if (blank($evidence)) {
            throw new DomainException('Say what proves the loss: the count, the documents, the people spoken to.');
        }
        $limit = $this->rules->for($f->company_id, $f->category)->approval_over;

        return $this->step($f, 'VERIFIED', Finding::VERIFIED, $actor, $evidence, ['verified_loss' => $amount, 'estimated_impact' => $f->estimated_impact], [
            'verified_loss' => round($amount, 2), 'verification_evidence' => $evidence, 'verified_by' => $actor?->id, 'verified_at' => now(),
            'requires_approval' => $f->requires_approval || ($limit !== null && $amount >= $limit),
        ]);
    }

    /** Explained, or not a loss at all. */
    public function dismiss(Finding $f, string $reason, ?User $actor): Finding
    {
        $this->assertStatus($f, [Finding::DETECTED, Finding::INVESTIGATING], 'dismissed');
        if (blank($reason)) {
            throw new DomainException('Say why it is not a loss.');
        }

        return $this->step($f, 'DISMISSED', Finding::DISMISSED, $actor, $reason, [], ['dismissal_reason' => $reason, 'closed_at' => now()]);
    }

    public function plan(Finding $f, string $action, ?string $dueDate, ?User $actor): Finding
    {
        $this->assertStatus($f, [Finding::VERIFIED], 'given a corrective action');
        if (blank($action)) {
            throw new DomainException('Describe the corrective action.');
        }

        return $this->step($f, 'ACTION_PLANNED', Finding::VERIFIED, $actor, $action, ['due_date' => $dueDate],
            ['corrective_action' => $action, 'due_date' => $dueDate ?? $f->due_date?->toDateString()]);
    }

    /**
     * What happened after the corrective action. A saving is recorded only with its evidence, and
     * claiming one sends the finding for approval.
     */
    public function result(Finding $f, string $result, ?float $saving, ?string $savingEvidence, ?User $actor): Finding
    {
        $this->assertStatus($f, [Finding::VERIFIED], 'given a result');
        if (blank($f->corrective_action)) {
            throw new DomainException('Record the corrective action before its result.');
        }
        if (blank($result)) {
            throw new DomainException('Describe the result.');
        }
        if ($saving !== null && $saving < 0) {
            throw new DomainException('A saving cannot be negative.');
        }
        if ($saving && blank($savingEvidence)) {
            throw new DomainException('A saving counts as realised only with the evidence for it -- the before-and-after figures and where they come from.');
        }

        return $this->step($f, 'RESULT', Finding::ACTIONED, $actor, $result, ['realised_saving' => $saving], [
            'result_after_action' => $result, 'realised_saving' => $saving ?: null, 'saving_evidence' => $saving ? $savingEvidence : null,
            'requires_approval' => $f->requires_approval || (bool) $saving,
        ]);
    }

    /** Management sign-off: never by the investigator or whoever verified the loss. */
    public function approve(Finding $f, ?User $actor, ?string $notes = null): Finding
    {
        if (! $f->requires_approval) {
            throw new DomainException("{$f->number} does not need approval.");
        }
        if ($f->approved_at) {
            throw new DomainException("{$f->number} is already approved.");
        }
        $this->assertStatus($f, [Finding::VERIFIED, Finding::ACTIONED], 'approved');
        // A warehouse case may be signed off by the warehouse's own loss approvers (WMS-112).
        $warehouseCase = in_array($f->category, Finding::WAREHOUSE_CATEGORIES, true) && $actor?->can('wms.loss.approve');
        if (! $actor?->can(RoleCatalog::APPROVE_LOSS) && ! $warehouseCase) {
            throw new DomainException('Approving a loss finding needs the loss approval permission.');
        }
        if (in_array($actor->id, array_filter([$f->owner_id, $f->verified_by]), true)) {
            throw new DomainException('The approval must come from somebody other than the investigator.');
        }

        return $this->step($f, 'APPROVED', $f->status, $actor, $notes, [], ['approved_by' => $actor->id, 'approved_at' => now(), 'approval_notes' => $notes]);
    }

    public function close(Finding $f, ?User $actor): Finding
    {
        $this->assertStatus($f, [Finding::ACTIONED], 'closed');
        if ($f->requires_approval && ! $f->approved_at) {
            throw new DomainException('Management approval is needed before this finding can close.');
        }

        return $this->step($f, 'CLOSED', Finding::CLOSED, $actor, null, [], ['closed_at' => now()]);
    }

    /** Overdue findings climb a level every few days, up to the top. */
    public function escalateOverdue(int $companyId): int
    {
        $n = 0;
        $due = Finding::withoutGlobalScopes()->where('company_id', $companyId)->whereIn('status', Finding::OPEN)
            ->whereDate('due_date', '<', today())->where('escalation_level', '<', self::MAX_ESCALATION)
            ->where(fn ($q) => $q->whereNull('escalated_at')->orWhere('escalated_at', '<=', now()->subDays(self::ESCALATE_EVERY_DAYS)))->get();
        foreach ($due as $f) {
            $this->step($f, 'ESCALATED', $f->status, null, "Overdue since {$f->due_date->toDateString()}", ['level' => $f->escalation_level + 1],
                ['escalation_level' => $f->escalation_level + 1, 'escalated_at' => now()]);
            $n++;
        }

        return $n;
    }

    /**
     * The three amounts, kept apart: what detectors estimate is at stake in open findings, what
     * investigators have verified as lost, and what corrective action has saved -- evidenced and
     * approved. Nothing is counted in more than one.
     */
    public function summary(): array
    {
        $all = Finding::query()->get(['status', 'category', 'estimated_impact', 'verified_loss', 'realised_saving', 'saving_evidence', 'approved_at', 'due_date', 'escalation_level']);

        return [
            'open' => $all->whereIn('status', Finding::OPEN)->count(),
            'overdue' => $all->filter(fn ($f) => $f->isOverdue())->count(),
            'escalated' => $all->whereIn('status', Finding::OPEN)->where('escalation_level', '>', 0)->count(),
            'estimated_open' => round((float) $all->whereIn('status', [Finding::DETECTED, Finding::INVESTIGATING])->sum('estimated_impact'), 2),
            'verified_loss' => round((float) $all->whereNotIn('status', [Finding::DISMISSED])->sum('verified_loss'), 2),
            'realised_saving' => round((float) $all->filter(fn ($f) => $f->realised_saving && $f->saving_evidence && $f->approved_at)->sum('realised_saving'), 2),
            'saving_awaiting_approval' => round((float) $all->filter(fn ($f) => $f->realised_saving && ! $f->approved_at)->sum('realised_saving'), 2),
            'by_category' => $all->groupBy('category')->map(fn ($g, $c) => ['category' => $c, 'open' => $g->whereIn('status', Finding::OPEN)->count(),
                'estimated' => round((float) $g->whereIn('status', Finding::OPEN)->sum('estimated_impact'), 2),
                'verified' => round((float) $g->sum('verified_loss'), 2)])->values()->all(),
        ];
    }

    // ── Internals ────────────────────────────────────────────────────────────────────────────

    private function step(Finding $f, string $action, string $to, ?User $actor, ?string $notes, array $detail, array $changes): Finding
    {
        return DB::transaction(function () use ($f, $action, $to, $actor, $notes, $detail, $changes) {
            $from = $f->status;
            $f->fill($changes + ['status' => $to])->save();
            LossEvent::create(['company_id' => $f->company_id, 'finding_id' => $f->id, 'action' => $action, 'from_status' => $from, 'to_status' => $to,
                'actor_id' => $actor?->id, 'notes' => $notes, 'detail' => $detail ?: null, 'acted_at' => now()]);

            return $f->refresh();
        });
    }

    private function assertOpen(Finding $f): void
    {
        if (! in_array($f->status, Finding::OPEN, true)) {
            throw new DomainException("{$f->number} is ".strtolower($f->status).'.');
        }
    }

    private function assertStatus(Finding $f, array $allowed, string $verb): void
    {
        if (! in_array($f->status, $allowed, true)) {
            throw new DomainException("{$f->number} is ".strtolower($f->status)." and cannot be {$verb}.");
        }
    }

    private function append(?string $existing, ?string $notes): ?string
    {
        if (blank($notes)) {
            return $existing;
        }

        return trim(($existing ? $existing."\n\n" : '').now()->format('Y-m-d H:i').' — '.$notes);
    }

    private function name(User $u): string
    {
        $p = $u->person;

        return $p ? trim("{$p->first_name} {$p->surname}") : (string) $u->email;
    }
}
