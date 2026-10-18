<?php

namespace App\Modules\Workflow\Services;

use App\Modules\Workflow\Exceptions\WorkflowException;
use App\Modules\Workflow\Models\WorkflowForm;
use App\Modules\Workflow\Models\WorkflowFormVersion;
use App\Modules\Workflow\Support\Conditions;
use Illuminate\Support\Facades\DB;

/**
 * Forms (PRS §6): definitions are versioned; values are checked here, on the server, whatever the
 * browser showed. A hidden field is dropped, a field required only under a condition is required
 * only then, calculated fields are computed here (never trusted from the client), and repeating
 * line items are checked row by row.
 */
class FormService
{
    public const TYPES = [
        'text' => 'Single-line text', 'textarea' => 'Multi-line text', 'number' => 'Whole number', 'decimal' => 'Decimal',
        'currency' => 'Amount (R)', 'date' => 'Date', 'time' => 'Time', 'daterange' => 'Date range',
        'select' => 'Dropdown', 'multiselect' => 'Multi-select', 'radio' => 'Radio buttons', 'checkboxes' => 'Checkboxes',
        'yesno' => 'Yes / no', 'email' => 'Email', 'phone' => 'Telephone', 'url' => 'Web address',
        'user' => 'Employee', 'users' => 'Employees (several)', 'department' => 'Department', 'supplier' => 'Supplier',
        'attachment' => 'Attachment', 'calculated' => 'Calculated value', 'acknowledgement' => 'Acknowledgement / signature',
        'line_items' => 'Repeating line items', 'heading' => 'Heading / instructions',
    ];

    public function __construct(private readonly WorkflowAudit $audit)
    {
    }

    public function create(string $name, ?string $description, array $fields): WorkflowForm
    {
        return DB::transaction(function () use ($name, $description, $fields) {
            $form = WorkflowForm::create(['name' => $name, 'description' => $description, 'created_by' => auth()->id()]);
            $this->saveVersion($form, $fields);

            return $form->refresh();
        });
    }

    /** Save the fields as a new version (an existing version never changes). */
    public function saveVersion(WorkflowForm $form, array $fields): WorkflowFormVersion
    {
        $problems = $this->schemaProblems($fields);
        if ($problems) {
            throw new WorkflowException(implode(' ', $problems));
        }

        return DB::transaction(function () use ($form, $fields) {
            $n = (int) $form->versions()->max('version') + 1;
            $v = WorkflowFormVersion::create(['company_id' => $form->company_id, 'form_id' => $form->id, 'version' => $n, 'fields' => array_values($fields), 'created_by' => auth()->id()]);
            $form->current_version_id = $v->id;
            $form->save();
            $this->audit->record('form.saved', $form, ['after' => ['version' => $n, 'fields' => count($fields)]]);

            return $v;
        });
    }

    /** @return list<string> */
    public function schemaProblems(array $fields, string $prefix = ''): array
    {
        $out = [];
        $keys = [];
        foreach ($fields as $i => $f) {
            $key = (string) ($f['key'] ?? '');
            $label = $f['label'] ?? $key;
            if (! preg_match('/^[a-z][a-z0-9_]{0,59}$/', $key)) {
                $out[] = "{$prefix}Field " . ($i + 1) . ' needs a key of lower-case letters, digits and underscores.';
            } elseif (in_array($key, ['record', 'requester'], true)) {
                $out[] = "{$prefix}\"{$key}\" is reserved; choose another key.";
            } elseif (isset($keys[$key])) {
                $out[] = "{$prefix}Two fields use the key \"{$key}\".";
            }
            $keys[$key] = true;
            if (! isset(self::TYPES[$f['type'] ?? ''])) {
                $out[] = "{$prefix}\"{$label}\" has an unknown type.";
            }
            if (in_array($f['type'] ?? '', ['select', 'multiselect', 'radio', 'checkboxes'], true) && empty($f['options'])) {
                $out[] = "{$prefix}\"{$label}\" needs at least one option.";
            }
            foreach (['visible_if', 'required_if'] as $c) {
                foreach (Conditions::problems($f[$c] ?? null) as $p) {
                    $out[] = "{$prefix}\"{$label}\": {$p}";
                }
            }
            if (($f['type'] ?? '') === 'line_items') {
                $out = array_merge($out, $this->schemaProblems($f['columns'] ?? [], "\"{$label}\" → "));
                if (empty($f['columns'])) {
                    $out[] = "{$prefix}\"{$label}\" needs at least one column.";
                }
            }
            if (($f['type'] ?? '') === 'calculated' && ! in_array($f['formula']['op'] ?? null, ['sum', 'multiply', 'add', 'subtract', 'sum_column'], true)) {
                $out[] = "{$prefix}\"{$label}\" needs a calculation (sum, multiply, add, subtract or column total).";
            }
        }

        return $out;
    }

