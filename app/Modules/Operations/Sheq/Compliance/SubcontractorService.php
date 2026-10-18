<?php

namespace App\Modules\Operations\Sheq\Compliance;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Who appointed whom, and what a principal contractor is allowed to see about it (CON-25).
 *
 * Construction Regulation 7 makes a principal contractor responsible for the subcontractors it
 * appoints, including for taking reasonable steps to ensure they comply. The register was flat —
 * every firm answered directly to the host — so the one question a principal is actually
 * accountable for, "are the firms I appointed in order?", could not be asked at all. They found
 * out when the host emailed them, which means they found out late.
 *
 * The permission boundary is the harder half and the easier one to get wrong. A principal may see
 * the compliance STATE of the firms beneath it. It may not see its siblings, it may not see the
 * firm that appointed it, and it may not see a subcontractor's workers' medical records — those
 * are health information about other people's employees, and the fact that you are accountable
 * for a firm's compliance does not make its staff's medicals yours to read.
 *
 * So visibility is declared, defaults to the summary alone, and every portal read goes through
 * `visibleTo()` rather than through a query somebody wrote in a controller.
 */
class SubcontractorService
{
    /**
     * What a principal may be given about a subcontractor.
     *
     * Each is off unless the host turns it on, except the summary. A host switching on worker
     * detail is making a decision about other people's employees and should have to make it.
     *
     * @var array<string,string>
     */
    public const VISIBILITY = [
        'COMPLIANCE_SUMMARY' => 'Whether they are compliant, and what is outstanding by category.',
        'DOCUMENT_STATUS' => 'Which documents are missing, expired or awaiting acceptance — by '
            .'name, without the documents themselves.',
        'SAFETY_FILE_STATE' => 'Whether their safety file has been approved.',
        'WORKER_COUNT' => 'How many people they have on the roster, and how many are compliant.',
        'SITE_APPROVALS' => 'Which sites have approved them.',
        'INCIDENT_COUNT' => 'How many incidents have been recorded against them.',
    ];

    /** The shipped set: enough to discharge the duty, and nothing about individuals. */
    public const DEFAULT_VISIBILITY = ['COMPLIANCE_SUMMARY', 'SAFETY_FILE_STATE'];

    /** Appoint one contractor as another's subcontractor. */
    public function appoint(int $companyId, string $principalUuid, string $subcontractorUuid,
        ?int $actorId = null): void
    {
        $principal = $this->row($companyId, $principalUuid);
        $subcontractor = $this->row($companyId, $subcontractorUuid);

        if ($principal->id === $subcontractor->id) {
            throw new RuntimeException('A contractor cannot be its own principal contractor.');
        }

        /*
         * Checked here as well as in the database. The trigger is the guarantee — the register is
         * written from a controller, an importer, a seeder and the supplier bridge — but a
         * Postgres exception surfaces as a 500, and somebody arranging a contractor tree deserves
         * a sentence rather than a stack trace.
         */
        if ($this->isAncestor($companyId, (int) $subcontractor->id, (int) $principal->id)) {
            throw new RuntimeException(sprintf(
                '%s already appears above %s in the contractor chain, so this would make a loop.',
                $subcontractor->name, $principal->name));
        }

        $before = $subcontractor->parent_contractor_id;

        DB::table('sheq_contractors')->where('id', $subcontractor->id)->update([
            'parent_contractor_id' => $principal->id,
            'updated_at' => now(),
        ]);

        $this->audit($companyId, $actorId, 'contractor.subcontractor_appointed',
            (int) $subcontractor->id,
            ['parent_contractor_id' => $before],
            ['parent_contractor_id' => $principal->id, 'principal' => $principal->name,
                'contractor_id' => (int) $subcontractor->id],
            sprintf('%s appointed as a subcontractor of %s.',
                $subcontractor->name, $principal->name));
    }

    /** Release a subcontractor, so it answers directly to the host again. */
    public function release(int $companyId, string $subcontractorUuid, ?int $actorId = null): void
    {
        $subcontractor = $this->row($companyId, $subcontractorUuid);

        if ($subcontractor->parent_contractor_id === null) {
            return;
        }

        DB::table('sheq_contractors')->where('id', $subcontractor->id)->update([
            'parent_contractor_id' => null,
            'updated_at' => now(),
        ]);

        $this->audit($companyId, $actorId, 'contractor.subcontractor_released',
            (int) $subcontractor->id,
            ['parent_contractor_id' => $subcontractor->parent_contractor_id],
            ['parent_contractor_id' => null, 'contractor_id' => (int) $subcontractor->id],
            sprintf('%s no longer answers to a principal contractor.', $subcontractor->name));
    }

