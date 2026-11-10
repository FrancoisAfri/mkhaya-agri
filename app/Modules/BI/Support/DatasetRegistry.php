<?php

namespace App\Modules\BI\Support;

/**
 * Every dataset the BI engine can query, read from app/Modules/BI/Datasets/*.php.
 *
 * A module publishes its data by adding a file there that returns
 *
 *   'dataset_key' => [
 *       'module'     => 'accounting',                  // billing / security module code that owns it
 *       'label'      => 'Sales invoices',
 *       'from'       => 'fin_invoices i left join fin_parties p on p.id = i.party_id',
 *       'company'    => 'i.company_id',                // the tenant column (RLS also applies)
 *       'date'       => 'i.invoice_date',              // the date the period filters on
 *       'where'      => "i.direction = 'receivable'",  // optional fixed condition
 *       'measures'   => ['revenue' => ['label' => 'Revenue', 'sql' => 'coalesce(sum(i.subtotal),0)', 'format' => 'currency'],
 *                        'avg' => ['label' => 'Average', 'ratio' => ['revenue', 'invoices'], 'format' => 'currency']],
 *       'dimensions' => ['customer' => ['label' => 'Customer', 'sql' => "coalesce(p.name, 'Unknown')"]],
 *   ]
 *
 * Only the SQL written in these files is ever executed: a dashboard names keys, never SQL, so
 * nothing a user saves can reach the database except as a bound value.
 */
final class DatasetRegistry
{
    private static ?array $all = null;

    /** @return array<string, array> */
    public static function all(): array
    {
        if (self::$all !== null) {
            return self::$all;
        }
        $all = [];
        foreach (glob(app_path('Modules/BI/Datasets/*.php')) ?: [] as $file) {
            try {
                $defs = (array) require $file;
            } catch (\Throwable $e) {
                report($e); // one broken file must not take every dashboard down with it
                continue;
            }
            foreach ($defs as $key => $ds) {
                $all[$key] = $ds + ['key' => $key, 'where' => null, 'measures' => [], 'dimensions' => []];
            }
        }
        ksort($all);

        return self::$all = $all;
    }

    public static function get(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    /** Measures a widget may name besides the dataset's own: comparisons with last year. */
    public const COMPARE = ['last_year' => 'Same period last year', 'growth_pct' => 'Growth % vs last year'];

    public static function flush(): void
    {
        self::$all = null;
    }
}
