<?php

namespace App\Shared\Traits;

use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Keep an optional field that arrived empty out of a column that refuses nulls.
 *
 * There is a gap between what `NOT NULL DEFAULT …` protects and what a form actually sends. The
 * default covers an insert that leaves the column *out*; it does nothing for one that names the
 * column and passes null. A form with an optional field does exactly the latter every time somebody
 * leaves it blank: the input arrives as an empty string, `ConvertEmptyStringsToNull` turns it into
 * null, the validator's `nullable` rule waves it through, and the column rejects it.
 *
 * The result is a 500 on the most ordinary action there is. It was reported from the live site as
 * "null value in column amount violates not-null constraint" when somebody added a deal from an
 * account page before knowing what the deal was worth — and an audit found the same shape in 88
 * columns across 55 tables, every one of them a form field somebody could reasonably leave blank.
 *
 * The rule this applies is not an invention: it is the default the schema has already declared for
 * that column. Omitting the field and blanking the field now mean the same thing, which is what
 * anybody filling in the form assumed in the first place.
 *
 * A column with no default is deliberately left alone. There is nothing to fall back on, and
 * guessing would put made-up data in a column somebody decided was mandatory — that case needs a
 * validation rule or a nullable column, not a silent fill.
 *
 * A model may pin the values explicitly instead, which is worth doing where the intent is not
 * obvious from the schema alone:
 *
 *     protected array $nullDefaults = ['amount' => 0, 'currency' => 'ZAR'];
 */
trait KeepsDatabaseDefaults
{
    /** Distinguishes "no usable default" from a default that is legitimately null. */
    private const NO_LITERAL = "\0__no_literal__";

    /** Parsed defaults per table, for the life of the process. One schema read, not one per save. */
    private static array $schemaDefaults = [];

    public static function bootKeepsDatabaseDefaults(): void
    {
        static::saving(function (self $model): void {
            foreach ($model->resolveNullDefaults() as $column => $default) {
                if ($model->{$column} === null) {
                    $model->{$column} = $default;
                }
            }
        });
    }

    /** @return array<string, mixed> */
    protected function resolveNullDefaults(): array
    {
        if (isset($this->nullDefaults)) {
            return $this->nullDefaults;
        }

        $table = $this->getTable();

        return self::$schemaDefaults[$table] ??= $this->readSchemaDefaults($table);
    }

    /**
     * The NOT NULL columns this table defaults, and to what.
     *
     * Read from the live schema rather than hardcoded so the two cannot drift: a migration that
     * changes a default changes this with it. Failures are swallowed on purpose — this runs during
     * migrations and on a fresh install, where the table may not exist yet, and a model that
     * refuses to save because it could not introspect itself would be a worse bug than the one
     * this fixes.
     *
     * @return array<string, mixed>
     */
    private function readSchemaDefaults(string $table): array
    {
        try {
            $columns = Schema::connection($this->getConnectionName())->getColumns($table);
        } catch (Throwable) {
            return [];
        }

        $defaults = [];

        foreach ($columns as $column) {
            if ($column['nullable'] ?? true) {
                continue;
            }

            $value = $this->literalDefault($column['default'] ?? null);

            if ($value !== self::NO_LITERAL) {
                $defaults[$column['name']] = $value;
            }
        }

        return $defaults;
    }

    /**
     * The value of a column default, but only when it is unambiguously a literal.
     *
     * An allow-list, not a deny-list, and deliberately so. Postgres reports a default as the SQL
     * that produces it, and some of those are expressions this must never inline — the tenancy
     * columns default to
     * `(NULLIF(current_setting('app.company_id', true), ''))::bigint`, which is the row-level
     * security session variable. Writing that string into a bigint column would be a far worse bug
     * than the null it was meant to prevent, and on 158 NOT NULL `company_id` columns it would be
     * a tenancy failure rather than a crash.
     *
     * So: a quoted string, a number, a boolean, or a quoted JSON document. Anything else — a
     * function call, a sequence, an expression, a bare identifier — is left alone and the column
     * keeps whatever the caller gave it.
     */
    private function literalDefault(?string $default): mixed
    {
        if ($default === null) {
            return self::NO_LITERAL;
        }

        $raw = trim($default);

        // Split a trailing cast off: `'ZAR'::character varying`, `'[1,2]'::jsonb`, `0::numeric`.
        $cast = null;
        if (preg_match('/^(.*?)::([a-z0-9_ \[\]"]+)$/is', $raw, $m)) {
            $raw = trim($m[1]);
            $cast = strtolower(trim($m[2]));
        }

        if (preg_match("/^'(.*)'$/s", $raw, $m)) {
            $string = str_replace("''", "'", $m[1]);

            // A json column's default is a document, and the model almost certainly casts the
            // attribute to an array. Handing it the raw string would store it double-encoded.
            if ($cast !== null && str_starts_with($cast, 'json')) {
                $decoded = json_decode($string, true);

                return json_last_error() === JSON_ERROR_NONE ? $decoded : self::NO_LITERAL;
            }

            // Postgres quotes numeric defaults too — `'30'::smallint`. Give the model the number
            // it would have got from the column rather than the string Postgres printed.
            if ($cast !== null
                && preg_match('/^(smallint|integer|bigint|numeric|decimal|real|double)/', $cast)
                && preg_match('/^-?\d+(\.\d+)?$/', $string)) {
                return $string + 0;
            }

            return $string;
        }

        return match (strtolower($raw)) {
            'true'  => true,
            'false' => false,
            default => preg_match('/^-?\d+(\.\d+)?$/', $raw) ? $raw + 0 : self::NO_LITERAL,
        };
    }

}