    /** Set what a principal may see about the firms beneath it. */
    public function setVisibility(int $companyId, string $principalUuid, array $visibility,
        ?int $actorId = null): array
    {
        $principal = $this->row($companyId, $principalUuid);

        $clean = array_values(array_intersect(
            array_map('strtoupper', $visibility), array_keys(self::VISIBILITY)));

        /*
         * The summary is not optional. A principal contractor accountable for a firm's compliance
         * and shown nothing about it is worse than one shown nothing at all: the screen exists, it
         * is empty, and they reasonably conclude there is nothing wrong.
         */
        if (! in_array('COMPLIANCE_SUMMARY', $clean, true)) {
            $clean[] = 'COMPLIANCE_SUMMARY';
        }

        DB::table('sheq_contractors')->where('id', $principal->id)->update([
            'subcontractor_visibility' => json_encode($clean),
            'updated_at' => now(),
        ]);

        $this->audit($companyId, $actorId, 'contractor.subcontractor_visibility_changed',
            (int) $principal->id,
            ['visibility' => $this->visibilityFor($companyId, (int) $principal->id)],
            ['visibility' => $clean, 'contractor_id' => (int) $principal->id],
            'What this principal contractor may see about the firms it appointed.');

        return $clean;
    }

    /** @return list<string> */
    public function visibilityFor(int $companyId, int $principalId): array
    {
        $stored = json_decode((string) DB::table('sheq_contractors')
            ->where('company_id', $companyId)->where('id', $principalId)
            ->value('subcontractor_visibility'), true);

        return is_array($stored) && $stored !== [] ? array_values($stored)
            : self::DEFAULT_VISIBILITY;
    }

    /**
     * The firms directly beneath one contractor.
     *
     * @return list<array<string,mixed>>
     */
    public function children(int $companyId, int $principalId): array
    {
        return DB::table('sheq_contractors')
            ->where('company_id', $companyId)->where('parent_contractor_id', $principalId)
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->get(['id', 'uuid', 'name', 'trade', 'access_status', 'is_active'])
            ->map(fn ($c) => (array) $c)->all();
    }

    /**
     * The whole chain beneath one contractor, flattened with its depth.
     *
     * Iterative rather than recursive, with a depth cap. A loop in the data would make a
     * recursive walk run until the request dies, and the cap means a bad row degrades into a
     * short answer rather than a white screen — the database trigger prevents loops being
     * created, and this file still has to survive data that predates it.
     *
     * @return list<array<string,mixed>>
     */
    public function descendants(int $companyId, int $principalId, int $maxDepth = 8): array
    {
        $out = [];
        $frontier = [[$principalId, 0]];
        $seen = [$principalId => true];

        while ($frontier !== []) {
            [$id, $depth] = array_shift($frontier);

            if ($depth >= $maxDepth) {
                continue;
            }

            foreach ($this->children($companyId, $id) as $child) {
                if (isset($seen[$child['id']])) {
                    continue;
                }

                $seen[$child['id']] = true;
                $out[] = $child + ['depth' => $depth + 1];
                $frontier[] = [(int) $child['id'], $depth + 1];
            }
        }

        return $out;
    }

    /** Whether one contractor appears above another in the chain. */
    public function isAncestor(int $companyId, int $possibleAncestor, int $contractorId): bool
    {
        $current = $contractorId;
        $depth = 0;

        while ($current !== null && $depth++ < 32) {
            $parent = DB::table('sheq_contractors')
                ->where('company_id', $companyId)->where('id', $current)
                ->value('parent_contractor_id');

            if ($parent === null) {
                return false;
            }

            if ((int) $parent === $possibleAncestor) {
                return true;
            }

            $current = (int) $parent;
        }

        return false;
    }

