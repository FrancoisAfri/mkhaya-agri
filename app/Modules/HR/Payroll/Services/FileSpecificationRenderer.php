<?php

namespace App\Modules\HR\Payroll\Services;

use App\Modules\HR\Payroll\Models\FileSpecification;
use App\Modules\HR\Payroll\Models\FileSpecificationField;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Builds an outbound file from a layout that lives in the database.
 *
 * The alternative, which is what the ACB bank file does today, is PHP that concatenates `str_pad()`
 * calls. That works exactly until the vendor revises the format — and then a bank file that moves
 * people's salaries needs a code change, a review and a deploy, usually at short notice, usually in
 * the same week payroll is due. Here the revision is data entry by whoever has the vendor's document
 * open, and last year's version stays intact for reprinting last year's file.
 *
 * Values come from tokens rather than expressions. A layout editor is not a place to run arbitrary
 * code typed by whoever last spoke to the vendor, and the set below covers what these files actually
 * need: a value from the row, a value from the run, a constant, a count, a total, and today's date.
 */
class FileSpecificationRenderer
{
    /**
     * Render a file.
     *
     * @param  array<string, mixed>  $context  values addressed as `run.total_net`, `company.name`
     * @param  list<array<string, mixed>>  $rows  one per detail line, addressed as `employee.name`
     */
    public function render(FileSpecification $spec, array $context, array $rows): string
    {
        $fields = $spec->relationLoaded('fields') ? $spec->fields : $spec->fields()->get();
        $byRecord = $fields->groupBy('record_type');

        $lines = [];

        if ($byRecord->has(FileSpecification::HEADER)) {
            $lines[] = $this->line($spec, $byRecord[FileSpecification::HEADER], $context, $rows);
        }

        foreach ($rows as $row) {
            if (! $byRecord->has(FileSpecification::DETAIL)) {
                break;
            }

            $lines[] = $this->line($spec, $byRecord[FileSpecification::DETAIL], $context + $row, $rows);
        }

        if ($byRecord->has(FileSpecification::TRAILER)) {
            $lines[] = $this->line($spec, $byRecord[FileSpecification::TRAILER], $context, $rows);
        }

        return implode($this->lineEnding($spec), $lines) . $this->lineEnding($spec);
    }

    /**
     * Check a layout makes sense before anybody builds a file with it.
     *
     * Overlaps and gaps are the two ways a transcribed fixed-width spec goes wrong, and both produce
     * a file that looks plausible and is rejected — or worse, parsed with every field shifted by one.
     *
     * @return list<string> the problems, empty when the layout is sound
     */
    public function validateSpecification(FileSpecification $spec): array
    {
        $problems = [];
        $fields = $spec->relationLoaded('fields') ? $spec->fields : $spec->fields()->get();

        if ($fields->isEmpty()) {
            return ['The layout has no fields.'];
        }

        foreach ($fields->groupBy('record_type') as $recordType => $group) {
            if ($spec->format === FileSpecification::FORMAT_FIXED) {
                $problems = array_merge($problems, $this->fixedWidthProblems($recordType, $group));
            }

            foreach ($group as $field) {
                if (blank($field->source)) {
                    $problems[] = "{$recordType}: field '{$field->name}' has no source.";
                    continue;
                }

                if (! $this->isKnownToken($field->source)) {
                    $problems[] = "{$recordType}: field '{$field->name}' uses an unrecognised source '{$field->source}'.";
                }

                if ($field->type === FileSpecificationField::DATE && blank($field->date_format)) {
                    $problems[] = "{$recordType}: date field '{$field->name}' has no date format.";
                }
            }
        }

        return $problems;
    }

    /** @param \Illuminate\Support\Collection<int, FileSpecificationField> $fields */
    private function fixedWidthProblems(string $recordType, $fields): array
    {
        $problems = [];
        $expected = 1;

        foreach ($fields->sortBy('position') as $field) {
            if (! $field->position || ! $field->length) {
                $problems[] = "{$recordType}: field '{$field->name}' has no position or length, which a fixed-width layout needs.";
                continue;
            }

            if ($field->position > $expected) {
                $gap = $field->position - $expected;
                $problems[] = "{$recordType}: {$gap} unclaimed character(s) before '{$field->name}' at position {$field->position}.";
            } elseif ($field->position < $expected) {
                $problems[] = "{$recordType}: '{$field->name}' starts at {$field->position}, inside the field before it.";
            }

            $expected = $field->position + $field->length;
        }

        return $problems;
    }

    /** @param \Illuminate\Support\Collection<int, FileSpecificationField> $fields */
    private function line(FileSpecification $spec, $fields, array $context, array $rows): string
    {
        if ($spec->format === FileSpecification::FORMAT_DELIMITED) {
            $values = $fields->sortBy('sequence')
                ->map(fn (FileSpecificationField $f) => $this->formatted($f, $this->resolve($f, $context, $rows)));

            return $values->implode($spec->delimiter ?: ',');
        }

        // Fixed width: each field is written at the position the vendor's document gives it, so a
        // layout with a gap in it produces spaces rather than a silently shortened line.
        $line = '';

        foreach ($fields->sortBy('position') as $field) {
            $at = max(0, ((int) $field->position) - 1);

            if (strlen($line) < $at) {
                $line = str_pad($line, $at);
            }

            $line = substr($line, 0, $at) . $this->fixed($field, $this->resolve($field, $context, $rows));
        }

        return $spec->pad_to_length ? $line : rtrim($line);
    }

