<?php

namespace App\Modules\Operations\Sheq\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Install every shared SHEQ library in one go: regulatory packs, inspection templates, inductions.
 *
 * These three are not tenant data. They are the content the module ships with, read from
 * `resources/` into tables every tenant then draws on, and each of the three underlying commands
 * skips content whose checksum has not changed — so running this repeatedly costs two YAML reads
 * and nothing else.
 *
 * It exists because the three were only ever run by hand, and nothing ran them. A deployment that
 * nobody remembered to run them on held no regulatory packs at all, which meant no tenant could
 * activate one, which meant **no incident could be reported** on that installation — the module was
 * inert and the first symptom was a customer getting a 500 while filing an injury.
 *
 * So it is wired in two places: after migrations (a deploy runs those), and nightly. Neither is
 * clever; between them a fresh install is usable without anybody remembering anything.
 */
class InstallLibrariesCommand extends Command
{
    protected $signature = 'sheq:install
                            {--force : Reinstall even where content is unchanged}';

    protected $description = 'Install the SHEQ regulatory packs, inspection templates and inductions';

    public function handle(): int
    {
        if (! Schema::hasTable('sheq_regulatory_pack_versions')) {
            // The module's migrations have not run on this database yet. Not a failure: on a fresh
            // install this fires between migration batches, and the next run finds the tables.
            $this->components->info('SHEQ tables are not present yet; nothing to install.');

            return self::SUCCESS;
        }

        $failed = false;

        foreach ([
            'sheq:packs:install' => 'Regulatory packs',
            'sheq:templates:install' => 'Inspection templates',
            'sheq:inductions:install' => 'Inductions',
        ] as $command => $label) {
            $arguments = $this->option('force') && $command === 'sheq:inductions:install'
                ? ['--force' => true]
                : [];

            $this->components->task($label, function () use ($command, $arguments, &$failed) {
                $code = $this->callSilently($command, $arguments);

                if ($code !== self::SUCCESS) {
                    $failed = true;
                }

                return $code === self::SUCCESS;
            });
        }

        $this->newLine();
        $this->components->info(sprintf(
            'SHEQ libraries: %d regulatory pack version(s), %d inspection template(s), %d induction(s) installed.',
            DB::table('sheq_regulatory_pack_versions')->count(),
            Schema::hasTable('sheq_inspection_templates') ? DB::table('sheq_inspection_templates')->count() : 0,
            Schema::hasTable('sheq_inductions') ? DB::table('sheq_inductions')->count() : 0,
        ));

        /*
         * Installing is not activating.
         *
         * A pack version on disk governs nobody until a tenant activates it for their company or a
         * site, and that stays a deliberate act — which rulebook a company is judged by is their
         * decision, not a deployment's. Said here so the person who runs this does not assume the
         * module is now live for everybody.
         */
        if (DB::table('sheq_regulatory_pack_versions')->exists()
            && ! DB::table('sheq_regulatory_pack_activations')
                ->where('status', 'ACCEPTED')->whereNull('deactivated_at')->exists()) {
            $this->components->warn(
                'No company has activated a regulatory pack yet. Until one does, incidents cannot '
                .'be reported — activate one under SHEQ → Regulatory Packs.'
            );
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
