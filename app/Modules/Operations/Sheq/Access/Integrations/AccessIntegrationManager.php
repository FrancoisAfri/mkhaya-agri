<?php

namespace App\Modules\Operations\Sheq\Access\Integrations;

use App\Modules\Fleet\Integrations\FleetIntegrationManager;
use App\Modules\Operations\Sheq\Access\Integrations\Adapters\AbstractAccessAdapter;
use App\Modules\Operations\Sheq\Access\Integrations\Adapters\AccessControl\GallagherAdapter;
use App\Modules\Operations\Sheq\Access\Integrations\Adapters\AccessControl\ImproAdapter;
use App\Modules\Operations\Sheq\Access\Integrations\Adapters\AccessControl\SupremaAdapter;
use App\Modules\Operations\Sheq\Access\Integrations\Adapters\AccessControl\ZktecoAdapter;
use App\Modules\Operations\Sheq\Access\Integrations\Adapters\ClockIn\ErsBiometricsAdapter;
use App\Modules\Operations\Sheq\Access\Integrations\Adapters\ClockIn\JarrisonAdapter;
use App\Modules\Operations\Sheq\Access\Integrations\Adapters\ClockIn\UnicloxAdapter;
use App\Modules\Operations\Sheq\Access\Integrations\Adapters\Generic\GenericRestAdapter;
use App\Modules\Operations\Sheq\Access\Integrations\Adapters\Generic\WebhookAdapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Resolves and drives the access-control and clock-in connections.
 *
 * Modelled directly on {@see FleetIntegrationManager}, down to the
 * provider-key map. An administrator who has configured a telematics feed on this platform should
 * find nothing new here — same shape of record, same test-then-sync flow, same failure reporting.
 * Copying a working local convention is worth more than a marginally better one nobody recognises.
 *
 * The intent stated in the brief is the design constraint: **enter credentials and API details, and
 * it works.** That means no per-customer code. A new site is a row in `sheq_integrations`; a new
 * *vendor* is one adapter class registered in the map below. Where a vendor is not on the list,
 * `generic_rest` and `webhook` cover almost everything else — most access controllers will either
 * poll a REST endpoint or post an event, and the two generic adapters are configured entirely from
 * `additional_config`.
 */
class AccessIntegrationManager
{
    /**
     * Provider key → adapter class.
     *
     * The South African access-control and time-and-attendance vendors a customer is most likely to
     * already own. Impro and ZKTeco dominate mid-market sites; Gallagher and Suprema appear on
     * larger mines and industrial estates; ERS, Uniclox and Jarrison are the common clock-in
     * systems payroll already reads from.
     */
    private const ADAPTERS = [
        // Access control / turnstiles / booms
        'impro' => ImproAdapter::class,
        'zkteco' => ZktecoAdapter::class,
        'suprema' => SupremaAdapter::class,
        'gallagher' => GallagherAdapter::class,

        // Clock-in / time and attendance
        'ers_biometrics' => ErsBiometricsAdapter::class,
        'uniclox' => UnicloxAdapter::class,
        'jarrison' => JarrisonAdapter::class,

        // Anything else: configured, not coded.
        'generic_rest' => GenericRestAdapter::class,
        'webhook' => WebhookAdapter::class,
    ];

