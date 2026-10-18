<?php

namespace App\Modules\Operations\Sheq\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates SHEQ sites: from the tenant's company structure, and by hand.
 *
 * Every SHEQ record hangs off a site: incidents, hazards, permits, inspections, notification
 * routing and the offline sync scope all key on one. The module had no way to create a site at all,
 * so `sheq_sites` sat empty and the Site dropdown on the report form was blank — a form nobody
 * could complete properly on the one screen that must never have friction.
 *
 * Sites mirror `company_units` rather than being typed in again. The tenant already built that tree
 * in Company Settings, and asking them to rebuild it here would guarantee the two drift apart —
 * then an incident is filed against "Shaft 4" while HR reorganises "Shaft 4 Operations" and nobody
 * can reconcile the two. `sheq_sites.company_unit_id` carries the reference so a rename follows.
 *
 * What is *not* copied is as deliberate: no headcount, no manager, no cost centre. SHEQ has no
 * business holding those (SHEQ-DATA-4), and the unit id is enough to ask HR when it needs them.
 *
 * A site created by hand is left alone. Once a tenant edits a geofence or a timezone, that is theirs
 * and a resync must not flatten it.
 *
 * ── Sites that are not units ─────────────────────────────────────────────────
 *
 * Mirroring the structure was right and was not enough. A depot rented for eight months, a client's
 * premises, a stretch of road under contract — these are places where work happens and where an
 * incident gets reported, and none of them is a line on an HR org chart. There was no way to record
 * one, so people filed against the nearest department and the site on the record was a fiction.
 *
 * So a site can also be typed here. It carries no `company_unit_id`, which is exactly what keeps
 * the resync away from it: `syncFromCompanyStructure` only ever looks at rows that have one.
 *
 * Both kinds are made through this class, because making a valid site row is more than an insert —
 * the code has to be unique per tenant and the `path` has to be written as an ltree, and a second
 * place doing it by hand is a second place to get it wrong.
 */
class SiteProvisioner
{
    /**
     * Unit types that become sites, in the order they nest.
     *
     * All of them, deliberately. A hazard gets reported where the person is standing, and forcing a
     * cleaner to pick "Finance & Administration" because their team is not a site is how a reporting
     * system stops being used. The hierarchy is preserved, so a rule pinned to a division still
     * covers every team under it.
     */
    private const SITE_TYPES = ['division', 'department', 'unit', 'section', 'team'];

