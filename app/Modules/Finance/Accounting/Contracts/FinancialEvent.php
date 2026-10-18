<?php

namespace App\Modules\Finance\Accounting\Contracts;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The canonical financial event (v1). Every operational module emits this one
 * shape; the Ledger consumes it. Additive changes only — never remove a field.
 *
 * Each debit/credit line: ['account' => code|id, 'amount' => float,
 * 'dimensions' => [...], 'narration' => ?string].
 */
final class FinancialEvent
{
    public string $eventId;
    public CarbonInterface $eventDate;

    /**
     * @param  array<int, array{account: string|int, amount: float|int, dimensions?: array, narration?: string}>  $debits
     * @param  array<int, array{account: string|int, amount: float|int, dimensions?: array, narration?: string}>  $credits
     */
    public function __construct(
        public string $sourceModule,
        public string $eventType,
        public array $debits,
        public array $credits,
        CarbonInterface|string|null $eventDate = null,
        public ?int $companyId = null,
        public string $currency = 'ZAR',
        public string $sourceRef = '',
        public string $description = '',
        public array $metadata = [],
        ?string $idempotencyKey = null,
        ?string $eventId = null,
    ) {
        $this->eventId   = $eventId ?: (string) Str::uuid();
        $this->eventDate = $eventDate ? Carbon::parse($eventDate) : Carbon::now();
        $this->idempotencyKey = $idempotencyKey ?: $this->eventId;

        $this->validate();
    }

    public string $idempotencyKey;

    public function debitTotal(): float
    {
        return round(array_sum(array_map(fn ($l) => (float) $l['amount'], $this->debits)), 2);
    }

    public function creditTotal(): float
    {
        return round(array_sum(array_map(fn ($l) => (float) $l['amount'], $this->credits)), 2);
    }

    public function isBalanced(): bool
    {
        return abs($this->debitTotal() - $this->creditTotal()) < 0.005;
    }

    private function validate(): void
    {
        if (empty($this->debits) || empty($this->credits)) {
            throw new InvalidArgumentException('A financial event needs at least one debit and one credit.');
        }
        foreach ([...$this->debits, ...$this->credits] as $line) {
            if (! isset($line['account']) || ! isset($line['amount']) || (float) $line['amount'] <= 0) {
                throw new InvalidArgumentException('Each line needs an account and a positive amount.');
            }
        }
        if (! $this->isBalanced()) {
            throw new InvalidArgumentException(
                "Unbalanced event: debits {$this->debitTotal()} != credits {$this->creditTotal()}."
            );
        }
    }

    public function toArray(): array
    {
        return [
            'event_id' => $this->eventId, 'company_id' => $this->companyId,
            'source_module' => $this->sourceModule, 'source_ref' => $this->sourceRef,
            'event_type' => $this->eventType, 'event_date' => $this->eventDate->toDateString(),
            'currency' => $this->currency, 'debits' => $this->debits, 'credits' => $this->credits,
            'metadata' => $this->metadata, 'idempotency_key' => $this->idempotencyKey,
        ];
    }
}
