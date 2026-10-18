<?php

namespace App\Modules\Operations\ProjectManagement\Support\Evidence\Adapters;

use App\Modules\Operations\ProjectManagement\Support\Evidence\ModuleAdapter;

/**
 * Procurement → evidence (PM-1005).
 *
 * The reference implementation of the adapter pattern, and the one with the most value: a goods
 * receipt is simultaneously evidence that material reached site *and* that money was committed, so
 * it feeds both progress and cost derivation without anyone re-keying it.
 *
 * Note what each mapping is worth. A requisition is an intention, an order is a commitment, a GRN
 * is a fact. The taxonomy's confidence values already encode that; the adapter's job is only to
 * pick the right type.
 */
class ProcurementAdapter extends ModuleAdapter
{
    public function capability(): string
    {
        return 'procurement.orders';
    }

    public function sourceModule(): string
    {
        return 'procurement';
    }

    public function mapping(): array
    {
        return [
            'procurement.requisition.approved' => 'requisition.raised',
            'procurement.po.issued'            => 'po.issued',
            'procurement.grn.received'         => 'grn.received',
            'procurement.invoice.received'     => 'invoice.received',
            'procurement.invoice.paid'         => 'invoice.paid',
        ];
    }

    protected function translate(string $externalEvent, array $payload): ?array
    {
        // Procurement runs plenty that has nothing to do with a project. Without a project there is
        // nothing to attach evidence to, and inventing an attachment would be worse than skipping.
        if (empty($payload['project_uuid'])) {
            return null;
        }

        $reference = (string) ($payload['reference'] ?? $payload['number'] ?? '');

        if ($reference === '') {
            return null;
        }

        return [
            'project_id'   => $payload['project_uuid'],
            'subject_type' => 'project',
            // Stable across replays: the same GRN is the same fact however many times the stream
            // is re-read.
            'source_ref'   => $reference,
            'occurred_at'  => $payload['occurred_at'] ?? now()->toIso8601String(),
            'occurred_tz'  => $payload['timezone'] ?? config('app.timezone', 'UTC'),
            'amount'       => isset($payload['amount']) ? (string) $payload['amount'] : null,
            'currency'     => $payload['currency'] ?? null,
            'quantity'     => $payload['quantity'] ?? null,
            'unit'         => $payload['unit'] ?? null,
            'payload'      => array_filter([
                'reference' => $reference,
                'supplier'  => $payload['supplier'] ?? null,
            ], fn ($v) => $v !== null),
        ];
    }
}
