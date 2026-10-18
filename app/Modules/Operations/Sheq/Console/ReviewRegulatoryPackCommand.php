<?php

namespace App\Modules\Operations\Sheq\Console;

use App\Modules\Operations\Sheq\Regulatory\PackAssurance;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Record that a qualified person has reviewed a regulatory pack (REG-FIX-4, REG-FIX-5).
 *
 * A command rather than a screen, deliberately. A pack version is shared product content, not
 * tenant data — the same za-ohsa 2026.1.0 governs every customer who activates it — so recording
 * its legal review is a release activity carried out by whoever ships the pack, in the same step
 * as publishing it. Putting it on a tenant screen would invite each customer to assert their own
 * review of content they did not write.
 */
class ReviewRegulatoryPackCommand extends Command
{
    protected $signature = 'sheq:packs:review
                            {version? : The pack version row id — omit to list them}
                            {--reviewer= : The reviewer\'s name}
                            {--capacity= : Their professional capacity}
                            {--reference= : Opinion or file reference}
                            {--valid-until= : Date the review is certified to (YYYY-MM-DD)}
                            {--notes= : Anything qualifying the review}
                            {--publisher= : REG-FIX-4 release metadata: who published the pack}
                            {--published-on= : When it was published}
                            {--source= : The instrument it is a reading of}
                            {--source-reference= : Gazette or instrument number}
                            {--changelog= : What changed since the previous version}';

    protected $description = 'Record a legal review and release provenance against a pack version';

    public function handle(PackAssurance $assurance): int
    {
        if ($this->argument('version') === null) {
            return $this->listVersions($assurance);
        }

        $versionId = (int) $this->argument('version');

        try {
            if ($this->option('reviewer') !== null || $this->option('capacity') !== null) {
                $assurance->recordLegalReview($versionId, [
                    'reviewer_name' => $this->option('reviewer'),
                    'reviewer_capacity' => $this->option('capacity'),
                    'reference' => $this->option('reference'),
                    'valid_until' => $this->option('valid-until'),
                    'notes' => $this->option('notes'),
                ]);
            }

            if ($this->option('publisher') !== null || $this->option('source') !== null) {
                $assurance->recordRelease($versionId, [
                    'publisher' => $this->option('publisher'),
                    'published_on' => $this->option('published-on'),
                    'source_instrument' => $this->option('source'),
                    'source_reference' => $this->option('source-reference'),
                    'changelog' => $this->option('changelog'),
                ]);
            }
        } catch (InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $result = $assurance->assess($versionId);

        $this->components->twoColumnDetail(
            "{$result['pack_id']}@{$result['version']}",
            $result['clean'] ? '<fg=green>clean</>' : '<fg=yellow>'
                .implode(', ', array_merge($result['blocking'], $result['acknowledgeable']))
                .'</>',
        );

        if ($result['legal_review'] !== null) {
            $this->line('    Reviewed by '.$result['legal_review']['reviewer_name']
                .' ('.$result['legal_review']['reviewer_capacity'].')');
        }

        return self::SUCCESS;
    }

    private function listVersions(PackAssurance $assurance): int
    {
        $rows = [];

        foreach ($assurance->all() as $id => $result) {
            $rows[] = [
                $id,
                $result['pack_id'].'@'.$result['version'],
                $result['signed'] ? ($result['signature_verified'] ? 'verified' : 'unverified')
                    : 'unsigned',
                $result['legal_review'] === null ? 'NOT REVIEWED'
                    : $result['legal_review']['reviewer_name'],
                $result['blocking'] === [] ? '' : implode(',', $result['blocking']),
            ];
        }

        if ($rows === []) {
            $this->components->warn('No regulatory pack version is installed. Run sheq:install.');

            return self::SUCCESS;
        }

        $this->table(['id', 'pack', 'signature', 'legal review', 'blocking'], $rows);

        return self::SUCCESS;
    }
}
