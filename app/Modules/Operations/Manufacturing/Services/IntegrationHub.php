<?php

namespace App\Modules\Operations\Manufacturing\Services;

use App\Core\User\Models\User;
use App\Modules\Finance\Accounting\Models\FinStockMovement;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Operations\Manufacturing\Integrations\IntegrationAdapter;
use App\Modules\Operations\Manufacturing\Integrations\PassiveAdapter;
use App\Modules\Operations\Manufacturing\Integrations\RestAdapter;
use App\Modules\Operations\Manufacturing\Models\DowntimeEvent;
use App\Modules\Operations\Manufacturing\Models\DowntimeReason;
use App\Modules\Operations\Manufacturing\Models\Factory;
use App\Modules\Operations\Manufacturing\Models\Integration;
use App\Modules\Operations\Manufacturing\Models\IntegrationEvent as Event;
use App\Modules\Operations\Manufacturing\Models\IntegrationMapping as Map;
use App\Modules\Operations\Manufacturing\Models\Item;
use App\Modules\Operations\Manufacturing\Models\ProductionOrder as PO;
use App\Modules\Operations\Manufacturing\Models\WorkCentre;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The integration framework (MFG-022): messages in and out of other systems, each processed once.
 *
 * Incoming messages are stored under an idempotency key before anything happens; a second delivery
 * of the same message is counted, not processed. Processing goes through the module's own services
 * -- a weighed issue is an ordinary material issue, a machine stop is an ordinary downtime event, an
 * order from an ERP is a draft production order -- so every control those services keep still
 * holds. A failure retries with back-off and, after the last attempt, waits as a dead letter.
 */
class IntegrationHub
{
    /** Minutes to wait before each retry. */
    private const BACKOFF = [1, 5, 15, 60, 240];

    private const SECRET_KEYS = '/pass|secret|token|api[_-]?key|authorization|credential|private/i';

    /** Message types another module handles (WMS-171): type => fn (Integration, Event, self): [type, id, reference]. */
    private array $handlers = [];

    /** Exports another module queues on sync: settings 'export' name => fn (Integration, self): int queued. */
    private array $exporters = [];

    /**
     * Let another module take a message type through this same pipeline -- the idempotency key, the
     * retries and the dead letter -- instead of building a second one.
     */
    public function handles(string $type, callable $handler): void
    {
        $this->handlers[strtoupper($type)] = $handler;
    }

    public function exportsWith(string $name, callable $queue): void
    {
        $this->exporters[$name] = $queue;
    }

    /** @return list<string> */
    public function exportNames(): array
    {
        return array_merge(['OUTPUT_RECEIPT'], array_keys($this->exporters));
    }

    public function adapter(Integration $i): IntegrationAdapter
    {
        return $i->method === 'API' ? app(RestAdapter::class) : app(PassiveAdapter::class);
    }

    // ── Lifecycle ────────────────────────────────────────────────────────────────────────────

    /**
     * Going live needs the customer's edition, licence and API access confirmed and written down,
     * and, for an API, the system answering.
     */
    public function validate(Integration $i, string $notes, User $actor): array
    {
        if (mb_strlen(trim($notes)) < 20) {
            throw new DomainException('Record what was checked: the customer\'s edition and licence, and that this access method is permitted.');
        }
        $ping = $this->adapter($i)->ping($i);
        if (! $ping['ok']) {
            $i->update(['last_sync_result' => 'Validation failed: '.$ping['detail']]);

            return $ping;
        }
        $i->update(['status' => 'VALIDATED', 'validation_notes' => $notes, 'validated_by' => $actor->id, 'validated_at' => now(),
            'last_sync_result' => 'Validated: '.$ping['detail']]);

        return $ping;
    }

    public function activate(Integration $i): Integration
    {
        if (! in_array($i->status, ['VALIDATED', 'PAUSED'], true) || ! $i->validated_at) {
            throw new DomainException('Validate the integration against the customer\'s edition, licence and access before switching it on.');
        }
        $i->update(['status' => 'ACTIVE']);

        return $i->refresh();
    }

    public function pause(Integration $i): Integration
    {
        $i->update(['status' => 'PAUSED']);

        return $i->refresh();
    }

    // ── In ───────────────────────────────────────────────────────────────────────────────────

