<?php

namespace App\Modules\Tenancy\Services;

use App\Core\User\Models\User;
use App\Modules\Tenancy\Models\Company;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Deletes the business data of a company that was converted to a reseller, once its retention
 * period is over (companies.purge_after).
 *
 * Every row carrying the company's id goes, in any order the foreign keys allow, including the
 * append-only audit tables: their protecting triggers are switched off for this transaction only,
 * and only on the tables that hold this company's rows, then switched back on before it commits.
 * The retention period has ended, so the records the triggers protect are due for deletion too.
 *
 * The company row stays as a tombstone (name, dates, the reseller it became) with its contact
 * details cleared, so the reseller's history still says where it came from. The users who moved to
 * the reseller are untouched: they no longer belong to the company.
 *
 * One transaction: any failure rolls the whole purge back and leaves the company to be retried.
 */
class ArchivedCompanyPurge
{
    /** @return list<Company> companies whose retention period is over */
    public function due(): array
    {
        return Company::query()->where('status', 'archived')->whereNull('purged_at')
            ->whereNotNull('purge_after')->whereDate('purge_after', '<=', today())->orderBy('id')->get()->all();
    }

    /** @return array<string, int> rows still held per table (what a purge would delete) */
    public function inventory(Company $company): array
    {
        $out = [];
        foreach ($this->tables() as $table) {
            $n = DB::table($table)->where('company_id', $company->id)->count();
            if ($n) {
                $out[$table] = $n;
            }
        }

        return $out;
    }

    /** @return array<string, int> rows deleted per table */
    public function purge(Company $company): array
    {
        if ($company->status !== 'archived' || $company->purged_at || ! $company->purge_after || $company->purge_after->isFuture()) {
            throw new RuntimeException("{$company->name} is not due for purging.");
        }

        $deleted = DB::transaction(function () use ($company) {
            $cid = (int) $company->id;
            DB::statement("SELECT set_config('app.company_id', ?, true)", [(string) $cid]);

            $tables = array_keys($this->inventory($company));
            $userIds = DB::table('users')->where('company_id', $cid)->pluck('id')->all();
            $triggers = $this->protectingTriggers($tables);
            foreach ($triggers as [$table, $trigger]) {
                DB::statement(sprintf('ALTER TABLE %s DISABLE TRIGGER %s', $this->ident($table), $this->ident($trigger)));
            }

            $deleted = [];
            $pending = array_values(array_diff($tables, ['users']));
            // Rows that reference each other are deleted in whatever order the foreign keys allow:
            // each pass deletes what it can (inside a savepoint) and retries the rest.
            for ($pass = 0; $pending; $pass++) {
                $left = [];
                foreach ($pending as $table) {
                    try {
                        $deleted[$table] = DB::transaction(fn () => DB::table($table)->where('company_id', $cid)->delete());
                    } catch (Throwable $e) {
                        $left[$table] = $e->getMessage();
                    }
                }
                if (count($left) === count($pending) || $pass > 40) {
                    throw new RuntimeException('Could not delete ' . implode(', ', array_keys($left)) . ': ' . mb_substr((string) reset($left), 0, 300));
                }
                $pending = array_keys($left);
            }

            // The people left behind at the conversion, and what hangs off their logins.
            if ($userIds) {
                DB::table('model_has_roles')->whereIn('model_id', $userIds)->where('model_type', User::class)->delete();
                DB::table('model_has_permissions')->whereIn('model_id', $userIds)->where('model_type', User::class)->delete();
                foreach (['security_module_access', 'security_ribbons_access', 'security_category_access'] as $table) {
                    DB::table($table)->whereIn('user_id', $userIds)->delete();
                }
                DB::table('notifications')->whereIn('notifiable_id', $userIds)->where('notifiable_type', User::class)->delete();
                DB::table('sessions')->whereIn('user_id', $userIds)->delete();
                $deleted['users'] = DB::table('users')->whereIn('id', $userIds)->where('company_id', $cid)->delete();
            }

            foreach ($triggers as [$table, $trigger]) {
                DB::statement(sprintf('ALTER TABLE %s ENABLE TRIGGER %s', $this->ident($table), $this->ident($trigger)));
            }

            $company->forceFill(['purged_at' => now(), 'contact_person' => null, 'contact_email' => null, 'contact_phone' => null])->save();

            return array_filter($deleted);
        });

        Log::info('Archived company purged', ['company_id' => $company->id, 'rows' => array_sum($deleted), 'tables' => count($deleted)]);

        return $deleted;
    }

    /** @return list<string> base tables with a company_id column */
    private function tables(): array
    {
        return collect(DB::select("SELECT c.table_name FROM information_schema.columns c
            JOIN information_schema.tables t ON t.table_name = c.table_name AND t.table_schema = c.table_schema
            WHERE c.column_name = 'company_id' AND c.table_schema = current_schema() AND t.table_type = 'BASE TABLE'
            ORDER BY c.table_name"))->pluck('table_name')->all();
    }

    /**
     * User triggers that fire on DELETE (the append-only guards) on the given tables.
     *
     * @return list<array{0:string, 1:string}>
     */
    private function protectingTriggers(array $tables): array
    {
        if (! $tables) {
            return [];
        }
        $rows = DB::select("SELECT c.relname AS table_name, t.tgname AS trigger_name
            FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid JOIN pg_namespace n ON n.oid = c.relnamespace
            WHERE NOT t.tgisinternal AND n.nspname = current_schema() AND (t.tgtype & 8) <> 0
              AND c.relname = ANY (?)", ['{' . implode(',', $tables) . '}']);

        return array_map(fn ($r) => [$r->table_name, $r->trigger_name], $rows);
    }

    private function ident(string $name): string
    {
        return '"' . str_replace('"', '""', $name) . '"';
    }
}
