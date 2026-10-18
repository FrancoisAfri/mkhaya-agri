<?php

namespace App\Modules\Fleet\Integrations;

use App\Modules\Fleet\Integrations\Adapters\AbstractFleetAdapter;
use App\Modules\Fleet\Integrations\Adapters\Fines\DigiTrafficAdapter;
use App\Modules\Fleet\Integrations\Adapters\Fines\IntellicheckAdapter;
use App\Modules\Fleet\Integrations\Adapters\Fines\ManualFineAdapter;
use App\Modules\Fleet\Integrations\Adapters\Fines\PaabAdapter;
use App\Modules\Fleet\Integrations\Adapters\FuelCard\FleetCardSAAdapter;
use App\Modules\Fleet\Integrations\Adapters\FuelCard\WexAdapter;
use App\Modules\Fleet\Integrations\Adapters\Telematics\CartrackAdapter;
use App\Modules\Fleet\Integrations\Adapters\Telematics\GeotabAdapter;
use App\Modules\Fleet\Integrations\Adapters\Telematics\MixTelematicsAdapter;
use App\Modules\Fleet\Integrations\Adapters\Tollgate\SanralEtanAdapter;
use Illuminate\Support\Facades\DB;

class FleetIntegrationManager
{
    /** Map provider key → adapter class */
    private array $adapters = [
        // Telematics
        'mix_telematics' => MixTelematicsAdapter::class,
        'cartrack'       => CartrackAdapter::class,
        'geotab'         => GeotabAdapter::class,
        // Fuel card
        'wex'            => WexAdapter::class,
        'fleetcard'      => FleetCardSAAdapter::class,
        // Tollgate
        'sanral_etoll'   => SanralEtanAdapter::class,
        // Custom providers fall back to null (logged as unsupported)
        'custom_fuel'    => null,
        'bakwena'        => null,
        'custom_toll'    => null,
        // Fines / AARTO
        'intellicheck'   => IntellicheckAdapter::class,
        'digitraffic'    => DigiTrafficAdapter::class,
        'paab'           => PaabAdapter::class,
        'manual_fine'    => ManualFineAdapter::class,
    ];

    /**
     * Resolve adapter for a given integration record.
     * Returns null if provider has no adapter yet.
     */
    public function resolveAdapter(object $record): ?AbstractFleetAdapter
    {
        $class = $this->adapters[$record->provider] ?? null;

        if (!$class) return null;

        return new $class($record);
    }

    /**
     * Run sync for all active integrations.
     * Returns summary array.
     */
    public function syncAll(): array
    {
        $results   = [];
        $active    = DB::table('fleet_integrations')->where('active', true)->get();

        foreach ($active as $record) {
            $adapter = $this->resolveAdapter($record);

            if (!$adapter) {
                DB::table('fleet_integrations')->where('id', $record->id)->update([
                    'last_sync_status'  => 'pending',
                    'last_sync_message' => 'Provider "' . $record->provider . '" adapter not yet implemented.',
                    'last_sync_at'      => now(),
                    'updated_at'        => now(),
                ]);
                $results[] = ['provider' => $record->provider, 'status' => 'skipped'];
                continue;
            }

            $result      = $adapter->sync();
            $result['provider'] = $record->provider;
            $results[]   = $result;
        }

        return $results;
    }

    /**
     * Sync a single integration by its DB record ID.
     */
    public function syncOne(int $integrationId): array
    {
        $record  = DB::table('fleet_integrations')->where('id', $integrationId)->first();
        if (!$record) return ['error' => 'Integration not found.'];

        $adapter = $this->resolveAdapter($record);
        if (!$adapter) return ['error' => 'No adapter for provider: ' . $record->provider];

        return $adapter->sync();
    }

    /**
     * Test connection for a single integration.
     */
    public function testOne(int $integrationId): array
    {
        $record  = DB::table('fleet_integrations')->where('id', $integrationId)->first();
        if (!$record) return ['success' => false, 'message' => 'Integration not found.'];

        $adapter = $this->resolveAdapter($record);
        if (!$adapter) {
            return ['success' => false, 'message' => 'No adapter implemented for provider "' . $record->provider . '" yet.'];
        }

        $result = $adapter->testConnection();

        DB::table('fleet_integrations')->where('id', $integrationId)->update([
            'last_sync_status'  => $result['success'] ? 'success' : 'error',
            'last_sync_message' => $result['message'],
            'last_sync_at'      => now(),
            'updated_at'        => now(),
        ]);

        return $result;
    }
}
