<?php

namespace App\Modules\SupplierManagement\DueDiligence\Services;

use App\Core\User\Models\User;
use App\Modules\SupplierManagement\DueDiligence\Support\CaseState;
use App\Modules\SupplierManagement\DueDiligence\Support\RoleSlot;
use App\Modules\SupplierManagement\Models\DueDiligenceCase;
use App\Modules\SupplierManagement\Models\DueDiligenceCaseTransition;
use App\Modules\SupplierManagement\Models\Supplier;
use App\Modules\SupplierManagement\Models\SupplierRiskScore;
use App\Modules\SupplierManagement\Models\SupplierSetting;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Moves a case, and refuses to move it when it should not.
 *
 * Every refusal here is a control somebody would otherwise have to remember:
 *
 *  - The raiser cannot decide. One person raising a supplier, entering its banking details and
 *    clearing its own file is the single-operator path every payments fraud takes, and no role map
 *    is allowed to permit it.
 *  - Approval requires the gates. A person may reject a supplier against the gates any time; nobody
 *    approves around a failing gate without recording an override, and the override is itself a
 *    transition, so it lives on the file rather than in somebody's memory.
 *  - Enhanced due diligence must actually be done. A case cannot leave EDD with required tasks
 *    outstanding, or the pack becomes paperwork that gets skipped under deadline.
 *  - Terminal is terminal. Nothing moves out of rejected or blacklisted; a new case is raised, so
 *    the original decision stays legible instead of being edited into something else.
 *
 * Every move writes a transition with the actor's slot captured at the time, and every move ends
 * with the evidence ledger, because "who approved this and on what basis" is the question the whole
 * module exists to answer.
 */
class CaseWorkflow
{
    public function __construct(
        private readonly RoleMap $roles,
        private readonly RiskEngine $risk,
        private readonly EvidenceLedger $ledger,
        private readonly CurrentCompany $currentCompany,
    ) {}

    /**
     * Open a case for a supplier, or return the one already open.
     *
     * Idempotent on purpose: two people pressing the same button should not produce two files for
     * the same engagement, and a second case would split the evidence between them.
     */
    public function open(Supplier $supplier, ?int $tier = null): DueDiligenceCase
    {
        if ($existing = DueDiligenceCase::openFor($supplier->id)) {
            return $existing;
        }

        $sla = (int) (SupplierSetting::query()->first()->case_sla_days ?? 10);

        $case = DueDiligenceCase::create([
            'supplier_id' => $supplier->id,
            'reference' => $this->reference(),
            'state' => CaseState::DRAFT,
            'tier' => $tier ?? $supplier->risk_tier,
            'risk_score' => $supplier->risk_score,
            'risk_band' => $supplier->risk_band,
            'raised_by' => Auth::id(),
            'owner_id' => Auth::id(),
            'owner_slot' => RoleSlot::PROCUREMENT_OFFICER,
            'due_at' => Carbon::now()->addDays($sla),
        ]);

        $this->record($case, null, CaseState::DRAFT, 'open', null, ['tier' => $case->tier]);

        return $case;
    }

    /**
     * Can this user make this move, and if not, why not?
     *
     * Returns the reasons rather than a boolean so the screen can grey a button AND say what would
     * have to change — a disabled control with no explanation is the most common way a workflow
     * gets described as broken.
     *
     * @return array{allowed:bool, reasons:array<int,string>}
     */
    public function check(DueDiligenceCase $case, string $action, ?User $user = null): array
    {
        $user ??= Auth::user();
        $definition = CaseState::transitions()[$action] ?? null;
        $reasons = [];

        if (! $definition) {
            return ['allowed' => false, 'reasons' => ["\"{$action}\" is not a move this workflow has."]];
        }

        if (CaseState::isTerminal($case->state)) {
            $reasons[] = 'This case is '.CaseState::label($case->state).' and cannot be moved. Raise a new case instead.';
        } elseif (! in_array($case->state, $definition['from'], true)) {
            $reasons[] = 'A case that is '.CaseState::label($case->state).' cannot be '.Str::lower($definition['label']).'.';
        }

        if (! $this->roles->can($user, $definition['slot'])) {
            $reasons[] = 'This needs a '.RoleSlot::label($definition['slot']).'.';
        }

        // The one rule no configuration may switch off.
        if ($definition['blocks_if_raiser'] && $user && (int) $case->raised_by === (int) $user->id) {
            $reasons[] = 'You raised this case, so somebody else has to decide it.';
        }

        if ($definition['requires_gates']) {
            $gateReasons = $this->gateBlockers($case);

            if ($gateReasons !== []) {
                $reasons[] = 'Approval gates are failing: '.implode(' ', $gateReasons);
            }
        }

        if ($action === 'to_compliance' || $action === 'to_procurement' || $action === 'approve') {
            if ($case->state === CaseState::ENHANCED_DUE_DILIGENCE && $case->outstandingEdd() > 0) {
                $reasons[] = $case->outstandingEdd().' enhanced due-diligence item(s) are still outstanding.';
            }
        }

        return ['allowed' => $reasons === [], 'reasons' => $reasons];
    }