    /** Store, then process, one incoming message. A message seen before is counted, not redone. */
    public function ingest(Integration $i, string $type, string $externalId, array $data): Event
    {
        if ($i->status !== 'ACTIVE') {
            throw new DomainException("{$i->name} is not switched on.");
        }
        $key = strtoupper($type).':'.$externalId;
        $existing = Event::query()->where('integration_id', $i->id)->where('idempotency_key', $key)->first();
        if ($existing) {
            $existing->increment('duplicates');

            return $existing->refresh();
        }
        $event = Event::create(['company_id' => $i->company_id, 'integration_id' => $i->id, 'direction' => 'IN', 'event_type' => strtoupper($type),
            'external_id' => $externalId, 'idempotency_key' => $key, 'payload' => $this->redact($data), 'status' => 'RECEIVED']);

        return $this->process($event);
    }

    public function process(Event $event): Event
    {
        $i = $event->integration()->firstOrFail();
        $event->increment('attempts');

        try {
            // A savepoint: a failed handler must not take the event's own record down with it.
            [$type, $id, $ref] = DB::transaction(fn () => $event->direction === 'IN' ? $this->handle($i, $event) : $this->deliver($i, $event));
            $event->update(['status' => 'PROCESSED', 'processed_at' => now(), 'last_error' => null, 'next_attempt_at' => null,
                'result_type' => $type, 'result_id' => $id, 'result_reference' => $ref]);
        } catch (\Throwable $e) {
            $dead = $event->attempts >= Event::MAX_ATTEMPTS;
            $event->update(['status' => $dead ? 'DEAD' : 'FAILED', 'last_error' => mb_substr($this->scrub($i, $e->getMessage()), 0, 1000),
                'next_attempt_at' => $dead ? null : now()->addMinutes(self::BACKOFF[min($event->attempts - 1, count(self::BACKOFF) - 1)])]);
        }

        return $event->refresh();
    }

    /** @return array{0: ?string, 1: ?int, 2: ?string} what the message became */
    private function handle(Integration $i, Event $e): array
    {
        $d = $e->payload;

        return match ($e->event_type) {
            // An ERP's demand becomes a draft production order: people plan and approve it here.
            'ORDER_IMPORT' => (function () use ($i, $e, $d) {
                $item = Item::query()->findOrFail($this->local($i, 'PRODUCT', (string) ($d['product'] ?? '')));
                $warehouse = FinWarehouse::query()->findOrFail($this->local($i, 'WAREHOUSE', (string) ($d['warehouse'] ?? '')));
                $factory = Factory::query()->where('code', $d['factory'] ?? ($i->settings['factory_code'] ?? null))->first()
                    ?? Factory::query()->where('default_warehouse_id', $warehouse->id)->firstOrFail();
                $o = app(ProductionOrders::class)->create(['item' => $item, 'quantity' => (float) $d['qty'], 'due_date' => $d['due_date'] ?? now()->addWeek()->toDateString(),
                    'factory' => $factory, 'warehouse' => $warehouse, 'demand_type' => 'EXTERNAL', 'demand_reference' => "{$i->connector}:{$e->external_id}"], null);
                Map::query()->firstOrCreate(['integration_id' => $i->id, 'entity' => 'ORDER', 'external_ref' => (string) $e->external_id],
                    ['company_id' => $i->company_id, 'local_id' => $o->id]);

                return ['PRODUCTION_ORDER', $o->id, $o->number];
            })(),

            // A scale's reading issues the weighed material to the order -- once, however often it is resent.
            'WEIGHT_READING' => (function () use ($e, $d) {
                $order = PO::query()->where('number', $d['order'] ?? '')->firstOrFail();
                $component = $order->components()->whereHas('stockItem', fn ($q) => $q->where('code', $d['material'] ?? ''))->firstOrFail();
                $m = app(ProductionExecution::class)->issue($order, $component, (float) $d['weight'], null, ['idempotency_key' => "integration:{$e->uuid}"]);

                return ['STOCK_MOVEMENT', $m[0]->id ?? null, $order->number];
            })(),

            // A machine reporting a stop opens a downtime event; reporting it runs again closes it.
            'MACHINE_STATE' => (function () use ($i, $d) {
                $centre = WorkCentre::query()->whereKey($this->localOrNull($i, 'WORK_CENTRE', (string) ($d['machine'] ?? '')))
                    ->orWhere('code', $d['machine'] ?? '')->firstOrFail();
                $open = DowntimeEvent::query()->where('work_centre_id', $centre->id)->whereNull('ended_at')->latest('id')->first();
                $floor = app(ShopFloor::class);
                if (strtoupper((string) $d['state']) === 'STOP') {
                    if ($open) {
                        return ['DOWNTIME', $open->id, 'already stopped'];
                    }
                    $reason = DowntimeReason::query()->where('code', $d['reason'] ?? 'BRK')->first() ?? DowntimeReason::query()->where('code', 'OTH')->firstOrFail();
                    $ev = $floor->startDowntime($centre, $reason, null, 'Reported by '.($d['source'] ?? 'machine'));

                    return ['DOWNTIME', $ev->id, $centre->code];
                }
                if ($open) {
                    $floor->endDowntime($open, null, 'Machine reported running');

                    return ['DOWNTIME', $open->id, $centre->code];
                }

                return [null, null, 'running'];
            })(),

            'SCAN' => (function () use ($d) {
                $found = app(ShopFloor::class)->identify((string) ($d['code'] ?? ''));
                if (! $found) {
                    throw new DomainException('Nothing is known by that code.');
                }

                return [strtoupper($found['type']), null, $found['label']];
            })(),

            default => isset($this->handlers[$e->event_type]) ? ($this->handlers[$e->event_type])($i, $e, $this)
                : throw new DomainException("{$e->event_type} is not a message this integration understands."),
        };
    }

