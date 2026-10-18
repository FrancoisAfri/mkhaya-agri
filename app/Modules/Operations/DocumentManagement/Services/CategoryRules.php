<?php

namespace App\Modules\Operations\DocumentManagement\Services;

use App\Modules\Operations\DocumentManagement\Models\Document;
use App\Modules\Operations\DocumentManagement\Models\DocumentCategory;
use App\Modules\Operations\DocumentManagement\Models\DocumentCategoryField;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The rules that belong to a kind of document rather than to the repository.
 *
 * Every organisation has some, and none of them fit a single global setting. A contract needs a
 * counterparty and an end date; a certificate needs an issuer and an expiry; a policy needs neither
 * and does need approval before anybody reads it. Requiring all of that of everything makes filing
 * a scanned receipt an ordeal; requiring none of it leaves the contracts register with holes that
 * are found when somebody needs the counterparty.
 *
 * Numbering is the part most worth having. CTR-2027-0041 without anybody keeping a spreadsheet of
 * the last number used — and without two people uploading at the same moment getting the same one,
 * which is what a read-then-write would do and what the row lock below prevents.
 */
class CategoryRules
{
    /**
     * The extra fields a document of this kind carries.
     *
     * @return \Illuminate\Support\Collection<int, DocumentCategoryField>
     */
    public function fieldsFor(?DocumentCategory $category)
    {
        if (! $category) {
            return collect();
        }

        return DocumentCategoryField::query()
            ->where('category_id', $category->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * Checks the values given against what the category asks for.
     *
     * Returns the labels of what is missing rather than a boolean: "Counterparty and End date are
     * needed" is something somebody can act on, where "invalid" sends them hunting.
     *
     * @param array<string, mixed> $values
     * @return array{ok: bool, missing: list<string>}
     */
    public function validate(?DocumentCategory $category, array $values): array
    {
        $missing = [];

        foreach ($this->fieldsFor($category) as $field) {
            if (! $field->is_required) {
                continue;
            }

            // A checkbox left unticked is a legitimate "no". Requiring one would mean the only way
            // to file the document is to answer yes, which is not a question worth asking.
            if ($field->type === DocumentCategoryField::TYPE_BOOLEAN) {
                continue;
            }

            if (blank($values[$field->key] ?? null)) {
                $missing[] = $field->label;
            }
        }

        return ['ok' => $missing === [], 'missing' => $missing];
    }

    /**
     * Keeps only the values the category actually asks for, in the right shape.
     *
     * Anything else is dropped. Without this the column becomes whatever a form happened to post,
     * and a field renamed leaves its old values behind for ever with nothing to read them.
     *
     * @param array<string, mixed> $values
     * @return array<string, mixed>|null
     */
    public function clean(?DocumentCategory $category, array $values): ?array
    {
        $clean = [];

        foreach ($this->fieldsFor($category) as $field) {
            if (! array_key_exists($field->key, $values)) {
                continue;
            }

            $value = $values[$field->key];

            $clean[$field->key] = match ($field->type) {
                DocumentCategoryField::TYPE_NUMBER => is_numeric($value) ? 0 + $value : null,
                DocumentCategoryField::TYPE_BOOLEAN => (bool) $value,
                DocumentCategoryField::TYPE_DATE => blank($value) ? null : Carbon::parse($value)->toDateString(),
                // A choice not on the list is not a choice. Silently keeping it would mean a value
                // that renders as an option nobody can select again.
                DocumentCategoryField::TYPE_SELECT => in_array($value, $field->options ?? [], true) ? $value : null,
                default => blank($value) ? null : mb_substr((string) $value, 0, 500),
            };
        }

        return $clean === [] ? null : $clean;
    }

    /**
     * The next number for this kind of document.
     *
     * Locked and incremented inside a transaction. Reading the counter and writing it back would
     * hand the same number to two people uploading at the same moment, and a duplicate contract
     * number is discovered a year later by somebody who cannot tell which contract is meant.
     *
     * Placeholders: {YYYY} the year, {YY} its last two digits, {MM} the month, and a run of #
     * for the sequence, padded to however many were written. The hashes may be braced or bare —
     * people write {####} by analogy with the others, and a pattern that left the braces in the
     * number would be discovered by whoever had to read one aloud.
     */
    public function nextNumber(?DocumentCategory $category): ?string
    {
        if (! $category || blank($category->number_pattern)) {
            return null;
        }

        return DB::transaction(function () use ($category) {
            $locked = DocumentCategory::query()
                ->whereKey($category->id)
                ->lockForUpdate()
                ->first();

            if (! $locked) {
                return null;
            }

            $sequence = max(1, (int) $locked->number_next);

            DocumentCategory::query()->whereKey($locked->id)->update(['number_next' => $sequence + 1]);

            $now = Carbon::now();

            $number = str_replace(
                ['{YYYY}', '{YY}', '{MM}'],
                [$now->format('Y'), $now->format('y'), $now->format('m')],
                (string) $locked->number_pattern,
            );

            return preg_replace_callback(
                '/\{(#+)\}|(#+)/',
                function (array $match) use ($sequence) {
                    $hashes = $match[1] !== '' ? $match[1] : ($match[2] ?? '');

                    return str_pad((string) $sequence, strlen($hashes), '0', STR_PAD_LEFT);
                },
                $number,
            );
        });
    }

    /**
     * The workflow a document of this kind goes through, and whether it must.
     *
     * @return array{workflow_id: ?int, required: bool}
     */
    public function workflowFor(?DocumentCategory $category): array
    {
        return [
            'workflow_id' => $category?->default_workflow_id ? (int) $category->default_workflow_id : null,
            'required' => (bool) ($category?->workflow_required ?? false),
        ];
    }

    /**
     * What a document's extra fields say, ready to show.
     *
     * Read through the category's current fields rather than straight out of the column, so a value
     * left behind by a field somebody removed does not surface as a nameless row.
     *
     * @return list<array<string, mixed>>
     */
    public function describe(Document $document): array
    {
        $values = $document->custom_fields ?? [];

        return $this->fieldsFor($document->category)
            ->map(fn (DocumentCategoryField $field) => [
                'key' => $field->key,
                'label' => $field->label,
                'type' => $field->type,
                'value' => $values[$field->key] ?? null,
                'required' => (bool) $field->is_required,
            ])
            ->filter(fn (array $row) => $row['value'] !== null && $row['value'] !== '')
            ->values()
            ->all();
    }
}