    /**
     * Check and clean submitted values against a version's fields.
     *
     * @param  array<string, mixed>  $context  extra data conditions may refer to (record.*, requester.*)
     * @return array{0: array<string, mixed>, 1: array<string, string>} [clean values, errors by field]
     */
    public function validate(array $fields, array $values, array $context = [], bool $partial = false): array
    {
        $clean = [];
        $errors = [];
        // Calculated values first, so conditions can use them.
        $values = $this->calculate($fields, $values);
        $scope = array_merge($context, $values);

        foreach ($fields as $f) {
            $key = $f['key'];
            $type = $f['type'];
            if ($type === 'heading') {
                continue;
            }
            if (! empty($f['visible_if']) && ! Conditions::evaluate($f['visible_if'], $scope)) {
                continue; // hidden: neither required nor kept
            }
            $v = $values[$key] ?? ($f['default'] ?? null);
            $required = ! empty($f['required']) || (! empty($f['required_if']) && Conditions::evaluate($f['required_if'], $scope));
            if (blank($v) && ! (is_bool($v))) {
                if ($required && ! $partial && $type !== 'calculated') {
                    $errors[$key] = ($f['label'] ?? $key) . ' is required.';
                }
                continue;
            }
            [$v, $error] = $this->check($f, $v);
            if ($error) {
                $errors[$key] = $error;

                continue;
            }
            $clean[$key] = $v;
        }

        return [$clean, $errors];
    }

    /** @return array{0:mixed, 1:?string} */
    private function check(array $f, mixed $v): array
    {
        $label = $f['label'] ?? $f['key'];
        $rules = $f['validation'] ?? [];
        switch ($f['type']) {
            case 'number':
                if (filter_var($v, FILTER_VALIDATE_INT) === false) {
                    return [null, "{$label} must be a whole number."];
                }
                $v = (int) $v;
                break;
            case 'decimal':
            case 'currency':
                if (! is_numeric($v)) {
                    return [null, "{$label} must be a number."];
                }
                $v = bcadd((string) $v, '0', $f['type'] === 'currency' ? 2 : 4);
                break;
            case 'date':
                if (! $this->isDate($v)) {
                    return [null, "{$label} must be a date."];
                }
                break;
            case 'time':
                if (! preg_match('/^\d{2}:\d{2}$/', (string) $v)) {
                    return [null, "{$label} must be a time (HH:MM)."];
                }
                break;
            case 'daterange':
                if (! is_array($v) || count($v) !== 2 || ! $this->isDate($v[0] ?? null) || ! $this->isDate($v[1] ?? null) || $v[1] < $v[0]) {
                    return [null, "{$label} needs a start and an end date, in order."];
                }
                break;
            case 'select':
            case 'radio':
                if (! in_array((string) $v, array_map('strval', $f['options'] ?? []), true)) {
                    return [null, "{$label}: choose one of the options."];
                }
                break;
            case 'multiselect':
            case 'checkboxes':
                $v = array_values((array) $v);
                if (array_diff(array_map('strval', $v), array_map('strval', $f['options'] ?? []))) {
                    return [null, "{$label}: choose from the options."];
                }
                break;
            case 'yesno':
            case 'acknowledgement':
                $v = filter_var($v, FILTER_VALIDATE_BOOLEAN);
                if ($f['type'] === 'acknowledgement' && ! empty($f['required']) && ! $v) {
                    return [null, "{$label} must be acknowledged."];
                }
                break;
            case 'email':
                if (! filter_var($v, FILTER_VALIDATE_EMAIL)) {
                    return [null, "{$label} must be an email address."];
                }
                break;
            case 'url':
                if (! filter_var($v, FILTER_VALIDATE_URL) || ! preg_match('#^https?://#i', (string) $v)) {
                    return [null, "{$label} must be a web address starting with http(s)://."];
                }
                break;
            case 'phone':
                if (! preg_match('/^\+?\d[\d\s-]{6,19}$/', (string) $v)) {
                    return [null, "{$label} must be a telephone number."];
                }
                break;
            case 'user':
                if (! DB::table('users')->where('id', (int) $v)->where('company_id', \App\Modules\Workflow\Support\WorkflowCompany::id())->exists()) {
                    return [null, "{$label}: choose a person from this company."];
                }
                $v = (int) $v;
                break;
            case 'users':
                $v = array_values(array_unique(array_map('intval', (array) $v)));
                $found = DB::table('users')->whereIn('id', $v)->where('company_id', \App\Modules\Workflow\Support\WorkflowCompany::id())->count();
                if (! $v || $found !== count($v)) {
                    return [null, "{$label}: choose people from this company."];
                }
                break;
            case 'department':
                if (! DB::table('company_units')->where('id', (int) $v)->where('company_id', \App\Modules\Workflow\Support\WorkflowCompany::id())->exists()) {
                    return [null, "{$label}: choose a department."];
                }
                $v = (int) $v;
                break;
            case 'attachment':
                $v = array_values(array_filter(array_map('intval', (array) $v)));
                break;
            case 'line_items':
                if (! is_array($v)) {
                    return [null, "{$label} must be a list of lines."];
                }
                $rows = [];
                foreach (array_values($v) as $i => $row) {
                    [$cleanRow, $rowErrors] = $this->validate($f['columns'] ?? [], (array) $row);
                    if ($rowErrors) {
                        return [null, "{$label}, line " . ($i + 1) . ': ' . implode(' ', $rowErrors)];
                    }
                    $rows[] = $cleanRow;
                }
                if (isset($f['min_rows']) && count($rows) < (int) $f['min_rows']) {
                    return [null, "{$label} needs at least {$f['min_rows']} line(s)."];
                }
                $v = $rows;
                break;
            default:
                $v = is_scalar($v) ? trim((string) $v) : null;
                if ($v === null) {
                    return [null, "{$label} is not valid."];
                }
        }
        if (isset($rules['min']) && is_numeric($v) && bccomp((string) $v, (string) $rules['min'], 4) < 0) {
            return [null, "{$label} must be at least {$rules['min']}."];
        }
        if (isset($rules['max']) && is_numeric($v) && bccomp((string) $v, (string) $rules['max'], 4) > 0) {
            return [null, "{$label} must be at most {$rules['max']}."];
        }
        if (isset($rules['max_length']) && is_string($v) && mb_strlen($v) > (int) $rules['max_length']) {
            return [null, "{$label} must be at most {$rules['max_length']} characters."];
        }
        if (isset($rules['min_length']) && is_string($v) && mb_strlen($v) < (int) $rules['min_length']) {
            return [null, "{$label} must be at least {$rules['min_length']} characters."];
        }
        if (! empty($rules['pattern']) && is_string($v) && isset(Conditions::PATTERNS[$rules['pattern']]) && ! preg_match(Conditions::PATTERNS[$rules['pattern']], $v)) {
            return [null, $rules['message'] ?? "{$label} is not in the expected format."];
        }

        return [$v, null];
    }

