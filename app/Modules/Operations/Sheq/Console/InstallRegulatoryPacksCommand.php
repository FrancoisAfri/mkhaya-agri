<?php

namespace App\Modules\Operations\Sheq\Console;

use App\Modules\Operations\Sheq\Regulatory\PackLoader;
use App\Modules\Operations\Sheq\Regulatory\PackManifest;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Installs Regulatory Pack versions from disk (SHEQ-REG-8: pack updates without a code deploy).
 *
 *   php artisan sheq:packs:install                    # install/refresh every shipped pack
 *   php artisan sheq:packs:install za-ohsa            # one pack
 *   php artisan sheq:packs:install --require-signature
 *
 * Installing a pack does not activate it: a tenant activates a version per site (SHEQ-REG-1), and
 * historical records keep being judged by whichever version was effective when they occurred
 * (SHEQ-REG-2). That separation is why re-running this command is safe.
 *
 * An existing version whose checksum still matches is left alone. One whose contents have changed
 * is refused: versions are immutable, because an incident classified last month cites a version
 * number, and silently rewriting what that number means destroys the audit trail. Ship a new
 * version instead.
 */
class InstallRegulatoryPacksCommand extends Command
{
    protected $signature = 'sheq:packs:install
                            {pack? : Pack id (directory name) — omit to install all}
                            {--require-signature : Refuse packs that are unsigned or fail verification}
                            {--path= : Override the pack source directory}';

    protected $description = 'Install or refresh SHEQ Regulatory Pack versions from disk';

    public function handle(PackLoader $loader): int
    {
        $root = $this->option('path') ?: resource_path('regulatory-packs');
        $only = $this->argument('pack');

        $directories = $only ? ["{$root}/{$only}"] : $this->packDirectories($root);

        if ($directories === []) {
            $this->components->error("No regulatory packs found under {$root}");

            return self::FAILURE;
        }

        $rows = [];
        $failed = false;

        foreach ($directories as $directory) {
            try {
                $manifest = $loader->load($directory);
            } catch (\Throwable $e) {
                $this->components->error(basename($directory).': '.$e->getMessage());
                $failed = true;

                continue;
            }

            $signature = $loader->verifySignature($directory, $manifest);

            if ($this->option('require-signature') && ! $signature['verified']) {
                $this->components->error("{$manifest->packId}: {$signature['reason']}");
                $failed = true;

                continue;
            }

            if (! $signature['verified']) {
                $this->components->warn("{$manifest->packId}@{$manifest->version}: unsigned ({$signature['reason']})");
            }

            $rows[] = $this->install($manifest, $signature['verified']);
        }

        if ($rows !== []) {
            $this->table(['Pack', 'Version', 'Jurisdiction', 'Rules', 'Obligations', 'Signed', 'Result'], $rows);
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Installing a pack row and its version is one unit of work: a pack registered without the
     * version it was registered for is a half-installed rulebook, and the retry would then see the
     * pack already present and be harder to reason about.
     *
     * @return array<int,string>
     */
    private function install(PackManifest $manifest, bool $signatureVerified): array
    {
        return DB::transaction(fn () => $this->installWithinTransaction($manifest, $signatureVerified));
    }

    /** @return array<int,string> */
    private function installWithinTransaction(PackManifest $manifest, bool $signatureVerified): array
    {
        $checksum = $manifest->checksum();

        $packId = DB::table('sheq_regulatory_packs')->where('pack_id', $manifest->packId)->value('id');

        if ($packId === null) {
            $packId = DB::table('sheq_regulatory_packs')->insertGetId([
                'pack_id' => $manifest->packId,
                'jurisdiction' => $manifest->jurisdiction(),
                'name' => $manifest->label(),
                'description' => $manifest->manifest['description'] ?? null,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            DB::table('sheq_regulatory_packs')->where('id', $packId)->update([
                'jurisdiction' => $manifest->jurisdiction(),
                'name' => $manifest->label(),
                'description' => $manifest->manifest['description'] ?? null,
                'updated_at' => now(),
            ]);
        }

        $existing = DB::table('sheq_regulatory_pack_versions')
            ->where('pack_id', $packId)
            ->where('version', $manifest->version)
            ->first();

        $summary = [
            $manifest->packId,
            $manifest->version,
            $manifest->jurisdiction(),
            (string) count($manifest->classificationRules()),
            (string) count($manifest->obligations()),
            $signatureVerified ? 'yes' : 'no',
        ];

        if ($existing && $existing->checksum === $checksum) {
            return [...$summary, 'unchanged'];
        }

        if ($existing) {
            // Immutability is the point (SHEQ-REG-2). Refuse, name the fix, change nothing.
            $this->components->error(
                "{$manifest->packId}@{$manifest->version} already exists with different contents. "
                .'Published versions are immutable — publish a new version instead.'
            );

            return [...$summary, 'REFUSED (contents changed)'];
        }

        DB::table('sheq_regulatory_pack_versions')->insert([
            'pack_id' => $packId,
            'version' => $manifest->version,
            'effective_from' => $manifest->manifest['effective_from'] ?? now()->toDateString(),
            'effective_to' => $manifest->manifest['effective_to'] ?? null,
            'manifest' => json_encode($manifest->manifest),
            'checksum' => $checksum,
            'signature_verified' => $signatureVerified,
            'installed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [...$summary, 'installed'];
    }

    /** @return list<string> */
    private function packDirectories(string $root): array
    {
        if (! is_dir($root)) {
            return [];
        }

        $directories = [];
        foreach (scandir($root) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..' && is_dir("{$root}/{$entry}")) {
                $directories[] = "{$root}/{$entry}";
            }
        }

        return $directories;
    }
}