    /**
     * Create or refresh sites for one tenant.
     *
     * @return array{created:int, updated:int, unchanged:int, deactivated:int}
     */
    public function syncFromCompanyStructure(int $companyId): array
    {
        return DB::transaction(function () use ($companyId) {
            $units = DB::table('company_units')
                ->where('company_id', $companyId)
                ->whereIn('type', self::SITE_TYPES)
                ->orderBy('id')
                ->get(['id', 'parent_id', 'name', 'type', 'active']);

            $counts = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'deactivated' => 0];

            // unit id => site id, so a child can resolve its parent in the same pass. The query is
            // ordered by id and units are created parent-first, but a tree built by drag-and-drop
            // makes no such promise, so parents are resolved in a second pass below.
            $siteByUnit = DB::table('sheq_sites')
                ->where('company_id', $companyId)->whereNotNull('company_unit_id')
                ->pluck('id', 'company_unit_id')->all();

            foreach ($units as $unit) {
                $existingId = $siteByUnit[$unit->id] ?? null;

                if ($existingId === null) {
                    $siteByUnit[$unit->id] = DB::table('sheq_sites')->insertGetId([
                        'uuid' => (string) Str::uuid(),
                        'company_id' => $companyId,
                        'company_unit_id' => $unit->id,
                        'code' => $this->code($companyId, $unit->name),
                        'name' => $unit->name,
                        'site_type' => $this->siteType($unit->type),
                        // The tenant's own timezone matters for statutory deadlines (SHEQ-NFR-2),
                        // but a sensible default beats an empty column blocking provisioning.
                        'timezone' => config('app.timezone') === 'UTC'
                            ? 'Africa/Johannesburg' : config('app.timezone'),
                        'is_active' => (bool) $unit->active,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    $counts['created']++;

                    continue;
                }

                $site = DB::table('sheq_sites')->find($existingId);

                // Only the fields the structure owns. A geofence or timezone the tenant set here
                // belongs to SHEQ and is never overwritten by a resync.
                $changes = [];

                if ($site->name !== $unit->name) {
                    $changes['name'] = $unit->name;
                }

                if ((bool) $site->is_active !== (bool) $unit->active) {
                    $changes['is_active'] = (bool) $unit->active;

                    if (! $unit->active) {
                        $counts['deactivated']++;
                    }
                }

                if ($changes === []) {
                    $counts['unchanged']++;

                    continue;
                }

                DB::table('sheq_sites')->where('id', $existingId)
                    ->update($changes + ['updated_at' => now()]);

                $counts['updated']++;
            }

            $this->linkHierarchy($companyId, $units, $siteByUnit);

            return $counts;
        });
    }

    /**
     * Second pass: parent links and the ltree path.
     *
     * Separate from creation because a unit's parent may be created later in the same run, and a
     * half-built path breaks every subtree rule that reads it — notification routing scoped to a
     * division, sync scoped to a site and its children.
     *
     * @param  array<int,int>  $siteByUnit
     */
    private function linkHierarchy(int $companyId, $units, array $siteByUnit): void
    {
        $parentOfUnit = [];

        foreach ($units as $unit) {
            $parentOfUnit[$unit->id] = $unit->parent_id;
        }

        foreach ($units as $unit) {
            $siteId = $siteByUnit[$unit->id] ?? null;

            if ($siteId === null) {
                continue;
            }

            $parentSiteId = $unit->parent_id === null ? null : ($siteByUnit[$unit->parent_id] ?? null);

            // Ancestors first, this site last — the lineage the router and sync scope read.
            $lineage = [];
            $cursor = $unit->id;
            $guard = 0;

            while ($cursor !== null && $guard++ < 50) {
                if (isset($siteByUnit[$cursor])) {
                    array_unshift($lineage, $siteByUnit[$cursor]);
                }

                $cursor = $parentOfUnit[$cursor] ?? null;
            }

            $path = implode('.', $lineage) ?: (string) $siteId;

            DB::table('sheq_sites')->where('id', $siteId)->update([
                'parent_id' => $parentSiteId,
                'updated_at' => now(),
            ]);

            if (DB::getDriverName() === 'pgsql') {
                DB::statement('UPDATE sheq_sites SET path = ?::ltree WHERE id = ?', [$path, $siteId]);
            }
        }
    }

    /**
     * SHEQ's own vocabulary, not HR's. A team is where work happens; a division is a grouping.
     * Keeping the translation here means the site type stays meaningful to a safety practitioner
     * even if HR renames its unit types.
     */
    private function siteType(string $unitType): string
    {
        return match ($unitType) {
            'division' => 'REGION',
            'department' => 'SITE',
            'unit', 'section' => 'AREA',
            'team' => 'AREA',
            default => 'SITE',
        };
    }

    /**
     * A site somebody typed, rather than one mirrored from the structure.
     *
     * @param  array<string, mixed>  $data
     * @return string the new site's uuid
     */
    public function createManualSite(int $companyId, array $data, ?int $userId = null): string
    {
        return DB::transaction(function () use ($companyId, $data, $userId) {
            $uuid = (string) Str::uuid();

            $parentId = $this->siteIdForUuid($companyId, $data['parent_uuid'] ?? null);

            $id = DB::table('sheq_sites')->insertGetId([
                'uuid' => $uuid,
                'company_id' => $companyId,
                // Null on purpose, and load-bearing: this is what tells a resync the site is not
                // its to manage. See syncFromCompanyStructure, which only reads rows that have one.
                'company_unit_id' => null,
                'parent_id' => $parentId,
                'code' => filled($data['code'] ?? null)
                    ? $this->uniqueCode($companyId, $data['code'])
                    : $this->code($companyId, $data['name']),
                'name' => $data['name'],
                'site_type' => $data['site_type'] ?? 'SITE',
                'timezone' => $data['timezone'] ?? (config('app.timezone') === 'UTC'
                    ? 'Africa/Johannesburg' : config('app.timezone')),
                'is_active' => $data['is_active'] ?? true,
                'external_reference' => $data['external_reference'] ?? null,
                'created_by' => $userId,
                'updated_by' => $userId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->writePath($companyId, $id, $parentId);

            return $uuid;
        });
    }

    /**
     * Change a site.
     *
     * A mirrored site can be edited too — its name and active flag will simply be pulled back into
     * line on the next resync, because those are the two fields the structure owns. Everything else
     * (the timezone, the code, the geofence) stays whatever it is set to here, which is the same
     * rule the resync already follows.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateSite(int $companyId, string $uuid, array $data, ?int $userId = null): void
    {
        DB::transaction(function () use ($companyId, $uuid, $data, $userId) {
            $site = DB::table('sheq_sites')
                ->where('company_id', $companyId)->where('uuid', $uuid)->whereNull('deleted_at')
                ->first(['id', 'code']);

            if ($site === null) {
                return;
            }

            $changes = array_filter([
                'name' => $data['name'] ?? null,
                'site_type' => $data['site_type'] ?? null,
                'timezone' => $data['timezone'] ?? null,
                'external_reference' => $data['external_reference'] ?? null,
            ], fn ($value) => $value !== null);

            if (array_key_exists('is_active', $data)) {
                $changes['is_active'] = (bool) $data['is_active'];
            }

            if (filled($data['code'] ?? null) && $data['code'] !== $site->code) {
                $changes['code'] = $this->uniqueCode($companyId, $data['code']);
            }

            if (array_key_exists('parent_uuid', $data)) {
                $parentId = $this->siteIdForUuid($companyId, $data['parent_uuid']);

                // A site cannot be its own parent, and a cycle would make the path loop until the
                // guard trips. Refusing here keeps the tree a tree.
                if ($parentId !== $site->id && ! $this->isDescendant($companyId, $parentId, $site->id)) {
                    $changes['parent_id'] = $parentId;
                }
            }

            if ($changes !== []) {
                DB::table('sheq_sites')->where('id', $site->id)
                    ->update($changes + ['updated_by' => $userId, 'updated_at' => now()]);
            }

            $this->writePath(
                $companyId,
                $site->id,
                DB::table('sheq_sites')->where('id', $site->id)->value('parent_id'),
            );
        });
    }

    /**
     * Retire a site rather than delete it.
     *
     * Every incident, permit and inspection points at one. Deleting a site would orphan the record
     * an inspector asks for; deactivating it takes the site out of the dropdowns and leaves the
     * history intact and readable.
     */
    public function deactivateSite(int $companyId, string $uuid, bool $active, ?int $userId = null): void
    {
        DB::table('sheq_sites')
            ->where('company_id', $companyId)->where('uuid', $uuid)->whereNull('deleted_at')
            ->update(['is_active' => $active, 'updated_by' => $userId, 'updated_at' => now()]);
    }

    private function siteIdForUuid(int $companyId, ?string $uuid): ?int
    {
        if (! filled($uuid)) {
            return null;
        }

        return DB::table('sheq_sites')
            ->where('company_id', $companyId)->where('uuid', $uuid)->whereNull('deleted_at')
            ->value('id');
    }

    /** Would making `$parentId` this site's parent put the site under itself? */
    private function isDescendant(int $companyId, ?int $parentId, int $siteId): bool
    {
        $cursor = $parentId;
        $guard = 0;

        while ($cursor !== null && $guard++ < 50) {
            if ($cursor === $siteId) {
                return true;
            }

            $cursor = DB::table('sheq_sites')->where('company_id', $companyId)
                ->where('id', $cursor)->value('parent_id');
        }

        return false;
    }

    /**
     * The ltree lineage for one site, written the same way the structure sync writes it.
     *
     * Nothing reads a site by its parent id alone — the notification router and the offline sync
     * scope both read `path`, so a site without one is a site those never reach.
     */
    private function writePath(int $companyId, int $siteId, ?int $parentId): void
    {
        $lineage = [$siteId];
        $cursor = $parentId;
        $guard = 0;

        while ($cursor !== null && $guard++ < 50) {
            array_unshift($lineage, $cursor);
            $cursor = DB::table('sheq_sites')->where('company_id', $companyId)
                ->where('id', $cursor)->value('parent_id');
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('UPDATE sheq_sites SET path = ?::ltree WHERE id = ?', [
                implode('.', $lineage), $siteId,
            ]);
        }
    }

    /** A code the tenant chose, made unique the same way a generated one is. */
    private function uniqueCode(int $companyId, string $wanted): string
    {
        $base = Str::upper(Str::substr(preg_replace('/[^A-Za-z0-9]/', '', $wanted) ?: 'SITE', 0, 12));
        $code = $base;
        $suffix = 1;

        while (DB::table('sheq_sites')->where('company_id', $companyId)->where('code', $code)->exists()) {
            $code = $base.(++$suffix);
        }

        return $code;
    }

    /** A short, stable, unique code — what appears on a permit board or a printed form. */
    private function code(int $companyId, string $name): string
    {
        $base = Str::upper(Str::substr(preg_replace('/[^A-Za-z0-9]/', '', $name) ?: 'SITE', 0, 6));
        $code = $base;
        $suffix = 1;

        while (DB::table('sheq_sites')->where('company_id', $companyId)->where('code', $code)->exists()) {
            $code = $base.(++$suffix);
        }

        return $code;
    }
}