    /**
     * Make a move.
     *
     * @param  array<string,mixed>  $options  reason, conditions, conditions_due, override
     */
    public function apply(DueDiligenceCase $case, string $action, array $options = [], ?User $user = null): DueDiligenceCase
    {
        $user ??= Auth::user();
        $definition = CaseState::transitions()[$action] ?? null;

        if (! $definition) {
            throw ValidationException::withMessages(['action' => "Unknown action \"{$action}\"."]);
        }

        $check = $this->check($case, $action, $user);
        $reason = trim((string) ($options['reason'] ?? ''));

        if (! $check['allowed']) {
            // An override is permitted only for a failing gate, only with a reason, and only by
            // somebody senior enough to make the move at all. It never bypasses the raiser rule.
            $onlyGates = count($check['reasons']) === 1 && Str::startsWith($check['reasons'][0], 'Approval gates are failing');

            if (! ($onlyGates && ! empty($options['override']) && $reason !== '')) {
                throw ValidationException::withMessages(['action' => implode(' ', $check['reasons'])]);
            }
        }

        if ($definition['requires_reason'] && $reason === '') {
            throw ValidationException::withMessages([
                'reason' => 'This decision needs a reason on record.',
            ]);
        }

        return DB::transaction(function () use ($case, $action, $definition, $options, $user, $reason) {
            $from = $case->state;
            $snapshot = SupplierRiskScore::latestFor($case->supplier_id);

            $attributes = [
                'state' => $definition['to'],
                'risk_score' => $snapshot?->total ?? $case->risk_score,
                'risk_band' => $snapshot?->band ?? $case->risk_band,
                'owner_slot' => $this->nextOwnerSlot($definition['to'], $definition['slot']),
            ];

            if (in_array($definition['to'], [CaseState::APPROVED, CaseState::REJECTED, CaseState::BLACKLISTED, CaseState::CONDITIONAL_APPROVAL], true)) {
                $attributes += [
                    'decided_at' => Carbon::now(),
                    'decided_by' => $user?->id,
                    'decided_role' => RoleSlot::label($definition['slot']),
                    'decision_reason' => $reason ?: null,
                    'closed_reason' => match ($definition['to']) {
                        CaseState::APPROVED, CaseState::CONDITIONAL_APPROVAL => 'approved',
                        CaseState::REJECTED => 'rejected',
                        default => 'blacklisted',
                    },
                ];
            }

            if ($definition['to'] === CaseState::CONDITIONAL_APPROVAL) {
                $conditions = array_values(array_filter((array) ($options['conditions'] ?? [])));

                if ($conditions === []) {
                    throw ValidationException::withMessages([
                        'conditions' => 'A conditional approval has to say what the conditions are.',
                    ]);
                }

                // An "approved subject to" with no deadline is an approval.
                $attributes['conditions'] = $conditions;
                $attributes['conditions_due'] = $options['conditions_due'] ?? Carbon::now()->addDays(30)->toDateString();
            }

            $case->forceFill($attributes)->save();

            $this->record($case, $from, $definition['to'], $action, $reason ?: null, [
                'risk_score' => $case->risk_score,
                'risk_band' => $case->risk_band,
                'override' => ! empty($options['override']),
                'gates' => $this->gateBlockers($case),
            ], $definition['slot'], $user);

            return $case->fresh();
        });
    }

