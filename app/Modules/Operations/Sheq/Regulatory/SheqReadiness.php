<?php

namespace App\Modules\Operations\Sheq\Regulatory;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Whether this installation of SHEQ can actually be used (REG-FIX-2).
 *
 * A fresh deployment of this module installs clean, migrates clean, shows every screen, and
 * refuses the first incident somebody tries to report — because the regulatory packs are on disk
 * and nothing has activated one. The failure arrives at the worst possible moment: a safety
 * officer typing up a real injury, at which point the system is not a compliance tool, it is an
 * obstacle.
 *
 * REG-FIX-2 asks for a supported installation flow. This is the half of it that cannot be
 * automated: which rulebook a company is judged by is their decision and must stay a deliberate
 * act. What can be automated is telling them, before they need it, exactly what is missing and
 * the one thing that fixes each item.
 *
 * Every check says whether it blocks. A blocker means a core workflow is refused, not that
 * something is unconfigured — the distinction is what stops the list becoming a wall of advice
 * nobody reads.
 */
class SheqReadiness
{
    public function __construct(private readonly PackAssurance $assurance) {}

    /**
     * @return array{ready:bool, blockers:int, warnings:int, checks:list<array<string,mixed>>}
     */
    public function check(?int $companyId = null): array
    {
        if (! Schema::hasTable('sheq_regulatory_pack_versions')) {
            return [
                'ready' => false, 'blockers' => 1, 'warnings' => 0,
                'checks' => [[
                    'key' => 'migrations',
                    'label' => 'SHEQ tables',
                    'ok' => false, 'blocking' => true,
                    'detail' => 'The module\'s migrations have not run on this database.',
                    'fix' => 'php artisan migrate',
                ]],
            ];
        }

        $checks = [];

        // ── Content on disk, installed ──────────────────────────────────────────────────────
        $packVersions = DB::table('sheq_regulatory_pack_versions')->count();

        $checks[] = [
            'key' => 'packs_installed',
            'label' => 'Regulatory packs installed',
            'ok' => $packVersions > 0,
            'blocking' => true,
            'count' => $packVersions,
            'detail' => $packVersions > 0
                ? "{$packVersions} pack version(s) available to activate."
                : 'No regulatory pack is installed, so there is nothing to activate and no '
                    .'incident can be classified.',
            'fix' => 'php artisan sheq:install',
        ];

        foreach ([
            ['sheq_inspection_templates', 'inspection_templates', 'Inspection templates',
                'php artisan sheq:templates:install'],
            ['sheq_inductions', 'inductions', 'Induction library',
                'php artisan sheq:inductions:install'],
        ] as [$table, $key, $label, $fix]) {
            $count = Schema::hasTable($table) ? DB::table($table)->count() : 0;

            $checks[] = [
                'key' => $key, 'label' => $label,
                'ok' => $count > 0,
                // Not blocking: a tenant can author their own, and an empty library is a thin
                // experience rather than a refused workflow.
                'blocking' => false,
                'count' => $count,
                'detail' => $count > 0 ? "{$count} available."
                    : 'None installed. Screens will work and will be empty.',
                'fix' => $fix,
            ];
        }

        // ── Integrity and review of what IS installed (REG-FIX-4, REG-FIX-5) ───────────────
        if ($packVersions > 0) {
            $assurances = $this->assurance->all();

            $tampered = array_filter($assurances, fn (array $a) => $a['blocking'] !== []);
            $unreviewed = array_filter($assurances,
                fn (array $a) => in_array(PackAssurance::NOT_LEGALLY_REVIEWED,
                    $a['acknowledgeable'], true));

            $checks[] = [
                'key' => 'pack_integrity',
                'label' => 'Pack contents match their checksums',
                'ok' => $tampered === [],
                'blocking' => true,
                'count' => count($tampered),
                'detail' => $tampered === []
                    ? 'Every installed pack still matches the checksum it was installed with.'
                    : count($tampered).' pack version(s) no longer match their checksum and '
                        .'cannot be activated.',
                'fix' => 'php artisan sheq:packs:install --force',
            ];

            $checks[] = [
                'key' => 'pack_legal_review',
                'label' => 'Packs legally reviewed',
                'ok' => $unreviewed === [],
                // Not a blocker. It does not stop the system working; it stops the output being
                // honestly describable as statutory compliance, which is a commercial fact
                // rather than a technical one.
                'blocking' => false,
                'count' => count($unreviewed),
                'detail' => $unreviewed === []
                    ? 'Every installed pack has a recorded legal review.'
                    : count($unreviewed).' pack version(s) have no recorded legal review. Their '
                        .'classifications are this system\'s reading of the regulations and must '
                        .'not be presented to a customer as statutory compliance.',
                'fix' => 'php artisan sheq:packs:review {version} --reviewer= --capacity=',
            ];
        }

        // ── Per-tenant: the one thing that cannot be automated ─────────────────────────────
        if ($companyId !== null) {
            $activated = DB::table('sheq_regulatory_pack_activations')
                ->where('company_id', $companyId)->where('status', 'ACCEPTED')
                ->whereNull('deactivated_at')->exists();

            $checks[] = [
                'key' => 'pack_activated',
                'label' => 'A regulatory pack is in force for this company',
                'ok' => $activated,
                'blocking' => true,
                'detail' => $activated
                    ? 'Incidents can be classified and statutory deadlines set.'
                    : 'No pack is in force. Every incident report will be refused at the moment '
                        .'of submitting it, which is how a tenant found out in production.',
                // A screen, not a command: which rulebook a company is judged by is their
                // decision and stays a deliberate act.
                'fix' => 'SHEQ → Regulatory Packs → Activate',
            ];

            $sites = DB::table('sheq_sites')->where('company_id', $companyId)
                ->whereNull('deleted_at')->where('is_active', true)->count();

            $checks[] = [
                'key' => 'sites',
                'label' => 'Sites',
                'ok' => $sites > 0,
                'blocking' => true,
                'count' => $sites,
                'detail' => $sites > 0 ? "{$sites} active site(s)."
                    : 'No site exists, so nothing can be inspected, permitted or entered.',
                'fix' => 'SHEQ → Sites → Add a site',
            ];
        }

        $blockers = count(array_filter($checks,
            fn (array $c) => $c['blocking'] && ! $c['ok']));

        $warnings = count(array_filter($checks,
            fn (array $c) => ! $c['blocking'] && ! $c['ok']));

        return [
            'ready' => $blockers === 0,
            'blockers' => $blockers,
            'warnings' => $warnings,
            'checks' => $checks,
        ];
    }
}
