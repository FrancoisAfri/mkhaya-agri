<?php

namespace App\Modules\SupplierManagement\Console;

use App\Modules\SupplierManagement\DueDiligence\Support\CheckType;
use App\Modules\SupplierManagement\Models\DueDiligenceProvider;
use Illuminate\Console\Command;

/**
 * Install the call profile for one verification source, from a JSON file.
 *
 *   php artisan suppliers:providers:profile searchworks path/to/searchworks.json
 *   php artisan suppliers:providers:profile treasury_csd csd.json --source="OCPO web service spec v3"
 *
 * A profile is the half of an integration we can know once: for each check the source answers, the
 * endpoint, the method, the placeholders filled from the supplier, and the map from the response
 * into our fields. With one installed, a customer enables the source, pastes their credentials, and
 * it works — which is the whole point, because today they are asked for an endpoint and a field map
 * on a settings screen, and that is a developer's job wearing a buyer's hat.
 *
 * Deliberately a file rather than code. A profile can only be written against a provider's real
 * documentation, and every contract differs — a tier that returns directors inline and one that
 * returns a link are the same product. Taking it as data means a new source is a file somebody
 * writes from their own API documentation, not a release.
 *
 * The file:
 *
 *   {
 *     "checks": {
 *       "company.registry": {
 *         "endpoint": "https://api.example.test/company/{registration_number}",
 *         "method": "GET",
 *         "query": { "key": "{api_key}" },
 *         "map": { "company_name": "data.registeredName", "status": "data.status" },
 *         "found_path": "data.registrationNumber"
 *       }
 *     }
 *   }
 */
class InstallProviderProfileCommand extends Command
{
    protected $signature = 'suppliers:providers:profile
                            {code : The catalogue code, e.g. searchworks}
                            {file : A JSON file describing the calls}
                            {--source= : Which document this was written from}
                            {--dry-run : Validate the file and write nothing}';

    protected $description = 'Install the shipped call profile for a verification source';

    public function handle(): int
    {
        $code = (string) $this->argument('code');
        $path = (string) $this->argument('file');

        $provider = DueDiligenceProvider::query()->where('code', $code)->first();

        if (! $provider) {
            $this->components->error("No source in the catalogue with the code '{$code}'.");

            return self::FAILURE;
        }

        if (! is_file($path)) {
            $this->components->error("No such file: {$path}");

            return self::FAILURE;
        }

        $profile = json_decode((string) file_get_contents($path), true);

        if (! is_array($profile)) {
            $this->components->error('That file is not valid JSON.');

            return self::FAILURE;
        }

        $problems = $this->problemsWith($profile, $provider);

        if ($problems !== []) {
            $this->components->error('The profile was not installed:');
            foreach ($problems as $problem) {
                $this->line('   '.$problem);
            }

            return self::FAILURE;
        }

        $checks = array_keys($profile['checks']);
        $this->components->info(count($checks).' check(s): '.implode(', ', $checks));

        if ($this->option('dry-run')) {
            $this->components->info('Valid. Nothing written (dry run).');

            return self::SUCCESS;
        }

        $provider->update([
            'profile' => $profile,
            'profile_source' => $this->option('source') ?: basename($path),
        ]);

        $this->components->info("Installed for {$provider->name}. Customers now supply only credentials.");

        return self::SUCCESS;
    }

    /**
     * Refuse a profile that would install cleanly and then call nothing.
     *
     * The failure this guards against is specific: a connector that is configured, enabled, reports
     * itself healthy, and silently answers no check — which reads to everybody as "the provider is
     * down" rather than as a typo in a key.
     *
     * @return list<string>
     */
    private function problemsWith(array $profile, DueDiligenceProvider $provider): array
    {
        $problems = [];
        $checks = $profile['checks'] ?? null;

        if (! is_array($checks) || $checks === []) {
            return ['The file has no "checks" object, so it describes no calls.'];
        }

        $capabilities = (array) ($provider->capabilities ?? []);

        foreach ($checks as $check => $spec) {
            if (! CheckType::exists((string) $check)) {
                $problems[] = "\"{$check}\" is not a check type this system knows.";

                continue;
            }

            if ($capabilities !== [] && ! in_array($check, $capabilities, true)) {
                // The catalogue is what the registry consults when choosing a source, so a profile
                // for a check the catalogue does not list would never be reached.
                $problems[] = "\"{$check}\" is not among this source's capabilities, so it would never be used.";
            }

            if (! is_array($spec) || blank($spec['endpoint'] ?? null)) {
                $problems[] = "\"{$check}\" has no endpoint.";

                continue;
            }

            if (! is_array($spec['map'] ?? null) || $spec['map'] === []) {
                // Without a map the call is made and the answer is discarded, which is worse than
                // not calling: it costs a paid lookup to learn nothing.
                $problems[] = "\"{$check}\" has no field map, so nothing would be read from the reply.";
            }
        }

        return $problems;
    }
}
