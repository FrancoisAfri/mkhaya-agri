<?php

namespace App\Modules\Operations\WarehouseManagement\Carriers;

use App\Modules\Operations\WarehouseManagement\Models\Carrier;
use App\Modules\Operations\WarehouseManagement\Models\Shipment;

/**
 * A carrier with no API contracted: rates come from the rate card kept here, the booking reference
 * is the carrier's waybill as somebody enters it, tracking and proof of delivery are recorded by a
 * person, and the warehouse prints its own dispatch label.
 */
class ManualCarrier implements CarrierDriver
{
    public function capabilities(): array
    {
        return ['rates' => true, 'booking' => true, 'tracking_pull' => false, 'labels' => false, 'proof_of_delivery' => false];
    }

    public function rates(Carrier $carrier, array $parcel): array
    {
        return $carrier->rates()->get()
            ->filter(fn ($r) => $r->zone === null || ($parcel['zone'] !== null && strcasecmp($r->zone, (string) $parcel['zone']) === 0))
            ->filter(fn ($r) => $r->max_weight_kg + 1e-9 >= $parcel['weight_kg'])
            ->groupBy('service')
            // The narrowest band that takes the weight; a zone's own rate before an anywhere rate.
            ->map(fn ($g) => $g->sortBy([fn ($a, $b) => ($a->zone === null) <=> ($b->zone === null), fn ($a, $b) => $a->max_weight_kg <=> $b->max_weight_kg])->first())
            ->map(fn ($r) => ['service' => $r->service, 'price' => (float) $r->price, 'transit_days' => $r->transit_days])
            ->sortBy('price')->values()->all();
    }

    public function book(Carrier $carrier, Shipment $shipment, string $service, ?string $reference): array
    {
        return ['booking_reference' => $reference ?: $shipment->number, 'tracking_reference' => $reference ?: $shipment->tracking_reference];
    }

    public function track(Carrier $carrier, Shipment $shipment): array
    {
        return [];
    }

    public function label(Carrier $carrier, Shipment $shipment): ?string
    {
        return null;
    }
}