    // ── Out ──────────────────────────────────────────────────────────────────────────────────

    /** Finished output received since the integration went live, queued once each for the other system. */
    public function queueOutputExports(Integration $i): int
    {
        $n = 0;
        $since = $i->validated_at ?? $i->created_at;
        $receipts = FinStockMovement::withoutGlobalScopes()->where('company_id', $i->company_id)->where('type', 'mfg_receipt')
            ->whereNull('reversal_of_id')->where('created_at', '>=', $since)->get();
        foreach ($receipts as $m) {
            $key = "OUTPUT_RECEIPT:{$m->id}";
            if (Event::query()->where('integration_id', $i->id)->where('idempotency_key', $key)->exists()) {
                continue;
            }
            $order = PO::query()->find($m->source_id);
            Event::create(['company_id' => $i->company_id, 'integration_id' => $i->id, 'direction' => 'OUT', 'event_type' => 'OUTPUT_RECEIPT',
                'external_id' => (string) $m->id, 'idempotency_key' => $key, 'status' => 'RECEIVED', 'payload' => [
                    'product' => $this->external($i, 'PRODUCT', (int) $order?->item_id) ?? DB::table('fin_stock_items')->where('id', $m->item_id)->value('code'),
                    'warehouse' => $this->external($i, 'WAREHOUSE', (int) $m->warehouse_id), 'qty' => abs((float) $m->qty),
                    'lot' => $m->lot_id ? DB::table('fin_stock_lots')->where('id', $m->lot_id)->value('lot_number') : null,
                    'order' => $this->external($i, 'ORDER', (int) $order?->id) ?? $order?->number, 'received_at' => $m->created_at?->toIso8601String()]]);
            $n++;
        }

        return $n;
    }

    private function deliver(Integration $i, Event $e): array
    {
        $r = $this->adapter($i)->send($i, $e);
        if (! $r['ok']) {
            throw new DomainException($r['detail']);
        }

        return ['EXTERNAL', null, $r['reference'] ?: null];
    }

    // ── Scheduled work ───────────────────────────────────────────────────────────────────────

    /** One integration's sync: pull what is waiting, queue and push what is due, retry failures. */
    public function sync(Integration $i): array
    {
        $result = ['pulled' => 0, 'queued' => 0, 'sent' => 0, 'retried' => 0, 'error' => null];
        try {
            foreach ($this->adapter($i)->fetch($i) as $m) {
                $this->ingest($i, (string) $m['type'], (string) $m['id'], (array) ($m['data'] ?? []));
                $result['pulled']++;
            }
        } catch (\Throwable $e) {
            $result['error'] = $this->scrub($i, $e->getMessage());
        }
        if (in_array('OUTPUT_RECEIPT', (array) ($i->settings['export'] ?? []), true)) {
            $result['queued'] = $this->queueOutputExports($i);
        }
        foreach ($this->exporters as $name => $queue) {
            if (in_array($name, (array) ($i->settings['export'] ?? []), true)) {
                $result['queued'] += $queue($i, $this);
            }
        }
        if ($i->method === 'API') {
            foreach (Event::query()->where('integration_id', $i->id)->where('direction', 'OUT')->where('status', 'RECEIVED')->limit(200)->get() as $e) {
                $result['sent'] += $this->process($e)->status === 'PROCESSED' ? 1 : 0;
            }
        }
        foreach (Event::query()->where('integration_id', $i->id)->where('status', 'FAILED')->where('next_attempt_at', '<=', now())->limit(200)->get() as $e) {
            $this->process($e);
            $result['retried']++;
        }
        $i->update(['last_synced_at' => now(), 'last_sync_result' => json_encode($result)]);

        return $result;
    }

