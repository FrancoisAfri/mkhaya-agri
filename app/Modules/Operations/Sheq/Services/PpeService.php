<?php

namespace App\Modules\Operations\Sheq\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * PPE issue and stock (§7 of the SHEQ scope).
 *
 * The reason this is a register and not a spreadsheet: section 8 of the OHS Act requires the
 * employer to provide personal protective equipment, and after an injury that PPE would have
 * prevented, the first question is whether it was issued, when, in the right size, and whether it
 * was still serviceable. "We give everyone gloves" is not an answer; a signed issue record with a
 * replacement date is.
 *
 * Two decisions worth stating:
 *
 * **Stock is decremented in the same transaction as the issue.** Otherwise two storemen issuing the
 * last pair of size-10 boots both succeed, and the register says two people are protected when one
 * is.
 *
 * **Replacement dates come from the item's service life, not from the issuer's memory.** A helmet
 * degrades on a shelf; the person handing it over has no way to judge a five-year-old polymer shell
 * by looking at it, so the date is computed.
 */
class PpeService
{
    public function __construct(
        private readonly \App\Modules\Operations\Sheq\Approvals\ApprovalService $approvals,
    ) {}

    /** Issue PPE to a person, decrementing stock atomically. */
    public function issue(int $companyId, array $data, ?int $actorId = null): array
    {
        $itemId = (int) ($data['ppe_item_id'] ?? 0);

        if ($itemId <= 0) {
            throw new InvalidArgumentException('An issue must name the PPE item.');
        }

        if (empty($data['hr_person_id']) && empty($data['contractor_worker_id'])) {
            throw new InvalidArgumentException(
                'PPE must be issued to somebody. An issue with no recipient cannot evidence that '
                .'the employer provided it.'
            );
        }
        /*
         * An approval workflow, where the tenant configured one.
         *
         * The Approvals screen offered a workflow for this subject and nothing read it, so a
         * tenant could build a chain, save it, and watch it ignored. With no workflow configured
         * this is a no-op, so nothing changes for a tenant that has not set one up.
         */
        /*
         * No request phase to hang this on: issuing PPE and recording a fitness outcome are
         * immediate acts. So the chain is raised here and the act refused until it is signed —
         * the first attempt reports that an approval has been started rather than simply
         * refusing, which is the difference between a queue and a dead end.
         */
        $this->approvals->openIfConfigured($companyId, [
            'subject_type' => 'PPE_ISSUE',
            'subject_id' => $itemId,
            'subject_label' => 'PPE issue',
            'requested_by' => (int) ($actorId ?? 0),
        ]);

        $blocked = $this->approvals->blockingReason($companyId, 'PPE_ISSUE', $itemId, null, null);

        if ($blocked !== null) {
            throw new RuntimeException($blocked);
        }


        return DB::transaction(function () use ($companyId, $data, $itemId, $actorId) {
            $item = DB::table('sheq_ppe_items')->where('company_id', $companyId)
                ->where('id', $itemId)->whereNull('deleted_at')
                ->lockForUpdate()->first(['id', 'name', 'lifespan_months', 'requires_size']);

            if ($item === null) {
                throw new RuntimeException('That PPE item does not exist in this company.');
            }

            if ($item->requires_size && empty($data['size'])) {
                throw new InvalidArgumentException(
                    "{$item->name} is issued by size, and PPE in the wrong size is PPE that will "
                    .'be taken off. Record the size.'
                );
            }

            $quantity = max(1, (int) ($data['quantity'] ?? 1));

            // Locked and decremented in the same transaction: two storemen issuing the last pair
            // must not both succeed.
            $stock = DB::table('sheq_ppe_stock')
                ->where('company_id', $companyId)->where('ppe_item_id', $itemId)
                ->when(! empty($data['size']), fn ($q) => $q->where('size', $data['size']))
                ->when(! empty($data['site_id']), fn ($q) => $q->where('site_id', $data['site_id']))
                ->lockForUpdate()->first(['id', 'quantity_on_hand']);

            if ($stock !== null) {
                if ($stock->quantity_on_hand < $quantity) {
                    throw new RuntimeException(sprintf(
                        'Only %d of %s left in stock%s. Issuing more than is held would make the '
                        .'register disagree with the store.',
                        $stock->quantity_on_hand, $item->name,
                        empty($data['size']) ? '' : " in size {$data['size']}",
                    ));
                }

                DB::table('sheq_ppe_stock')->where('id', $stock->id)
                    ->update(['quantity_on_hand' => $stock->quantity_on_hand - $quantity,
                        'updated_at' => now()]);
            }

            $issuedOn = Carbon::parse($data['issued_on'] ?? now());

            $uuid = (string) Str::uuid();

            $id = DB::table('sheq_ppe_issues')->insertGetId([
                'uuid' => $uuid,
                'company_id' => $companyId,
                'ppe_item_id' => $itemId,
                'hr_person_id' => $data['hr_person_id'] ?? null,
                'contractor_worker_id' => $data['contractor_worker_id'] ?? null,
                'size' => $data['size'] ?? null,
                'quantity' => $quantity,
                'serial_number' => $data['serial_number'] ?? null,
                'issued_on' => $issuedOn->toDateString(),
                // Computed from the item's service life, not typed by the issuer.
                'replace_due' => $item->lifespan_months
                    ? $issuedOn->copy()->addMonths($item->lifespan_months)->toDateString()
                    : null,
                'status' => 'ISSUED',
                'signature' => $data['signature'] ?? null,
                'issued_by' => $actorId,
                'note' => $data['note'] ?? null,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            return ['id' => $id, 'uuid' => $uuid, 'replace_due' => $issuedOn->copy()
                ->addMonths((int) ($item->lifespan_months ?? 0))->toDateString()];
        });
    }

    /** Record a return, and put serviceable stock back. */
    public function markReturned(int $companyId, string $uuid, string $condition = 'RETURNED'): void
    {
        $issue = DB::table('sheq_ppe_issues')->where('company_id', $companyId)
            ->where('uuid', $uuid)->whereNull('deleted_at')->first();

        if ($issue === null) {
            throw new RuntimeException("No PPE issue {$uuid} in this company.");
        }

        DB::transaction(function () use ($companyId, $issue, $condition) {
            DB::table('sheq_ppe_issues')->where('id', $issue->id)->update([
                'status' => $condition,
                'returned_on' => now()->toDateString(),
                'updated_at' => now(),
            ]);

            // Only serviceable kit goes back. Damaged or expired PPE returning to the shelf is
            // how somebody ends up issued a harness that failed.
            if ($condition === 'RETURNED') {
                DB::table('sheq_ppe_stock')
                    ->where('company_id', $companyId)->where('ppe_item_id', $issue->ppe_item_id)
                    ->when($issue->size !== null, fn ($q) => $q->where('size', $issue->size))
                    ->increment('quantity_on_hand', $issue->quantity);
            }
        });
    }

    /** Issues due for replacement, and those already overdue. */
    public function replacementsDue(int $companyId, int $withinDays = 30): array
    {
        $today = Carbon::today();

        return DB::table('sheq_ppe_issues as i')
            ->join('sheq_ppe_items as it', 'it.id', '=', 'i.ppe_item_id')
            ->leftJoin('hr_people as p', 'p.id', '=', 'i.hr_person_id')
            ->where('i.company_id', $companyId)->where('i.status', 'ISSUED')
            ->whereNull('i.deleted_at')->whereNotNull('i.replace_due')
            ->where('i.replace_due', '<=', $today->copy()->addDays($withinDays)->toDateString())
            ->orderBy('i.replace_due')
            ->get(['i.uuid', 'i.replace_due', 'i.size', 'it.name', 'it.code',
                'p.first_name', 'p.surname'])
            ->map(fn ($r) => [
                'uuid' => $r->uuid,
                'item' => $r->name,
                'code' => $r->code,
                'size' => $r->size,
                'replace_due' => $r->replace_due,
                'overdue' => Carbon::parse($r->replace_due)->isPast(),
                'person' => trim(($r->first_name ?? '').' '.($r->surname ?? '')) ?: '—',
            ])->values()->all();
    }

    /** Stock at or below its reorder level. */
    public function lowStock(int $companyId): array
    {
        return DB::table('sheq_ppe_stock as s')
            ->join('sheq_ppe_items as i', 'i.id', '=', 's.ppe_item_id')
            ->where('s.company_id', $companyId)
            ->whereColumn('s.quantity_on_hand', '<=', 's.reorder_level')
            ->where('i.is_active', true)
            ->get(['i.name', 'i.code', 's.size', 's.quantity_on_hand', 's.reorder_level'])
            ->map(fn ($r) => (array) $r)->values()->all();
    }

    /** What a person currently holds — what a supervisor checks before a task. */
    public function heldBy(int $companyId, int $hrPersonId): array
    {
        return DB::table('sheq_ppe_issues as i')
            ->join('sheq_ppe_items as it', 'it.id', '=', 'i.ppe_item_id')
            ->where('i.company_id', $companyId)->where('i.hr_person_id', $hrPersonId)
            ->where('i.status', 'ISSUED')->whereNull('i.deleted_at')
            ->get(['i.uuid', 'i.size', 'i.issued_on', 'i.replace_due', 'it.name', 'it.code'])
            ->map(fn ($r) => (array) $r + [
                'expired' => $r->replace_due !== null && Carbon::parse($r->replace_due)->isPast(),
            ])->values()->all();
    }
}