    /**
     * What this principal may be told about the firms beneath it (CON-25's permission boundary).
     *
     * The only road from the portal to a subcontractor's data. A query written in a controller
     * would be a second road, and the second road is the one that forgets the boundary — which
     * here means showing a contractor another firm's workers' medical records.
     *
     * @return array<string,mixed>
     */
    public function visibleTo(int $companyId, int $principalId): array
    {
        $allowed = $this->visibilityFor($companyId, $principalId);
        $engine = app(ComplianceDecisionService::class);

        $rows = [];

        foreach ($this->descendants($companyId, $principalId) as $child) {
            $decision = $engine->forContractor($companyId, (int) $child['id']);

            $row = [
                // Never the internal id: this is handed to a contractor over a public portal, and
                // a sequential id is an invitation to try the next one.
                'uuid' => $child['uuid'],
                'name' => $child['name'],
                'trade' => $child['trade'],
                'depth' => $child['depth'],
                'is_active' => (bool) $child['is_active'],
            ];

            if (in_array('COMPLIANCE_SUMMARY', $allowed, true)) {
                $row['outcome'] = $decision['outcome'];
                $row['allowed'] = $decision['allowed'];
                /*
                 * Counted by category rather than listed. "Two documents outstanding" is what a
                 * principal needs in order to chase them; the sentences name certificates and
                 * dates, which is detail about another firm's affairs.
                 */
                $row['outstanding'] = collect($decision['findings'])
                    ->where('severity', 'BLOCKING')
                    ->groupBy('subject')->map->count()->all();
            }

            if (in_array('DOCUMENT_STATUS', $allowed, true)) {
                $row['documents'] = collect($decision['findings'])
                    ->where('subject', 'DOCUMENT')
                    ->pluck('code')->unique()->values()->all();
            }

            if (in_array('SAFETY_FILE_STATE', $allowed, true)) {
                $file = collect($decision['checks'])->firstWhere('key', 'SAFETY_FILE');
                $row['safety_file'] = $file['state'] ?? null;
            }

            if (in_array('WORKER_COUNT', $allowed, true)) {
                $row['workers'] = (int) DB::table('sheq_contractor_workers')
                    ->where('company_id', $companyId)->where('contractor_id', $child['id'])
                    ->whereNull('deleted_at')->where('is_active', true)->count();
            }

            if (in_array('SITE_APPROVALS', $allowed, true)) {
                $row['approved_sites'] = DB::table('sheq_contractor_site_approvals as a')
                    ->join('sheq_sites as s', 's.id', '=', 'a.site_id')
                    ->where('a.company_id', $companyId)->where('a.contractor_id', $child['id'])
                    // Soft-deleted rather than withdrawn: a withdrawal removes the decision
                    // row, which is how `SiteApprovalService` records it.
                    ->where('a.status', 'APPROVED')->whereNull('a.deleted_at')
                    ->pluck('s.name')->all();
            }

            if (in_array('INCIDENT_COUNT', $allowed, true)) {
                $row['incidents'] = (int) DB::table('sheq_incidents')
                    ->where('company_id', $companyId)->where('contractor_id', $child['id'])
                    ->whereNull('deleted_at')->count();
            }

            $rows[] = $row;
        }

        return [
            'visibility' => $allowed,
            'subcontractors' => $rows,
            'counts' => [
                'total' => count($rows),
                'not_compliant' => count(array_filter($rows,
                    fn (array $r) => ($r['allowed'] ?? true) === false)),
            ],
        ];
    }

    /**
     * The contractor a principal may read, or null.
     *
     * The guard every cross-contractor read must pass. A principal asking about a firm that is
     * not beneath it gets nothing — not an error naming the firm, which would confirm it exists.
     */
    public function readableChild(int $companyId, int $principalId, string $uuid): ?object
    {
        $child = DB::table('sheq_contractors')
            ->where('company_id', $companyId)->where('uuid', $uuid)->whereNull('deleted_at')
            ->first(['id', 'uuid', 'name']);

        if ($child === null || ! $this->isAncestor($companyId, $principalId, (int) $child->id)) {
            return null;
        }

        return $child;
    }

    private function row(int $companyId, string $uuid): object
    {
        $row = DB::table('sheq_contractors')
            ->where('company_id', $companyId)->where('uuid', $uuid)->whereNull('deleted_at')
            ->first(['id', 'uuid', 'name', 'parent_contractor_id']);

        if ($row === null) {
            throw new RuntimeException('No such contractor in this company.');
        }

        return $row;
    }

    private function audit(int $companyId, ?int $actorId, string $action, int $subjectId,
        ?array $before, array $after, string $reason): void
    {
        DB::table('sheq_audit_log')->insert([
            'company_id' => $companyId,
            'actor_id' => $actorId,
            'actor_type' => 'USER',
            'action' => $action,
            'subject_type' => 'Contractor',
            'subject_id' => $subjectId,
            'before' => $before === null ? null : json_encode($before),
            'after' => json_encode($after),
            'reason' => $reason,
        ]);
    }
}
