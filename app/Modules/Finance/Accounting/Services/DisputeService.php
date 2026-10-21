<?php

namespace App\Modules\Finance\Accounting\Services;

use App\Modules\Finance\Accounting\Exceptions\PostingException;
use App\Modules\Finance\Accounting\Models\FinDispute;
use App\Modules\Finance\Accounting\Models\FinInvoice;
use App\Modules\Finance\Accounting\Models\FinParty;
use Illuminate\Support\Facades\DB;

/**
 * A customer's dispute, raised against an invoice (which is then held from dunning) and resolved —
 * upheld with a credit note when money goes back, or rejected.
 */
class DisputeService
{
    public function __construct(private readonly ArApService $arap) {}

    /** @param array{invoice_id?: ?int, subject_type?: ?string, subject_id?: ?int, kind: string, amount?: ?float, description: string, dimensions?: ?array} $data */
    public function raise(FinParty $party, array $data, ?int $actorId): FinDispute
    {
        if (! in_array($data['kind'], FinDispute::KINDS, true)) {
            throw new PostingException('Say what the dispute is about: price, quality, quantity, payment or other.');
        }
        if (! empty($data['invoice_id']) && ! FinInvoice::query()->whereKey($data['invoice_id'])->where('party_id', $party->id)->exists()) {
            throw new PostingException("That invoice is not {$party->name}'s.");
        }

        return DB::transaction(function () use ($party, $data, $actorId) {
            $d = FinDispute::create(['company_id' => $party->company_id, 'party_id' => $party->id, 'status' => 'open', 'raised_by' => $actorId]
                + array_intersect_key($data, array_flip(['invoice_id', 'subject_type', 'subject_id', 'kind', 'amount', 'description', 'dimensions'])));
            if ($d->invoice_id) {
                FinInvoice::query()->whereKey($d->invoice_id)->update(['disputed' => true, 'dispute_reason' => mb_strimwidth($d->description, 0, 250),
                    'disputed_at' => now(), 'disputed_by' => $actorId]);
            }

            return $d;
        });
    }

    /** $outcome: resolved | rejected. A credit is given against the dispute's invoice. */
    public function resolve(FinDispute $d, string $outcome, string $resolution, ?float $credit, ?int $actorId): FinDispute
    {
        if ($d->status !== 'open') {
            throw new PostingException('That dispute is closed.');
        }
        if (! in_array($outcome, ['resolved', 'rejected'], true)) {
            throw new PostingException('A dispute is resolved or rejected.');
        }

        return DB::transaction(function () use ($d, $outcome, $resolution, $credit, $actorId) {
            $cn = null;
            if ($credit && $credit > 0) {
                if (! $d->invoice_id) {
                    throw new PostingException('A credit is given against an invoice; this dispute names none.');
                }
                $cn = $this->arap->createCreditNote(FinInvoice::query()->findOrFail($d->invoice_id), $credit, "Dispute: {$resolution}");
            }
            if ($d->invoice_id) {
                FinInvoice::query()->whereKey($d->invoice_id)->update(['disputed' => false]);
            }
            $d->update(['status' => $outcome, 'resolution' => $resolution, 'credit_note_id' => $cn?->id, 'resolved_by' => $actorId, 'resolved_at' => now()]);

            return $d;
        });
    }
}
