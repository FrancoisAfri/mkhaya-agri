<?php

namespace App\Modules\Operations\Manufacturing\Services;

use App\Core\User\Models\User;
use App\Modules\Operations\Manufacturing\Models\NcrAction;
use App\Modules\Operations\Manufacturing\Models\Nonconformance as Ncr;
use App\Modules\Tenancy\Services\CompanyEntitlements;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Non-conformance and corrective action (MFG-009).
 *
 * Open -> Investigating (root cause) -> Action (containment, corrective, preventive, each with an
 * owner and a deadline) -> Verification (every action done) -> Closed if the fix worked, back to
 * Action if it did not. Two rules make it a control rather than a list:
 *
 *  - A major or critical NCR is verified by somebody who did none of its actions. The person who
 *    did the work believes it is fixed; that is not the same as checking.
 *  - Defects are counted on a code, so "the same thing again" is a number, not a feeling.
 *
 * Where the tenant runs SHEQ, the NCR can be raised in SHEQ's register too, so plant quality and
 * site quality are one picture.
 */
class Nonconformances
{
    public function __construct(private readonly NumberSequences $numbers) {}

    /** @param array<string, mixed> $data */
    public function raise(array $data, ?int $companyId, ?User $actor): Ncr
    {
        $severity = in_array($data['severity'] ?? null, Ncr::SEVERITIES, true) ? $data['severity'] : 'MINOR';

        if (blank($data['description'] ?? null)) {
            throw new DomainException('Describe the non-conformance.');
        }

        return Ncr::create([
            'company_id' => $companyId,
            'number' => $this->numbers->next($companyId, 'NONCONFORMANCE'),
            'source' => in_array($data['source'] ?? null, Ncr::SOURCES, true) ? $data['source'] : 'INTERNAL',
            'inspection_id' => $data['inspection_id'] ?? null,
            'stock_item_id' => $data['stock_item_id'] ?? null,
            'lot_id' => $data['lot_id'] ?? null,
            'production_order_id' => $data['production_order_id'] ?? null,
            'supplier_id' => $data['supplier_id'] ?? null,
            'purchase_order_id' => $data['purchase_order_id'] ?? null,
            'defect_code' => filled($data['defect_code'] ?? null) ? strtoupper(trim($data['defect_code'])) : null,
            'description' => $data['description'],
            'qty_affected' => $data['qty_affected'] ?? null,
            'severity' => $severity,
            'status' => Ncr::OPEN,
            'owner_id' => $data['owner_id'] ?? null,
            // A due date always: an NCR nobody is late on is an NCR nobody works.
            'due_date' => $data['due_date'] ?? Carbon::today()->addDays(match ($severity) {
                'CRITICAL' => 3, 'MAJOR' => 7, default => 21,
            })->toDateString(),
            'raised_by' => $actor?->id,
        ]);
    }

    /** Containment, root cause, owner, deadline -- while it is open. */
    public function update(Ncr $ncr, array $data): Ncr
    {
        $this->assertOpen($ncr);

        if (isset($data['defect_code'])) {
            $data['defect_code'] = filled($data['defect_code']) ? strtoupper(trim($data['defect_code'])) : null;
        }

        $ncr->fill(collect($data)->only(['description', 'defect_code', 'severity', 'containment', 'root_cause', 'owner_id', 'due_date', 'qty_affected'])->all());

        // Recording a root cause is what starting an investigation means.
        if ($ncr->status === Ncr::OPEN && filled($ncr->root_cause)) {
            $ncr->status = Ncr::INVESTIGATING;
        }

        $ncr->save();

        return $ncr;
    }

    public function addAction(Ncr $ncr, array $data): NcrAction
    {
        $this->assertOpen($ncr);

        if (! in_array($data['type'] ?? null, NcrAction::TYPES, true) || blank($data['description'] ?? null)) {
            throw new DomainException('An action needs a type and a description.');
        }

        return DB::transaction(function () use ($ncr, $data) {
            $action = $ncr->actions()->create([
                'company_id' => $ncr->company_id, 'type' => $data['type'], 'description' => $data['description'],
                'owner_id' => $data['owner_id'] ?? $ncr->owner_id, 'due_date' => $data['due_date'] ?? $ncr->due_date,
                'status' => 'OPEN',
            ]);

            if (in_array($ncr->status, [Ncr::OPEN, Ncr::INVESTIGATING, Ncr::VERIFICATION], true)) {
                $ncr->update(['status' => Ncr::ACTION]);
            }

            return $action;
        });
    }

    public function completeAction(NcrAction $action, ?string $notes, ?User $actor): NcrAction
    {
        $this->assertOpen($action->ncr);

        if ($action->status === 'DONE') {
            throw new DomainException('That action is already done.');
        }

        $action->update(['status' => 'DONE', 'completed_by' => $actor?->id, 'completed_at' => now(), 'completion_notes' => $notes]);

        return $action;
    }

