<?php

namespace App\Modules\SupplierManagement\Services;

use App\Modules\SupplierManagement\Models\RfxEvent;
use App\Modules\SupplierManagement\Models\RfxResponse;
use App\Modules\SupplierManagement\Models\Supplier;
use Illuminate\Validation\ValidationException;

/**
 * The rules a tender has to obey to be worth anything.
 *
 * A sourcing event is a sequence of commitments, and the value of the record it leaves behind comes
 * entirely from the order being real: bids arrive after the event is published and before it closes,
 * from companies that were invited, and the award goes to one of them. Written as bare status
 * updates, every one of those can be skipped, and the resulting record is indistinguishable from a
 * clean one — which is the problem, because the whole point of the record is to be evidence.
 *
 * The rules live here rather than in the controller so that a second entry point — an import, an
 * API, a future supplier-facing bid form — cannot route around them by not remembering to check.
 */
class RfxLifecycleService
{
    /**
     * Which statuses a given transition may start from.
     *
     * `awarded` is terminal. Re-awarding was possible before and silently overwrote the winner,
     * leaving no sign that the tender had ever been decided differently.
     */
    private const TRANSITIONS = [
        'published' => ['draft'],
        'closed'    => ['published'],
        'awarded'   => ['closed'],
    ];

    public function publish(RfxEvent $rfx): void
    {
        $this->assertTransition($rfx, 'published');

        if ($rfx->invitations()->count() === 0) {
            throw ValidationException::withMessages([
                'status' => __('supplier_management.rfx.errors.publish_without_invitations'),
            ]);
        }

        if ($rfx->closing_date && $rfx->closing_date->endOfDay()->isPast()) {
            throw ValidationException::withMessages([
                'closing_date' => __('supplier_management.rfx.errors.publish_after_closing'),
            ]);
        }

        $rfx->update(['status' => 'published', 'published_at' => now()]);
    }

    public function close(RfxEvent $rfx): void
    {
        $this->assertTransition($rfx, 'closed');

        $rfx->update(['status' => 'closed', 'closed_at' => now()]);
    }

    /**
     * Record a bid, from an invited supplier, while the event is actually open.
     *
     * Returns the response so the caller can report whether a previous bid was replaced. A supplier
     * revising its own bid before close is legitimate; a supplier holding several live bids at once
     * is not, so this updates rather than inserts.
     */
    public function recordResponse(RfxEvent $rfx, Supplier $supplier, array $data): RfxResponse
    {
        if ($rfx->status !== 'published') {
            throw ValidationException::withMessages([
                'supplier_id' => __('supplier_management.rfx.errors.bid_when_not_published', [
                    'status' => $rfx->status,
                ]),
            ]);
        }

        if ($rfx->closing_date && $rfx->closing_date->endOfDay()->isPast()) {
            throw ValidationException::withMessages([
                'supplier_id' => __('supplier_management.rfx.errors.bid_after_closing', [
                    'date' => $rfx->closing_date->format('d M Y'),
                ]),
            ]);
        }

        if (! $rfx->invitations()->where('supplier_id', $supplier->id)->exists()) {
            throw ValidationException::withMessages([
                'supplier_id' => __('supplier_management.rfx.errors.bid_from_uninvited', [
                    'supplier' => $supplier->company_name,
                ]),
            ]);
        }

        $response = $rfx->responses()->updateOrCreate(
            ['supplier_id' => $supplier->id],
            [
                'amount'       => $data['amount'] ?? null,
                'currency'     => $data['currency'] ?? 'ZAR',
                'proposal'     => $data['proposal'] ?? null,
                'submitted_at' => now(),
                // A revised bid invalidates the score the old one carried.
                'ai_score'     => null,
                'ai_summary'   => null,
            ],
        );

        $rfx->invitations()->where('supplier_id', $supplier->id)->update(['status' => 'responded']);

        return $response;
    }

    /**
     * Award the event to a supplier that actually bid for it.
     *
     * The reason is required whenever the winner is not the cheapest bid on the table. That is the
     * award an auditor asks about, and asking for the justification at the moment of the decision is
     * the only time anyone can still remember it.
     */
    public function award(RfxEvent $rfx, Supplier $supplier, int $userId, ?string $reason = null): void
    {
        $this->assertTransition($rfx, 'awarded');

        $response = $rfx->responses()->where('supplier_id', $supplier->id)->first();

        if (! $response) {
            throw ValidationException::withMessages([
                'supplier_id' => __('supplier_management.rfx.errors.award_without_bid', [
                    'supplier' => $supplier->company_name,
                ]),
            ]);
        }

        if (! $this->isLowestBid($rfx, $response) && blank($reason)) {
            throw ValidationException::withMessages([
                'award_reason' => __('supplier_management.rfx.errors.award_reason_required'),
            ]);
        }

        $rfx->update([
            'awarded_supplier_id' => $supplier->id,
            'status'              => 'awarded',
            'awarded_by'          => $userId,
            'awarded_at'          => now(),
            'award_reason'        => $reason,
        ]);
    }

    /** Suppliers eligible to be awarded — those who bid, cheapest first. */
    public function awardableSuppliers(RfxEvent $rfx): array
    {
        return $rfx->responses()
            ->with('supplier:id,company_name')
            ->orderByRaw('amount IS NULL, amount ASC')
            ->get()
            ->map(fn (RfxResponse $r) => [
                'id'     => $r->supplier_id,
                'name'   => $r->supplier?->company_name,
                'amount' => $r->amount,
            ])
            ->all();
    }

    /** Whether the event is currently accepting bids, for the screen to reflect. */
    public function isOpenForBids(RfxEvent $rfx): bool
    {
        return $rfx->status === 'published'
            && ! ($rfx->closing_date && $rfx->closing_date->endOfDay()->isPast());
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function assertTransition(RfxEvent $rfx, string $to): void
    {
        $from = (string) ($rfx->status ?? 'draft');

        if (in_array($from, self::TRANSITIONS[$to] ?? [], true)) {
            return;
        }

        throw ValidationException::withMessages([
            'status' => __('supplier_management.rfx.errors.invalid_transition', [
                'from' => __("supplier_management.rfx.statuses.{$from}"),
                'to'   => __("supplier_management.rfx.statuses.{$to}"),
            ]),
        ]);
    }

    /**
     * Is this the cheapest bid on the event?
     *
     * Bids with no amount captured cannot be compared, so an event made only of those never demands
     * a reason — there is nothing to justify the choice against.
     */
    private function isLowestBid(RfxEvent $rfx, RfxResponse $response): bool
    {
        if ($response->amount === null) {
            return true;
        }

        $lowest = $rfx->responses()->whereNotNull('amount')->min('amount');

        return $lowest === null || (float) $response->amount <= (float) $lowest;
    }

}