    /** The moves this user could make right now, for a screen that offers buttons. */
    public function availableTo(DueDiligenceCase $case, ?User $user = null): array
    {
        $user ??= Auth::user();

        return collect(CaseState::availableFrom($case->state))
            ->map(function (array $definition, string $action) use ($case, $user) {
                $check = $this->check($case, $action, $user);

                return [
                    'action' => $action,
                    'label' => $definition['label'],
                    'to' => $definition['to'],
                    'to_label' => CaseState::label($definition['to']),
                    'slot' => $definition['slot'],
                    'slot_label' => RoleSlot::label($definition['slot']),
                    'requires_reason' => $definition['requires_reason'],
                    'allowed' => $check['allowed'],
                    // Why not, said out loud: a disabled button with no explanation is how a workflow
                    // gets reported as broken.
                    'reasons' => $check['reasons'],
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Gates failing right now, from the newest assessment.
     *
     * Read from the stored snapshot rather than recalculated, so what the screen shows and what the
     * approval checks are the same thing. A case with no assessment at all fails, because approving
     * a supplier nobody assessed is exactly what the gates exist to stop.
     */
    private function gateBlockers(DueDiligenceCase $case): array
    {
        $snapshot = SupplierRiskScore::latestFor($case->supplier_id);

        if (! $snapshot) {
            return ['This supplier has not been assessed.'];
        }

        return $snapshot->auto_approvable ? [] : ($snapshot->blockers ?? ['The assessment is not clear.']);
    }

    /** Who should hold the case once it lands in its new state. */
    private function nextOwnerSlot(string $state, string $movedBySlot): string
    {
        return match ($state) {
            CaseState::ENHANCED_DUE_DILIGENCE, CaseState::COMPLIANCE_REVIEW, CaseState::SUSPENDED => RoleSlot::COMPLIANCE_OFFICER,
            CaseState::PROCUREMENT_REVIEW => RoleSlot::PROCUREMENT_MANAGER,
            CaseState::MANAGEMENT_APPROVAL, CaseState::BLACKLISTED => RoleSlot::EXECUTIVE,
            CaseState::DRAFT, CaseState::INVITED, CaseState::AWAITING_SUPPLIER, CaseState::SUBMITTED,
            CaseState::VERIFICATION, CaseState::SCREENING, CaseState::RISK_ASSESSMENT => RoleSlot::PROCUREMENT_OFFICER,
            default => $movedBySlot,
        };
    }

    private function record(
        DueDiligenceCase $case,
        ?string $from,
        string $to,
        string $action,
        ?string $reason = null,
        array $context = [],
        ?string $slot = null,
        ?User $user = null,
    ): void {
        $user ??= Auth::user();

        DueDiligenceCaseTransition::create([
            'case_id' => $case->id,
            'from_state' => $from,
            'to_state' => $to,
            'action' => $action,
            'actor_id' => $user?->id,
            'actor_label' => $this->label($user),
            'actor_slot' => $slot,
            'actor_roles' => $this->rolesOf($user),
            'reason' => $reason,
            'context' => $context,
            'occurred_at' => Carbon::now(),
        ]);

        if ($companyId = ($case->company_id ?? $this->currentCompany->id() ?? $user?->company_id)) {
            $this->ledger->record((int) $companyId, [
                'action' => 'case.'.$action,
                'supplier_id' => $case->supplier_id,
                'status' => $to,
                'summary' => "Case {$case->reference}: ".($from ? CaseState::label($from).' → ' : '').CaseState::label($to)
                    .($reason ? ' — '.Str::limit($reason, 200, '') : ''),
                'observed' => $context,
            ]);
        }
    }

    private function label(?User $user): ?string
    {
        if (! $user) {
            return null;
        }

        // No users.name column in this schema; a person's name lives on hr_people.
        $person = $user->person ?? null;

        return $person ? trim("{$person->first_name} {$person->surname}") ?: $user->email : $user->email;
    }

    private function rolesOf(?User $user): ?string
    {
        if (! $user || ! method_exists($user, 'getRoleNames')) {
            return null;
        }

        try {
            return Str::limit($user->getRoleNames()->implode(', '), 185, '') ?: null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function reference(): string
    {
        $year = now()->format('Y');
        $count = DueDiligenceCase::withTrashed()->whereYear('created_at', $year)->count() + 1;

        return sprintf('DD-%s-%04d', $year, $count);
    }
}
