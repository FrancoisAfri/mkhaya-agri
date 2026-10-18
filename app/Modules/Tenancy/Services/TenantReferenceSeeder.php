<?php

namespace App\Modules\Tenancy\Services;

use App\Modules\Tenancy\Models\Company;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Seeds a NEW company's per-tenant reference data by cloning the source company's
 * (default: company 1) dropdown-driving lookup tables — the scoped *_types /
 * *_categories / *_statuses / *_settings / *_reasons rows. Without this a fresh
 * tenant would have empty category/type/status dropdowns because those tables now
 * hold only the source company's rows (RLS hides them from other tenants).
 *
 * Rows are read in the source tenant's context and inserted in the new tenant's
 * context (so RLS WITH CHECK passes and the DB default/explicit company_id tags
 * them correctly). Self-referential parent_id is remapped to the cloned ids.
 * Best-effort and non-fatal: a table that can't be cloned (e.g. a global unique
 * constraint) is skipped and logged, never breaking provisioning.
 */
class TenantReferenceSeeder
{
    public function __construct(private TenantContext $tenant) {}

    /** Table-name patterns that ARE per-company reference/lookup data. */
    private const INCLUDE = '/(_types?|_categor(y|ies)|_status(es)?|_settings?|_reasons?)$/';

    /**
     * Reference/default tables that don't match the name pattern but that every tenant
     * needs seeded (their default rows currently live only under the master company).
     * Kept as an EXPLICIT allow-list — a broad "_profile" pattern wrongly catches
     * OPERATIONAL per-employee tables (talent/skill/candidate profiles, shift
     * assignments), which must never be cloned across tenants.
     * e.g. leave_profile = "5 Day Employee / 6 Day Employee / Shift Worker / …".
     */
    private const EXTRA = ['leave_profile'];

    /** Per-tenant singleton config rows to clone that don't match the lookup pattern. */
    private const SINGLETONS = ['leave_configuration', 'company_identities'];

    /** ...except these, which are operational children, not reference dropdowns. */
    private const EXCLUDE = '/(question_option|_answer|_response|template)/';

    /** @return array{seeded:int, tables:int, skipped:array<int,string>} */
    public function seedFor(Company $company, int $sourceId = 1, bool $force = false): array
    {
        $result = ['seeded' => 0, 'tables' => 0, 'skipped' => []];
        if ($company->id === $sourceId || DB::getDriverName() !== 'pgsql') {
            return $result;
        }

        foreach ($this->referenceTables() as $table) {
            try {
                $n = $this->cloneTable($table, $sourceId, $company->id, $force);
                if ($n > 0) {
                    $result['seeded'] += $n;
                    $result['tables']++;
                }
            } catch (\Throwable $e) {
                $result['skipped'][] = $table;
                Log::warning('TenantReferenceSeeder: skipped table', ['table' => $table, 'company' => $company->id, 'error' => $e->getMessage()]);
            }
        }

        // Per-tenant SINGLETON config rows that don't match the lookup-table pattern but
        // are required for the tenant to work: the leave configuration (drives HR/manager
        // approval routing) and the company identity (drives settings/setup — HR Managers,
        // branding). Without these, LeaveConfiguration::first()/CompanyIdentity::first()
        // return null for the tenant and those screens/flows break.
        foreach (self::SINGLETONS as $table) {
            try {
                $n = $this->cloneTable($table, $sourceId, $company->id, $force);
                if ($n <= 0) {
                    continue;
                }
                $result['seeded'] += $n;
                $result['tables']++;
            } catch (\Throwable $e) {
                $result['skipped'][] = $table;
                Log::warning('TenantReferenceSeeder: singleton skipped', ['table' => $table, 'company' => $company->id, 'error' => $e->getMessage()]);

                continue;
            }

            // Cosmetic post-clone tweak — must NOT fail the seed. Stamp the tenant's own
            // name so the setup page doesn't show the source company's identity. (The
            // cloned leave_configuration keeps the source hr_person_id, which is NOT NULL
            // and invisible to the tenant under RLS — resolveHrUser() falls back to the
            // tenant's settings/setup HR Managers, so this is harmless.)
            try {
                if ($table === 'company_identities') {
                    $this->tenant->run($company->id, fn () => DB::table($table)
                        ->where('company_id', $company->id)
                        ->update(['company_name' => $company->name, 'full_company_name' => $company->name]));
                }
            } catch (\Throwable $e) {
                Log::warning('TenantReferenceSeeder: singleton post-tweak failed', ['table' => $table, 'company' => $company->id, 'error' => $e->getMessage()]);
            }
        }

        return $result;
    }

    /** Scoped lookup tables that match the include pattern (or the EXTRA list). */
    private function referenceTables(): array
    {
        return collect(DB::select('select tablename from pg_tables where schemaname = current_schema()'))
            ->pluck('tablename')
            ->filter(fn ($t) => (preg_match(self::INCLUDE, $t) || in_array($t, self::EXTRA, true))
                && ! preg_match(self::EXCLUDE, $t)
                && Schema::hasColumn($t, 'company_id')
                && Schema::hasColumn($t, 'id'))
            ->values()
            ->all();
    }

    /** All tables this seeder manages (lookup + singletons) — for reporting/coverage. */
    public function managedTables(): array
    {
        return array_values(array_unique(array_merge(
            $this->referenceTables(),
            array_filter(self::SINGLETONS, fn ($t) => Schema::hasTable($t)),
        )));
    }

    private function cloneTable(string $table, int $sourceId, int $newId, bool $force = false): int
    {
        // Idempotent: if the target company already has rows in this table, skip it
        // (so backfilling an existing tenant never duplicates the reference data). Use
        // --force to re-clone regardless.
        if (! $force) {
            $already = $this->tenant->run($newId, fn () => DB::table($table)->where('company_id', $newId)->exists());
            if ($already) {
                return 0;
            }
        }

        // Read the source company's rows in its own tenant context.
        $rows = $this->tenant->run($sourceId, fn () => DB::table($table)->where('company_id', $sourceId)->get());
        if ($rows->isEmpty()) {
            return 0;
        }
        $hasParent = Schema::hasColumn($table, 'parent_id');

        return $this->tenant->run($newId, function () use ($table, $rows, $newId, $hasParent) {
            $idMap = [];          // old id => new id
            $newParent = [];      // new id => old parent_id
            // Regenerate any UUID column so cloned rows don't collide on a unique uuid.
            $uuidCols = collect(Schema::getColumnListing($table))
                ->filter(fn ($c) => $c === 'uuid' || str_ends_with($c, '_uuid'))->all();
            foreach ($rows as $row) {
                $data = (array) $row;
                $oldId = $data['id'] ?? null;
                unset($data['id']);
                $data['company_id'] = $newId;
                foreach ($uuidCols as $uc) {
                    if (array_key_exists($uc, $data) && $data[$uc] !== null) {
                        $data[$uc] = (string) Str::uuid();
                    }
                }
                if ($hasParent && ! empty($data['parent_id'])) {
                    $oldParent = $data['parent_id'];
                }
                $newIdRow = DB::table($table)->insertGetId($data);
                if ($oldId !== null) {
                    $idMap[$oldId] = $newIdRow;
                }
                if (isset($oldParent)) {
                    $newParent[$newIdRow] = $oldParent;
                    unset($oldParent);
                }
            }
            // Remap self-referential parent_id to the cloned ids.
            foreach ($newParent as $rowId => $oldParent) {
                if (isset($idMap[$oldParent])) {
                    DB::table($table)->where('id', $rowId)->update(['parent_id' => $idMap[$oldParent]]);
                }
            }

            return count($rows);
        });
    }
}