    /** One value, padded and truncated to exactly the width the layout gives it. */
    private function fixed(FileSpecificationField $field, mixed $value): string
    {
        $text = $this->formatted($field, $value);
        $length = (int) ($field->length ?: strlen($text));

        // Truncated from the *left* for numbers and the right for text: losing the leading digits of
        // an amount changes it by orders of magnitude, while a name cut short is still the person.
        if (strlen($text) > $length) {
            return in_array($field->type, [FileSpecificationField::NUMBER, FileSpecificationField::CENTS], true)
                ? substr($text, -$length)
                : substr($text, 0, $length);
        }

        return $field->padSide() === 'left'
            ? str_pad($text, $length, $field->padCharacter(), STR_PAD_LEFT)
            : str_pad($text, $length, $field->padCharacter(), STR_PAD_RIGHT);
    }

    private function formatted(FileSpecificationField $field, mixed $value): string
    {
        if (blank($value) && filled($field->default_value)) {
            $value = $field->default_value;
        }

        $text = match ($field->type) {
            // Whole cents, no separator — what most bank files want, and the one conversion people
            // get wrong by hand often enough that it belongs here rather than at each call site.
            FileSpecificationField::CENTS  => (string) (int) round(((float) $value) * 100),
            FileSpecificationField::NUMBER => number_format((float) $value, (int) $field->decimals, '.', ''),
            FileSpecificationField::DATE   => blank($value)
                ? ''
                : Carbon::parse($value)->format($field->date_format ?: 'Ymd'),
            default => (string) ($value ?? ''),
        };

        // Anything outside plain ASCII is stripped rather than passed on: these files are read by
        // mainframes that answer a Ž with a rejected batch, and a name with an accent in it is not
        // worth a failed payment run.
        $text = preg_replace('/[^\x20-\x7E]/', '', $text) ?? '';

        return $field->uppercase ? strtoupper($text) : $text;
    }

    /**
     * Resolve one field's source token.
     *
     * Deliberately a small closed set. Everything these files need is a value from the current row,
     * a value from the run, a constant, how many detail lines there are, what they add up to, or the
     * date — and the moment this becomes an expression language it becomes a way to run code from a
     * settings screen.
     */
    private function resolve(FileSpecificationField $field, array $context, array $rows): mixed
    {
        $source = trim((string) $field->source);

        if (str_starts_with($source, 'literal:')) {
            return substr($source, 8);
        }

        if (str_starts_with($source, 'sum:')) {
            $key = substr($source, 4);

            return array_sum(array_map(fn ($row) => (float) data_get($row, $key, 0), $rows));
        }

        if ($source === 'count' || $source === 'count:detail') {
            return count($rows);
        }

        if ($source === 'today') {
            return Carbon::now();
        }

        return data_get($context, $source);
    }

    private function isKnownToken(string $source): bool
    {
        $source = trim($source);

        return str_starts_with($source, 'literal:')
            || str_starts_with($source, 'sum:')
            || in_array($source, ['count', 'count:detail', 'today'], true)
            // A dotted path into the context. Anything else is a typo, and a typo that resolves to
            // an empty string is a file that is quietly wrong rather than loudly refused.
            || (bool) preg_match('/^[a-z_][a-z0-9_]*(\.[a-z0-9_]+)*$/i', $source);
    }

    /**
     * Build the file and refuse it if a required field came out empty.
     *
     * Separate from `render` because most callers want the guarantee and a preview screen does not:
     * a layout half-transcribed should still show what it produces so far.
     */
    public function build(FileSpecification $spec, array $context, array $rows): string
    {
        $missing = [];
        $fields = $spec->relationLoaded('fields') ? $spec->fields : $spec->fields()->get();

        foreach ($fields->where('required', true) as $field) {
            $scopes = $field->record_type === FileSpecification::DETAIL
                ? array_map(fn ($row) => $context + $row, $rows)
                : [$context];

            foreach ($scopes as $index => $scope) {
                if (blank($this->resolve($field, $scope, $rows)) && blank($field->default_value)) {
                    $where = $field->record_type === FileSpecification::DETAIL ? ' (line ' . ($index + 1) . ')' : '';
                    $missing[] = $field->name . $where;
                }
            }
        }

        if ($missing) {
            throw ValidationException::withMessages([
                'file' => 'The file cannot be built — these required fields are empty: '
                    . implode(', ', array_slice(array_unique($missing), 0, 10)) . '.',
            ]);
        }

        return $this->render($spec, $context, $rows);
    }

    private function lineEnding(FileSpecification $spec): string
    {
        return match ($spec->line_ending) {
            'lf'   => "\n",
            'cr'   => "\r",
            default => "\r\n",   // what every South African banking format asks for
        };
    }

    /**
     * The tokens a layout editor can offer, so nobody has to guess the spelling.
     *
     * @return array<string, list<string>>
     */
    public static function availableTokens(array $context = [], array $sampleRow = []): array
    {
        return [
            'run'      => array_keys(Arr::dot($context)),
            'row'      => array_keys(Arr::dot($sampleRow)),
            'computed' => ['count', 'sum:<field>', 'literal:<value>', 'today'],
        ];
    }
}