    /** Computes calculated fields from the other values (money with bcmath). */
    public function calculate(array $fields, array $values): array
    {
        foreach ($fields as $f) {
            if (($f['type'] ?? '') !== 'calculated') {
                continue;
            }
            $op = $f['formula']['op'] ?? 'sum';
            $refs = (array) ($f['formula']['fields'] ?? []);
            $num = fn ($x) => is_numeric($x) ? (string) $x : '0';
            $result = match ($op) {
                'sum', 'add' => array_reduce($refs, fn ($c, $r) => bcadd($c, $num(data_get($values, $r)), 4), '0'),
                'multiply' => array_reduce($refs, fn ($c, $r) => bcmul($c, $num(data_get($values, $r)), 4), '1'),
                'subtract' => array_reduce(array_slice($refs, 1), fn ($c, $r) => bcsub($c, $num(data_get($values, $r)), 4), $num(data_get($values, $refs[0] ?? ''))),
                'sum_column' => array_reduce((array) ($values[$f['formula']['list'] ?? ''] ?? []), function ($c, $row) use ($f, $num) {
                    $cols = (array) ($f['formula']['columns'] ?? []);
                    $line = count($cols) > 1 ? array_reduce($cols, fn ($p, $col) => bcmul($p, $num($row[$col] ?? 0), 4), '1') : $num($row[$cols[0] ?? ''] ?? 0);

                    return bcadd($c, $line, 4);
                }, '0'),
                default => '0',
            };
            $values[$f['key']] = bcadd($result, '0', 2);
        }

        return $values;
    }

    /** Field keys flagged sensitive (masked in notifications and logs). */
    public function sensitive(array $fields): array
    {
        return array_values(array_map(fn ($f) => $f['key'], array_filter($fields, fn ($f) => ! empty($f['sensitive']))));
    }

    private function isDate(mixed $v): bool
    {
        return is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && checkdate((int) substr($v, 5, 2), (int) substr($v, 8, 2), (int) substr($v, 0, 4));
    }
}
