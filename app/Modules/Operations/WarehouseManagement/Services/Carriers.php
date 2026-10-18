<?php

namespace App\Modules\Operations\WarehouseManagement\Services;

use App\Core\User\Models\User;
use App\Modules\Operations\WarehouseManagement\Carriers\CarrierDriver;
use App\Modules\Operations\WarehouseManagement\Carriers\ManualCarrier;
use App\Modules\Operations\WarehouseManagement\Models\Carrier;
use App\Modules\Operations\WarehouseManagement\Models\FulfilmentOrder;
use App\Modules\Operations\WarehouseManagement\Models\Shipment;
use App\Modules\Operations\WarehouseManagement\Models\TrackingEvent;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Carriers (WMS-170): rates, booking, tracking, labels and proof of delivery through one driver
 * contract. MANUAL is the only driver until a carrier's API is contracted and proven; a new driver
 * is a class and a name in Carrier::DRIVERS, not a change to the screens.
 */
class Carriers
{
    public function __construct(private readonly Dispatching $dispatching) {}

    public function driver(Carrier $carrier): CarrierDriver
    {
        return match ($carrier->driver) {
            'MANUAL' => app(ManualCarrier::class),
            default => throw new RuntimeException("No driver for {$carrier->driver}."),
        };
    }

    /** Every active carrier's price for what is packed on the order (or for the packages named). */
    public function quote(FulfilmentOrder $order, ?\Illuminate\Support\Collection $packages = null): array
    {
        $packages ??= $order->packages()->whereIn('status', ['OPEN', 'CLOSED'])->get();
        $parcel = ['weight_kg' => round((float) $packages->sum('weight_kg'), 3), 'packages' => $packages->count(),
            'zone' => $order->ship_to['zone'] ?? $order->ship_to['province'] ?? null];

        return ['parcel' => $parcel, 'options' => Carrier::query()->where('is_active', true)->orderBy('name')->get()
            ->flatMap(fn (Carrier $c) => collect($this->driver($c)->capabilities()['rates'] ? $this->driver($c)->rates($c, $parcel) : [])
                ->map(fn ($r) => $r + ['carrier' => $c->uuid, 'carrier_name' => $c->name]))->sortBy('price')->values()->all()];
    }

    /** Book the shipment with the carrier; the price quoted is kept with it. */
    public function book(Shipment $shipment, Carrier $carrier, string $service, ?string $reference, ?User $user): Shipment
    {
        if (! $carrier->is_active) {
            throw new RuntimeException("{$carrier->name} is switched off.");
        }
        if ($shipment->booked_at) {
            throw new RuntimeException("{$shipment->number} is already booked with ".($shipment->carrier ?? 'a carrier').'.');
        }
        $order = $shipment->order;
        $quote = collect($this->quote($order, $shipment->packages()->get())['options'])->first(fn ($o) => $o['carrier'] === $carrier->uuid && $o['service'] === $service);
        $booked = $this->driver($carrier)->book($carrier, $shipment, $service, $reference);

        return DB::transaction(function () use ($shipment, $carrier, $service, $booked, $quote, $user) {
            $shipment->update(['carrier_id' => $carrier->id, 'carrier' => $carrier->name, 'service' => $service, 'booking_reference' => $booked['booking_reference'],
                'tracking_reference' => $booked['tracking_reference'] ?? $shipment->tracking_reference, 'booked_at' => now(), 'quoted_price' => $quote['price'] ?? null]);
            $this->track($shipment, 'BOOKED', now()->toIso8601String(), null, "Booked: {$service}", 'MANUAL', "booking:{$shipment->id}", $user);

            return $shipment->fresh();
        });
    }

    /**
     * Record what happened to a shipment. An event the carrier sends twice (same external id) is
     * kept once. Delivered records proof of delivery; failed marks the shipment failed.
     */
    public function track(Shipment $shipment, string $status, string $occurredAt, ?string $location, ?string $detail, string $source, ?string $externalId, ?User $user, ?string $receivedBy = null): ?TrackingEvent
    {
        if (! in_array($status, TrackingEvent::STATUSES, true)) {
            throw new RuntimeException("Unknown tracking status {$status}.");
        }
        if ($externalId !== null && TrackingEvent::query()->where('shipment_id', $shipment->id)->where('external_id', $externalId)->exists()) {
            return null;
        }
        if ($status === 'DELIVERED' && $shipment->status === 'DISPATCHED' && trim((string) ($receivedBy ?? '')) === '') {
            throw new RuntimeException('Record who received the goods.');
        }

        return DB::transaction(function () use ($shipment, $status, $occurredAt, $location, $detail, $source, $externalId, $user, $receivedBy) {
            $event = TrackingEvent::create(['company_id' => $shipment->company_id, 'shipment_id' => $shipment->id, 'status' => $status, 'occurred_at' => $occurredAt,
                'location' => $location, 'detail' => $detail, 'source' => $source, 'external_id' => $externalId, 'recorded_by' => $user?->id]);
            // The latest event by when it happened, not by when it arrived.
            $latest = TrackingEvent::query()->where('shipment_id', $shipment->id)->orderByDesc('occurred_at')->orderByDesc('id')->first();
            $shipment->update(['tracking_status' => $latest->status, 'tracked_at' => now()]);
            if ($status === 'DELIVERED' && $shipment->status === 'DISPATCHED') {
                $this->dispatching->recordDelivery($shipment->fresh(), (string) $receivedBy, $occurredAt, null, $detail);
            } elseif ($status === 'FAILED' && $shipment->status === 'DISPATCHED') {
                $shipment->update(['status' => 'FAILED']);
            }

            return $event;
        });
    }

    /** Pull tracking from carriers whose API offers it. */
    public function poll(): int
    {
        $n = 0;
        Shipment::query()->where('status', 'DISPATCHED')->whereNotNull('carrier_id')->with('carrierRecord')->get()
            ->filter(fn (Shipment $s) => $s->carrierRecord && $this->driver($s->carrierRecord)->capabilities()['tracking_pull'])
            ->each(function (Shipment $s) use (&$n) {
                foreach ($this->driver($s->carrierRecord)->track($s->carrierRecord, $s) as $e) {
                    $n += $this->track($s, $e['status'], $e['occurred_at'], $e['location'], $e['detail'], 'CARRIER', $e['external_id'], null) ? 1 : 0;
                }
            });

        return $n;
    }
}