    /** What the configuration screen offers, with the fields each one needs. */
    public function catalogue(): array
    {
        return [
            ['provider' => 'impro', 'label' => 'Impro Access', 'type' => 'ACCESS_CONTROL',
                'fields' => ['api_endpoint', 'api_key', 'api_secret'],
                'config' => ['site_code' => 'Impro site code', 'reader_group' => 'Reader group']],
            ['provider' => 'zkteco', 'label' => 'ZKTeco', 'type' => 'ACCESS_CONTROL',
                'fields' => ['api_endpoint', 'api_key', 'api_secret'],
                'config' => ['device_serials' => 'Comma-separated device serials']],
            ['provider' => 'suprema', 'label' => 'Suprema BioStar 2', 'type' => 'ACCESS_CONTROL',
                'fields' => ['api_endpoint', 'api_key', 'api_secret'],
                'config' => ['device_group_id' => 'Device group id']],
            ['provider' => 'gallagher', 'label' => 'Gallagher Command Centre', 'type' => 'ACCESS_CONTROL',
                'fields' => ['api_endpoint', 'api_key'],
                'config' => ['division' => 'Division href', 'access_group' => 'Access group href']],
            ['provider' => 'ers_biometrics', 'label' => 'ERS Biometrics', 'type' => 'CLOCK_IN',
                'fields' => ['api_endpoint', 'api_key', 'api_secret'],
                'config' => ['branch_code' => 'Branch code']],
            ['provider' => 'uniclox', 'label' => 'Uniclox', 'type' => 'CLOCK_IN',
                'fields' => ['api_endpoint', 'api_key'], 'config' => []],
            ['provider' => 'jarrison', 'label' => 'Jarrison Time', 'type' => 'CLOCK_IN',
                'fields' => ['api_endpoint', 'api_key'], 'config' => ['db_name' => 'Database name']],
            ['provider' => 'generic_rest', 'label' => 'Generic REST endpoint', 'type' => 'ACCESS_CONTROL',
                'fields' => ['api_endpoint', 'api_key'],
                'config' => [
                    'events_path' => 'Path returning events, e.g. /api/events',
                    'identifier_field' => 'Field holding the card or ID number',
                    'timestamp_field' => 'Field holding the event time',
                    'auth_header' => 'Header name for the key, default Authorization',
                ]],
            ['provider' => 'webhook', 'label' => 'Inbound webhook (vendor posts to us)',
                'type' => 'ACCESS_CONTROL', 'fields' => [],
                'config' => ['identifier_field' => 'JSON key holding the identifier']],
        ];
    }

    public function resolveAdapter(object $record): ?AbstractAccessAdapter
    {
        $class = self::ADAPTERS[$record->provider] ?? null;

        if ($class === null) {
            Log::warning('SHEQ access integration has no adapter', [
                'provider' => $record->provider, 'integration' => $record->id,
            ]);

            return null;
        }

        return new $class($record);
    }

    /** Prove the credentials work, without changing anything. */
    public function testOne(int $companyId, string $uuid): array
    {
        $record = $this->find($companyId, $uuid);
        $adapter = $this->resolveAdapter($record);

        if ($adapter === null) {
            return ['ok' => false, 'message' => "No adapter for provider '{$record->provider}'."];
        }

        $result = $adapter->testConnection();

        DB::table('sheq_integrations')->where('id', $record->id)->update([
            'last_sync_status' => $result['ok'] ? 'TEST_OK' : 'TEST_FAILED',
            'last_sync_message' => $result['message'] ?? null,
            'updated_at' => now(),
        ]);

        return $result;
    }

    /**
     * Pull events from one connection.
     *
     * A failure is recorded on the row rather than thrown, because these run on a schedule across
     * many tenants and one vendor's outage must not stop the rest.
     */
    public function syncOne(int $companyId, string $uuid): array
    {
        $record = $this->find($companyId, $uuid);

        return $this->runSync($record);
    }

    /** Every active connection for a company. */
    public function syncAll(int $companyId): array
    {
        $results = [];

        $records = DB::table('sheq_integrations')
            ->where('company_id', $companyId)->where('active', true)
            ->whereNull('deleted_at')->get();

        foreach ($records as $record) {
            $results[$record->uuid] = $this->runSync($record);
        }

        return $results;
    }

    private function runSync(object $record): array
    {
        $adapter = $this->resolveAdapter($record);

        if ($adapter === null) {
            return ['ok' => false, 'events' => 0,
                'message' => "No adapter for provider '{$record->provider}'."];
        }

        try {
            $result = $adapter->sync();
            $status = $result['ok'] ? 'OK' : 'FAILED';
            $message = $result['message'] ?? null;
        } catch (\Throwable $e) {
            // One vendor being down must not abort the sweep for every other tenant.
            Log::error('SHEQ access sync failed', ['integration' => $record->id, 'exception' => $e]);
            $result = ['ok' => false, 'events' => 0, 'message' => $e->getMessage()];
            $status = 'FAILED';
            $message = $e->getMessage();
        }

        DB::table('sheq_integrations')->where('id', $record->id)->update([
            'last_sync_status' => $status,
            'last_sync_message' => $message,
            'last_sync_at' => now(),
            'updated_at' => now(),
        ]);

        return $result;
    }

    private function find(int $companyId, string $uuid): object
    {
        $record = DB::table('sheq_integrations')
            ->where('company_id', $companyId)->where('uuid', $uuid)
            ->whereNull('deleted_at')->first();

        if ($record === null) {
            throw new RuntimeException("No integration {$uuid} in this company.");
        }

        return $record;
    }

    /** @return list<string> */
    public function providers(): array
    {
        return array_keys(self::ADAPTERS);
    }
}