    /** A dead letter tried again by a person, from the start. */
    public function retry(Event $e): Event
    {
        if (! in_array($e->status, ['FAILED', 'DEAD'], true)) {
            throw new DomainException('Only a failed message is retried.');
        }
        $e->update(['attempts' => 0, 'status' => 'RECEIVED', 'next_attempt_at' => null]);

        return $this->process($e);
    }

    public function discard(Event $e, string $reason): Event
    {
        if (! in_array($e->status, ['FAILED', 'DEAD', 'RECEIVED'], true) || blank($reason)) {
            throw new DomainException('Say why the message is set aside.');
        }
        $e->update(['status' => 'SKIPPED', 'last_error' => 'Set aside: '.$reason, 'next_attempt_at' => null]);

        return $e->refresh();
    }

    /** What arrived and what became of it; what left and whether it got there. */
    public function reconcile(Integration $i): array
    {
        $events = Event::query()->where('integration_id', $i->id)->get(['direction', 'event_type', 'status', 'duplicates']);
        $since = $i->validated_at ?? $i->created_at;
        $receipts = FinStockMovement::withoutGlobalScopes()->where('company_id', $i->company_id)->where('type', 'mfg_receipt')
            ->whereNull('reversal_of_id')->where('created_at', '>=', $since)->count();

        return [
            'by_type' => $events->groupBy(fn ($e) => "{$e->direction} {$e->event_type}")->map(fn ($g, $k) => ['type' => $k] + collect(['RECEIVED', 'PROCESSED', 'FAILED', 'DEAD', 'SKIPPED'])
                ->mapWithKeys(fn ($s) => [strtolower($s) => $g->where('status', $s)->count()])->all() + ['duplicates_ignored' => (int) $g->sum('duplicates')])->values()->all(),
            'output_receipts_since_live' => $receipts,
            'output_receipts_exported' => $events->where('direction', 'OUT')->where('event_type', 'OUTPUT_RECEIPT')->where('status', 'PROCESSED')->count(),
            'mappings' => Map::query()->where('integration_id', $i->id)->get()->groupBy('entity')->map->count()->all(),
        ];
    }

    // ── Internals ────────────────────────────────────────────────────────────────────────────

    public function redact(array $data): array
    {
        foreach ($data as $k => $v) {
            if (is_string($k) && preg_match(self::SECRET_KEYS, $k)) {
                $data[$k] = '[redacted]';
            } elseif (is_array($v)) {
                $data[$k] = $this->redact($v);
            }
        }

        return $data;
    }

    private function scrub(Integration $i, string $text): string
    {
        foreach (array_merge((array) ($i->credentials ?? []), [$i->webhook_secret]) as $v) {
            if (is_string($v) && strlen($v) >= 4) {
                $text = str_replace($v, '[redacted]', $text);
            }
        }

        return $text;
    }

    private function local(Integration $i, string $entity, string $ref): int
    {
        return $this->localOrNull($i, $entity, $ref) ?? throw new DomainException("No {$entity} is mapped to \"{$ref}\".");
    }

    public function localOrNull(Integration $i, string $entity, string $ref): ?int
    {
        $id = Map::query()->where('integration_id', $i->id)->where('entity', $entity)->where('external_ref', $ref)->value('local_id');

        return $id !== null ? (int) $id : null;
    }

    public function external(Integration $i, string $entity, int $localId): ?string
    {
        return Map::query()->where('integration_id', $i->id)->where('entity', $entity)->where('local_id', $localId)->value('external_ref');
    }

    public function signatureFor(Integration $i, string $body): string
    {
        return hash_hmac('sha256', $body, (string) $i->webhook_secret);
    }

    public function newSecret(): string
    {
        return Str::random(48);
    }
}
