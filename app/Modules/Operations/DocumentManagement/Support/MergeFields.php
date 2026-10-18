<?php

namespace App\Modules\Operations\DocumentManagement\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * What `{{employee.name}}` reads.
 *
 * A declared map rather than "any attribute on the model", for three reasons that all matter.
 *
 * A template author needs a list to pick from, and "whatever columns exist" is not a list anybody
 * can be shown. Exposing every column would put `password_reset_token` and `id_number` one typo
 * away from a printed contract. And a column renamed in another module would silently start
 * producing blanks in documents nobody re-reads — declaring the fields means the rename shows up
 * here as a field that stops resolving, which is a thing somebody notices.
 *
 * Fields whose column is absent are dropped rather than offered, so a template cannot be built on
 * something this platform does not have.
 */
class MergeFields
{
    /**
     * The fields each kind of record offers.
     *
     * `label` is what an author picks from a list. `column` is where the value comes from — a
     * string for a plain column, or a closure where the answer is assembled or formatted.
     *
     * @return array<string, array<string, array{label:string, column:string|callable, table?:string}>>
     */
    public static function map(): array
    {
        return [
            DocumentRelations::EMPLOYEE => [
                'name' => ['label' => 'Full name', 'column' => fn ($r) => trim(($r->first_name ?? '') . ' ' . ($r->surname ?? ''))],
                'first_name' => ['label' => 'First name', 'column' => 'first_name'],
                'surname' => ['label' => 'Surname', 'column' => 'surname'],
                'number' => ['label' => 'Employee number', 'column' => 'employee_number'],
                'email' => ['label' => 'Email', 'column' => 'email'],
                'position' => ['label' => 'Job title', 'column' => 'job_function'],
                'start_date' => ['label' => 'Start date', 'column' => 'date_joined'],
                'id_number' => ['label' => 'ID number', 'column' => 'id_number'],
                'department' => ['label' => 'Department', 'column' => fn ($r) => $r->department ?? null],
            ],
            DocumentRelations::SUPPLIER => [
                'name' => ['label' => 'Supplier name', 'column' => 'company_name'],
                'trading_name' => ['label' => 'Trading name', 'column' => 'trading_name'],
                'number' => ['label' => 'Supplier number', 'column' => 'supplier_number'],
                'registration' => ['label' => 'Registration number', 'column' => 'company_registration_number'],
                'vat' => ['label' => 'VAT number', 'column' => 'vat_registration_number'],
                'email' => ['label' => 'Email', 'column' => 'email'],
                'phone' => ['label' => 'Telephone', 'column' => 'phone_number'],
                'address' => ['label' => 'Address', 'column' => 'physical_address'],
            ],
            DocumentRelations::CONTRACT => [
                'title' => ['label' => 'Contract title', 'column' => 'title'],
                'number' => ['label' => 'Contract number', 'column' => 'contract_number'],
                'start_date' => ['label' => 'Start date', 'column' => 'start_date'],
                'end_date' => ['label' => 'End date', 'column' => 'end_date'],
                'value' => ['label' => 'Contract value', 'column' => 'contract_value'],
            ],
            DocumentRelations::CUSTOMER => [
                'name' => ['label' => 'Customer name', 'column' => 'name'],
            ],
            DocumentRelations::PROJECT => [
                'name' => ['label' => 'Project name', 'column' => 'name'],
                'code' => ['label' => 'Project code', 'column' => 'code'],
            ],
            DocumentRelations::ASSET => [
                'name' => ['label' => 'Asset name', 'column' => 'name'],
                'code' => ['label' => 'Asset code', 'column' => 'asset_code'],
                'serial' => ['label' => 'Serial number', 'column' => 'serial_number'],
            ],
        ];
    }

    /**
     * The fields a template about this kind of record can use, dropping any whose column is gone.
     *
     * @return array<string, string> field => label
     */
    public static function availableFor(?string $relation): array
    {
        $definition = DocumentRelations::definition((string) $relation);
        $fields = self::map()[$relation] ?? [];

        if (! $definition || $fields === []) {
            return self::companyFields();
        }

        $table = (new $definition['model'])->getTable();

        $usable = [];

        foreach ($fields as $key => $field) {
            // A closure works something out and is kept; a named column is only offered if it is
            // actually there, so a template cannot be written against a field that has been
            // renamed in another module.
            if (is_callable($field['column']) || Schema::hasColumn($table, $field['column'])) {
                $usable[$relation . '.' . $key] = $field['label'];
            }
        }

        return $usable + self::companyFields();
    }

    /**
     * Fields available to every template regardless of subject.
     *
     * Today's date earns its place: almost every generated document is dated, and a template author
     * should not have to think about how.
     *
     * @return array<string, string>
     */
    public static function companyFields(): array
    {
        return [
            'company.name' => 'Company name',
            'today' => 'Today’s date',
            'today.long' => 'Today’s date, written out',
        ];
    }

    /**
     * Reads every field for one record.
     *
     * @return array{values: array<string, string>, missing: list<string>}
     */
    public static function resolve(?string $relation, ?Model $record): array
    {
        $values = [
            'company.name' => (string) (\App\Modules\Tenancy\Models\Company::query()->value('name') ?? ''),
            'today' => now()->toDateString(),
            'today.long' => now()->toFormattedDateString(),
        ];

        $missing = [];
        $fields = self::map()[$relation] ?? [];

        foreach ($fields as $key => $field) {
            $name = $relation . '.' . $key;

            if (! $record) {
                $missing[] = $name;

                continue;
            }

            $raw = is_callable($field['column'])
                ? ($field['column'])($record)
                : ($record->{$field['column']} ?? null);

            if ($raw === null || $raw === '') {
                // Recorded rather than blanked. A contract with an empty salary line is a contract
                // somebody signs without noticing it is empty.
                $missing[] = $name;
                $values[$name] = '';

                continue;
            }

            $values[$name] = self::format($raw);
        }

        return ['values' => $values, 'missing' => $missing];
    }

    /**
     * A value as it should read in a document.
     *
     * Dates written out rather than as 2026-06-30, and money with its separators — a generated
     * contract is read by people, and "450000.00" in a contract is a value somebody has to squint
     * at to check.
     */
    private static function format(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value)->toFormattedDateString();
        }

        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        if (is_numeric($value) && ! is_int($value) && Str::contains((string) $value, '.')) {
            return number_format((float) $value, 2);
        }

        return (string) $value;
    }

    /**
     * Fills a body with values.
     *
     * `{{ employee.name }}` with or without spaces, because a template author will type both and
     * being strict about whitespace produces a document with the placeholder still in it.
     *
     * A field nothing answers is left as-is rather than blanked, so it is visible in the preview
     * instead of leaving a gap nobody can explain.
     *
     * @param  array<string, string>  $values
     */
    public static function fill(string $body, array $values): string
    {
        return preg_replace_callback('/\{\{\s*([a-z0-9_.]+)\s*\}\}/i', function ($matches) use ($values) {
            $key = strtolower(trim($matches[1]));

            return array_key_exists($key, $values)
                ? e($values[$key])
                : $matches[0];
        }, $body) ?? $body;
    }

    /**
     * The fields a body actually uses.
     *
     * @return list<string>
     */
    public static function used(string $body): array
    {
        preg_match_all('/\{\{\s*([a-z0-9_.]+)\s*\}\}/i', $body, $matches);

        return array_values(array_unique(array_map('strtolower', $matches[1] ?? [])));
    }
}
