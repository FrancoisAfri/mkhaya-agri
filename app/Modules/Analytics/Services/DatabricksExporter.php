<?php

namespace App\Modules\Analytics\Services;

use App\Modules\Analytics\Models\BiConnection;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Pushing a tenant's data into their own Databricks warehouse.
 *
 * ── Why the SQL Statement Execution API ──────────────────────────────────────
 *
 * It is HTTPS and a bearer token, which is the whole of what a customer can hand over from a
 * settings screen. The alternatives — JDBC, a driver, a cluster connection — need a runtime this
 * platform does not have and credentials no customer should paste into a web form.
 *
 * ── Why the table is replaced, not appended ──────────────────────────────────
 *
 * `CREATE OR REPLACE` then insert. An export that appends turns a repeated run into duplicated
 * rows, and a Power BI report built on it silently doubles every figure — which is the worst kind
 * of wrong, because it still looks like a report. Replacing means an export is idempotent: run it
 * twice and the warehouse holds one copy of the truth.
 *
 * ── What this class does not claim ───────────────────────────────────────────
 *
 * It has been built and tested against a faked HTTP client: the request shape, the escaping, the
 * batching and the error handling are covered. It has not been run against a live Databricks
 * workspace, so the first real export should be watched rather than assumed.
 */
class DatabricksExporter
{
    /**
     * Rows per INSERT. The Statement API takes a statement, not a stream, so a large dataset is
     * many statements — and one enormous statement is a request that times out somewhere with no
     * way to tell how much of it landed.
     */
    private const BATCH = 500;

    /** Long enough for a warehouse that has gone to sleep to wake up and answer. */
    private const TIMEOUT_SECONDS = 120;

    /**
     * Does this connection work?
     *
     * `SELECT 1` rather than reading a catalogue: it proves the host, the token and the warehouse
     * path together, which is exactly the set of things somebody gets wrong, and it needs no
     * permission the export itself will not need.
     *
     * @return array{ok: bool, error: ?string}
     */
    public function test(BiConnection $connection): array
    {
        try {
            $this->statement($connection, 'SELECT 1');

            return ['ok' => true, 'error' => null];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => Str::limit($e->getMessage(), 480)];
        }
    }

    /**
     * Write one dataset into the warehouse.
     *
     * @return array{table: string, rows: int}
     */
    public function export(BiConnection $connection, string $dataset, array $rows): array
    {
        // definition(), not all()[...]: the datasets are grouped by module now and the key that
        // names one is "module.dataset", so a flat lookup finds nothing and every export throws.
        $definition = BiDatasets::definition($dataset);

        if ($definition === null) {
            throw new RuntimeException("Unknown dataset [{$dataset}].");
        }

        $table = $this->qualify($connection, $definition['table']);
        $columns = $definition['columns'];

        $this->statement($connection, sprintf(
            'CREATE OR REPLACE TABLE %s (%s)',
            $table,
            collect($columns)->map(fn ($type, $name) => "{$this->identifier($name)} {$type}")->implode(', '),
        ));

        foreach (array_chunk($rows, self::BATCH) as $chunk) {
            $values = collect($chunk)->map(function (array $row) use ($columns) {
                $cells = collect($columns)->keys()
                    ->map(fn ($name) => $this->literal($row[$name] ?? null))
                    ->implode(', ');

                return "({$cells})";
            })->implode(', ');

            $this->statement($connection, sprintf(
                'INSERT INTO %s (%s) VALUES %s',
                $table,
                collect($columns)->keys()->map(fn ($n) => $this->identifier($n))->implode(', '),
                $values,
            ));
        }

        return ['table' => $table, 'rows' => count($rows)];
    }

    /** Catalog and schema when the tenant named them, so an export lands where they expect. */
    private function qualify(BiConnection $connection, string $table): string
    {
        return collect([$connection->catalog, $connection->schema_name, $table])
            ->filter()
            ->map(fn ($part) => $this->identifier($part))
            ->implode('.');
    }

    /**
     * A backtick-quoted identifier with any backtick doubled.
     *
     * These names come from our own dataset definitions and from a settings form, not from row
     * data — but a catalog name is still something a person types, and quoting it here is cheaper
     * than being sure forever that nobody ever types a backtick.
     */
    private function identifier(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }

    /**
     * A value as SQL.
     *
     * The Statement API supports parameter binding, and this deliberately does not use it: a batch
     * of five hundred rows across nine columns is four and a half thousand parameters in one
     * request, which is past what the API accepts. So values are escaped here, and the escaping is
     * the thing the tests are most pointed at — this is row data from a customer's own database,
     * and a project named `O'Brien's Yard` is not an attack but will break a naive quote.
     */
    private function literal(mixed $value): string
    {
        if ($value === null || $value === '') {
            return 'NULL';
        }

        if (is_bool($value)) {
            return $value ? 'TRUE' : 'FALSE';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        // Backslash as well as quote: Databricks SQL treats a backslash as an escape inside a
        // string literal, so a Windows path in a project name would otherwise eat the character
        // after it and, at the end of a value, the closing quote with it.
        $escaped = str_replace(['\\', "'"], ['\\\\', "''"], (string) $value);

        return "'{$escaped}'";
    }

    /** @return array<string, mixed> the API's parsed response */
    private function statement(BiConnection $connection, string $sql): array
    {
        $response = $this->client($connection)->post('/api/2.0/sql/statements', [
            'statement'     => $sql,
            'warehouse_id'  => $this->warehouseId($connection),
            // Synchronous up to the API's own ceiling, so a failure is reported to the caller that
            // asked for it rather than left as a statement id nobody polls.
            'wait_timeout'  => '50s',
            'on_wait_timeout' => 'CANCEL',
        ]);

        if ($response->failed()) {
            throw new RuntimeException(
                'Databricks refused the request: ' . Str::limit($response->body(), 400),
            );
        }

        $body = $response->json() ?? [];
        $state = $body['status']['state'] ?? null;

        // A 200 with a FAILED state is the normal way this API reports bad SQL, so the status code
        // alone is not the answer.
        if (in_array($state, ['FAILED', 'CANCELED', 'CLOSED'], true)) {
            throw new RuntimeException(
                'Databricks could not run the statement: '
                . ($body['status']['error']['message'] ?? $state),
            );
        }

        return $body;
    }

    /**
     * The warehouse id out of the SQL HTTP path.
     *
     * A tenant copies "/sql/1.0/warehouses/abc123" out of the Databricks UI because that is what
     * the UI gives them. The API wants the id on its own, so it is taken from the end rather than
     * asked for twice — two fields that must agree is two fields that will not.
     */
    private function warehouseId(BiConnection $connection): string
    {
        $path = trim((string) $connection->http_path, '/');

        if ($path === '') {
            throw new RuntimeException('This connection has no SQL warehouse path.');
        }

        return Str::afterLast($path, '/');
    }

    private function client(BiConnection $connection): PendingRequest
    {
        $host = rtrim((string) $connection->host, '/');

        if ($host === '') {
            throw new RuntimeException('This connection has no workspace host.');
        }

        if (! Str::startsWith($host, ['http://', 'https://'])) {
            $host = "https://{$host}";
        }

        if (! $connection->hasToken()) {
            throw new RuntimeException('This connection has no access token.');
        }

        return Http::baseUrl($host)
            ->withToken($connection->token())
            ->acceptJson()
            ->timeout(self::TIMEOUT_SECONDS);
    }
}
