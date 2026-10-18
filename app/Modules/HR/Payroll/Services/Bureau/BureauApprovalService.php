<?php

namespace App\Modules\HR\Payroll\Services\Bureau;

use App\Core\User\Models\User;
use App\Modules\HR\Payroll\Models\Bureau\BureauApprovalStep;
use App\Modules\HR\Payroll\Models\Bureau\BureauCompany;
use App\Modules\HR\Payroll\Models\Bureau\BureauPayRun;
use App\Modules\HR\Payroll\Models\Bureau\BureauPayRunApproval;
use App\Modules\HR\Payroll\Support\PayRunLifecycle;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Walks a client's payroll up its approval chain.
 *
 * Approval used to be a single act with a single rule — not the person who calculated it. That is a
 * control, but it is not a chain: a client who wants their HR manager to check the figures and then
 * their finance director to release the money had nowhere to say so, and the bureau had nothing to
 * show for having done it.
 *
 * A run is approved when every configured step has signed, in order. Until then it sits on a step,
 * and the screen can say whose signature it is waiting for rather than just "awaiting approval".
 */
class BureauApprovalService
{
    public function __construct(private readonly BureauAuditService $audit)
    {
    }

    /**
     * The chain a client uses.
     *
     * A client with steps of its own replaces the tenant default rather than adding to it — the same
     * rule the milestone rules follow, and for the same reason: a client who deliberately removed a
     * step must not get it back from the default.
     *
     * @return Collection<int, BureauApprovalStep>
     */
    public function stepsFor(?BureauCompany $company): Collection
    {
        if ($company) {
            $own = BureauApprovalStep::query()
                ->where('bureau_company_id', $company->id)
                ->where('is_active', true)
                ->orderBy('sequence')
                ->get();

            if ($own->isNotEmpty()) {
                return $own;
            }
        }

        return BureauApprovalStep::query()
            ->whereNull('bureau_company_id')
            ->where('is_active', true)
            ->orderBy('sequence')
            ->get();
    }

    /** The step a run is waiting on, or null when the chain is finished or unconfigured. */
    public function currentStep(BureauPayRun $run): ?BureauApprovalStep
    {
        $steps = $this->stepsFor($run->company);

        if ($steps->isEmpty()) {
            return null;
        }

        return $steps->firstWhere('sequence', $run->current_step ?? $steps->first()->sequence);
    }

    /**
     * Put a calculated run onto the first step of its chain.
     *
     * Called when the run is processed, so the screen can show where it stands without anybody
     * having to press anything first.
     */
    public function begin(BureauPayRun $run): void
    {
        $steps = $this->stepsFor($run->company);

        $run->forceFill([
            'current_step' => $steps->isEmpty() ? null : $steps->first()->sequence,
        ])->save();
    }

    /**
     * Sign the step this run is on.
     *
     * Returns true when that was the last step and the run is now approved. The caller decides what
     * to do with that — this service does not move the run's status past its own concern.
     */
    public function approve(BureauPayRun $run, ?string $comment = null): bool
    {
        $steps = $this->stepsFor($run->company);

        // No chain configured is not an error. A single-operator bureau that never set one up still
        // approves, and the separation-of-duties rule on the run itself still applies.
        if ($steps->isEmpty()) {
            return true;
        }

        $step = $this->currentStep($run);

        if (! $step) {
            return true;
        }

        $user = Auth::user();

        if (! $step->mayBeSignedBy($user, $run->created_by)) {
            throw ValidationException::withMessages([
                'run' => $this->refusal($step, $user, $run),
            ]);
        }

        BureauPayRunApproval::create([
            'bureau_pay_run_id' => $run->id,
            'bureau_company_id' => $run->bureau_company_id,
            'step_id'           => $step->id,
            'sequence'          => $step->sequence,
            'step_name'         => $step->name,
            'decision'          => BureauPayRunApproval::APPROVED,
            'comment'           => $comment,
            'decided_by'        => $user?->id,
            'decided_at'        => now(),
            'round'             => $run->approval_round ?? 1,
        ]);

        $next = $steps->firstWhere('sequence', '>', $step->sequence)
            ?? $steps->first(fn ($s) => $s->sequence > $step->sequence);

        $run->forceFill(['current_step' => $next?->sequence])->save();

        $this->audit->run(
            $run->refresh(),
            BureauAuditService::RUN_APPROVED,
            "Step {$step->sequence} ({$step->name}) approved for {$run->name}.",
            ['step' => $step->name, 'sequence' => $step->sequence, 'comment' => $comment],
        );

        return $next === null;
    }

    /**
     * Send it back.
     *
     * The run returns to review and the chain restarts on the next attempt, because a step signed
     * before the figures changed is not a signature on the figures that came out afterwards.
     */
    public function reject(BureauPayRun $run, string $reason): BureauPayRun
    {
        if (strlen(trim($reason)) < 5) {
            throw ValidationException::withMessages([
                'reason' => 'Say what needs fixing — the reason goes back to whoever prepared the run.',
            ]);
        }

        $step = $this->currentStep($run);
        $user = Auth::user();

        if ($step && ! $step->mayBeSignedBy($user, $run->created_by)) {
            throw ValidationException::withMessages(['run' => $this->refusal($step, $user, $run)]);
        }

        BureauPayRunApproval::create([
            'bureau_pay_run_id' => $run->id,
            'bureau_company_id' => $run->bureau_company_id,
            'step_id'           => $step?->id,
            'sequence'          => $step?->sequence ?? 0,
            'step_name'         => $step?->name ?? 'Approval',
            'decision'          => BureauPayRunApproval::REJECTED,
            'comment'           => trim($reason),
            'decided_by'        => $user?->id,
            'decided_at'        => now(),
            'round'             => $run->approval_round ?? 1,
        ]);

        $steps = $this->stepsFor($run->company);

        $run->forceFill([
            'status'         => PayRunLifecycle::REVIEW,
            'current_step'   => $steps->first()?->sequence,
            'approval_round' => ($run->approval_round ?? 1) + 1,
        ])->save();

        $this->audit->run(
            $run->refresh(),
            BureauAuditService::RUN_REJECTED,
            "{$run->name} sent back at step " . ($step?->name ?? 'approval') . ': ' . trim($reason),
            ['step' => $step?->name, 'reason' => trim($reason)],
        );

        return $run;
    }

    /** Every decision on this run, newest round first — what the screen shows as the trail. */
    public function historyFor(BureauPayRun $run): Collection
    {
        return BureauPayRunApproval::query()
            ->where('bureau_pay_run_id', $run->id)
            ->orderByDesc('round')
            ->orderBy('sequence')
            ->with('decidedBy')
            ->get();
    }

    /** Give a client (or the tenant) the starting chain, once. */
    public function installDefaults(?BureauCompany $company = null): int
    {
        $created = 0;

        foreach (BureauApprovalStep::DEFAULTS as $default) {
            $step = BureauApprovalStep::firstOrCreate(
                ['bureau_company_id' => $company?->id, 'sequence' => $default['sequence']],
                $default + ['is_active' => true, 'created_by' => Auth::id()],
            );

            $created += $step->wasRecentlyCreated ? 1 : 0;
        }

        return $created;
    }

    /** Why somebody was refused, said so they know what to do about it. */
    private function refusal(BureauApprovalStep $step, ?User $user, BureauPayRun $run): string
    {
        if ($user && $run->created_by === $user->id && ! $step->allow_self_approval) {
            return "You calculated this run, so somebody else has to sign \"{$step->name}\".";
        }

        return "\"{$step->name}\" is signed by {$step->approverLabel()}.";
    }
}
