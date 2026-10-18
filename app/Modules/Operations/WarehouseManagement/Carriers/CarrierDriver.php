<?php

namespace App\Modules\Operations\WarehouseManagement\Carriers;

use App\Modules\Operations\WarehouseManagement\Models\Carrier;
use App\Modules\Operations\WarehouseManagement\Models\Shipment;

/**
 * What the warehouse asks of a carrier (WMS-170). A driver says which of these its carrier's API
 * supports; the screens offer only those, and everything else is recorded by a person.
 */
interface CarrierDriver
{
    /** @return array{rates: bool, booking: bool, tracking_pull: bool, labels: bool, proof_of_delivery: bool} */
    public function capabilities(): array;

    /**
     * @param  array{weight_kg: float, packages: int, zone: ?string}  $parcel
     * @return list<array{service: string, price: float, transit_days: ?int}>
     */
    public function rates(Carrier $carrier, array $parcel): array;

    /** @return array{booking_reference: string, tracking_reference: ?string} */
    public function book(Carrier $carrier, Shipment $shipment, string $service, ?string $reference): array;

    /** @return list<array{status: string, occurred_at: string, location: ?string, detail: ?string, external_id: ?string}> */
    public function track(Carrier $carrier, Shipment $shipment): array;

    /** The carrier's own label, as ZPL, or null when the warehouse prints its own. */
    public function label(Carrier $carrier, Shipment $shipment): ?string;
}
