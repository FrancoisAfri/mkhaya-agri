<?php

namespace App\Modules\Fleet\Integrations\Adapters;

use App\Modules\Fleet\Integrations\Contracts\FleetIntegrationContract;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

abstract class AbstractFleetAdapter implements FleetIntegrationContract
{
    protected array  $config    = [];
    protected int    $recordId;
    protected string $provider;

    public function __construct(object $integrationRecord)
    {
        $this->recordId = $integrationRecord->id;
        $this->provider = $integrationRecord->provider;

        $extra = json_decode($integrationRecord->additional_config ?? '{}', true) ?? [];

        $this->config = array_merge($extra, [
            'api_endpoint' => $integrationRecord->api_endpoint ?? '',
            'api_key'      => $this->decrypt($integrationRecord->api_key),
            'api_secret'   => $this->decrypt($integrationRecord->api_secret),
        ]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    protected function decrypt(?string $value): string
    {
        if (!$value) return '';
        try { return Crypt::decryptString($value); } catch (\Throwable) { return ''; }
    }

    protected function endpoint(string $path = ''): string
    {
        return rtrim($this->config['api_endpoint'] ?? '', '/') . $path;
    }

    protected function log(string $message, array $context = []): void
    {
        Log::channel('stack')->info("[FleetIntegration:{$this->provider}] {$message}", $context);
    }

    protected function recordSyncResult(string $status, string $message): void
    {
        DB::table('fleet_integrations')->where('id', $this->recordId)->update([
            'last_sync_status'  => $status,
            'last_sync_message' => $message,
            'last_sync_at'      => now(),
            'updated_at'        => now(),
        ]);
    }

    /** Look up vehicle_id by provider's external vehicle identifier (registration or external ID). */
    protected function resolveVehicleId(string $registration): ?int
    {
        return DB::table('fleet_vehicles')
            ->whereNull('deleted_at')
            ->where(function ($q) use ($registration) {
                $q->where('registration_number', $registration)
                  ->orWhereRaw('LOWER(registration_number) = ?', [strtolower($registration)]);
            })
            ->value('id');
    }

    /** Look up driver_id by user id. */
    protected function resolveDriverId(int $userId): ?int
    {
        return DB::table('fleet_drivers')->where('user_id', $userId)->whereNull('deleted_at')->value('id');
    }

    /** Look up fleet_card id for a vehicle. */
    protected function resolveFleetCardId(int $vehicleId): ?int
    {
        return DB::table('fleet_cards')
            ->where('vehicle_id', $vehicleId)
            ->where('status', 'active')
            ->value('id');
    }

    /** Upsert a telematics position record for a vehicle. */
    protected function upsertPosition(array $data): void
    {
        DB::table('fleet_telematics_positions')->upsert(
            array_merge($data, ['updated_at' => now(), 'created_at' => now()]),
            ['vehicle_id', 'provider'],
            ['latitude', 'longitude', 'speed_kmh', 'heading', 'odometer_km',
             'ignition_status', 'address', 'recorded_at', 'updated_at']
        );
    }

    /** Insert a telematics event, skipping duplicates by provider_event_id. */
    protected function insertEvent(array $data): bool
    {
        try {
            DB::table('fleet_telematics_events')->insertOrIgnore(
                array_merge($data, ['created_at' => now(), 'updated_at' => now()])
            );
            return true;
        } catch (\Throwable $e) {
            $this->log('Event insert error: ' . $e->getMessage());
            return false;
        }
    }

    /** Insert a fuel card transaction, skipping if reference already exists. */
    protected function insertFuelTransaction(array $data): bool
    {
        if (isset($data['reference'])) {
            $exists = DB::table('fleet_card_transactions')
                ->where('reference', $data['reference'])
                ->exists();
            if ($exists) return false;
        }
        try {
            DB::table('fleet_card_transactions')->insert(
                array_merge($data, ['created_at' => now(), 'updated_at' => now()])
            );
            return true;
        } catch (\Throwable $e) {
            $this->log('Fuel transaction insert error: ' . $e->getMessage());
            return false;
        }
    }

    /** Insert a tollgate record, skipping obvious duplicates. */
    protected function insertTollgate(array $data): bool
    {
        // Skip if same vehicle + amount + timestamp already exists
        $exists = DB::table('fleet_booking_tollgates')
            ->where('vehicle_id', $data['vehicle_id'])
            ->where('amount', $data['amount'])
            ->where('toll_at', $data['toll_at'])
            ->exists();
        if ($exists) return false;

        try {
            DB::table('fleet_booking_tollgates')->insert(
                array_merge($data, ['created_at' => now(), 'updated_at' => now()])
            );
            return true;
        } catch (\Throwable $e) {
            $this->log('Tollgate insert error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Insert a fine record, deduplicating by (provider, provider_fine_id).
     * Returns true if inserted, false if duplicate or error.
     */
    protected function insertFine(array $data): bool
    {
        if (isset($data['provider_fine_id'])) {
            $exists = DB::table('fleet_fines')
                ->where('provider', $data['provider'] ?? $this->provider)
                ->where('provider_fine_id', $data['provider_fine_id'])
                ->exists();
            if ($exists) return false;
        }
        try {
            DB::table('fleet_fines')->insert(
                array_merge(['provider' => $this->provider, 'created_at' => now(), 'updated_at' => now()], $data)
            );
            return true;
        } catch (\Throwable $e) {
            $this->log('Fine insert error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Find the booking (driver_id + booking_id) that was active for a vehicle
     * at a given offence timestamp.  Returns ['driver_id' => ?, 'booking_id' => ?].
     */
    protected function resolveBookingForVehicle(int $vehicleId, string $offenceAt): array
    {
        $booking = DB::table('fleet_bookings')
            ->where('vehicle_id', $vehicleId)
            ->where('status', '!=', 'cancelled')
            ->where('start_datetime', '<=', $offenceAt)
            ->where(function ($q) use ($offenceAt) {
                $q->whereNull('end_datetime')
                  ->orWhere('end_datetime', '>=', $offenceAt);
            })
            ->orderByDesc('start_datetime')
            ->select('id', 'driver_id')
            ->first();

        return [
            'booking_id' => $booking?->id,
            'driver_id'  => $booking?->driver_id,
        ];
    }
}