    /** Ready to check: a root cause, at least one corrective action, and every action done. */
    public function submitForVerification(Ncr $ncr): Ncr
    {
        $this->assertOpen($ncr);
        $actions = $ncr->actions()->get();

        if (blank($ncr->root_cause)) {
            throw new DomainException('Record the root cause first.');
        }
        if (! $actions->contains('type', 'CORRECTIVE')) {
            throw new DomainException('Add at least one corrective action.');
        }
        if ($actions->contains('status', 'OPEN')) {
            throw new DomainException('Every action has to be done before the fix can be verified.');
        }

        $ncr->update(['status' => Ncr::VERIFICATION]);

        return $ncr;
    }

    /**
     * Was it effective? Effective closes it; not effective sends it back for more action. A major or
     * critical NCR cannot be verified by anybody who carried out one of its actions.
     */
    public function verify(Ncr $ncr, string $effectiveness, ?string $notes, ?User $actor): Ncr
    {
        if ($ncr->status !== Ncr::VERIFICATION) {
            throw new DomainException('Only an NCR whose actions are done can be verified.');
        }
        if (! in_array($effectiveness, ['EFFECTIVE', 'NOT_EFFECTIVE'], true)) {
            throw new DomainException('Say whether the action was effective.');
        }

        if (in_array($ncr->severity, ['MAJOR', 'CRITICAL'], true) && $actor
            && $ncr->actions()->where('completed_by', $actor->id)->exists()) {
            throw new DomainException(sprintf('A %s non-conformance cannot be verified by somebody who carried out its actions. Somebody else has to check it.', strtolower($ncr->severity)));
        }

        $ncr->update($effectiveness === 'EFFECTIVE'
            ? ['effectiveness' => 'EFFECTIVE', 'effectiveness_notes' => $notes, 'verified_by' => $actor?->id, 'verified_at' => now(),
                'status' => Ncr::CLOSED, 'closed_at' => now()]
            : ['effectiveness' => 'NOT_EFFECTIVE', 'effectiveness_notes' => $notes, 'status' => Ncr::ACTION]);

        return $ncr;
    }

    public function void(Ncr $ncr, string $reason): Ncr
    {
        $this->assertOpen($ncr);

        if (blank($reason)) {
            throw new DomainException('Say why the NCR is void.');
        }

        $ncr->update(['status' => Ncr::VOID, 'void_reason' => $reason, 'closed_at' => now()]);

        return $ncr;
    }

    /**
     * Other NCRs with the same defect code on the same material or from the same supplier in the
     * last year. The repeat-defect count, and the list behind it.
     */
    public function repeats(Ncr $ncr, int $days = 365)
    {
        if (! $ncr->defect_code) {
            return collect();
        }

        return Ncr::query()
            ->where('id', '!=', $ncr->id)
            ->where('defect_code', $ncr->defect_code)
            ->where('created_at', '>=', now()->subDays($days))
            ->where(fn ($q) => $q
                ->when($ncr->stock_item_id, fn ($x) => $x->orWhere('stock_item_id', $ncr->stock_item_id))
                ->when($ncr->supplier_id, fn ($x) => $x->orWhere('supplier_id', $ncr->supplier_id))
                ->when(! $ncr->stock_item_id && ! $ncr->supplier_id, fn ($x) => $x->whereRaw('true')))
            ->orderByDesc('id')->get();
    }

    /** Whether this tenant runs SHEQ, so the NCR can go to its register too. */
    public function sheqAvailable(): bool
    {
        return Schema::hasTable('sheq_ncrs')
            && DB::table('security_modules')->where('code_name', 'sheq')->where('active', 1)->exists()
            && app(CompanyEntitlements::class)->currentAllowsModule('sheq');
    }

    /** Raise the same NCR in SHEQ's register, once. */
    public function raiseInSheq(Ncr $ncr, ?User $actor): Ncr
    {
        if ($ncr->sheq_ncr_id) {
            throw new DomainException('This NCR is already in the SHEQ register.');
        }
        if (! $this->sheqAvailable()) {
            throw new DomainException('SHEQ is not part of this subscription.');
        }

        $sheq = app(\App\Modules\Operations\Sheq\Services\QualityService::class)->raiseNcr((int) $ncr->company_id, [
            'defect_code' => $ncr->defect_code,
            'defect' => $ncr->description,
            'discipline' => 'MATERIALS',
            'severity' => $ncr->severity,
            'location_text' => "Manufacturing {$ncr->number}",
            'responsible_id' => $ncr->owner_id,
            'due_date' => $ncr->due_date?->toDateString(),
        ], $actor?->id);

        $ncr->update(['sheq_ncr_id' => $sheq['id']]);

        return $ncr;
    }

    private function assertOpen(Ncr $ncr): void
    {
        if (! $ncr->isOpen()) {
            throw new DomainException("NCR {$ncr->number} is ".strtolower($ncr->status).'.');
        }
    }
}
