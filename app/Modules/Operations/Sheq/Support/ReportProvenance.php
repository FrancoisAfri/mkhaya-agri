<?php

namespace App\Modules\Operations\Sheq\Support;

use Illuminate\Support\Carbon;

/**
 * What an exported report has to say about itself (CMD-8).
 *
 * CMD-8 asks that every export carry its filters, its date period, a generated timestamp and a
 * report owner. That reads like metadata housekeeping and it is not: a spreadsheet of incident
 * counts with none of those four things on it is the single most common source of argument in a
 * safety meeting, because two people have each exported one, the numbers differ, and there is no
 * way to tell whether that is because the filters differed, the periods differed, or one of them
 * is three weeks old. All four, on the face of the document, settles it in one glance.
 *
 * Held as one object rather than assembled per format so the CSV, the spreadsheet and the PDF
 * cannot disagree — which would be the same failure one level down.
 */
class ReportProvenance
{
    /**
     * @param  array<string,string|null>  $filters  Label => the value as the reader chose it,
     *   not as the query used it. "Site: Tower block", never "site_id: 19496".
     */
    public function __construct(
        public readonly string $title,
        public readonly Carbon $from,
        public readonly Carbon $to,
        public readonly array $filters = [],
        public readonly ?string $ownerName = null,
        public readonly ?string $ownerEmail = null,
        public readonly ?Carbon $generatedAt = null,
        public readonly ?string $dataAsAt = null,
    ) {}

    public function generated(): Carbon
    {
        return $this->generatedAt ?? Carbon::now();
    }

    /** The owner as one line. Unattributed is said out loud rather than left blank. */
    public function owner(): string
    {
        if ($this->ownerName === null && $this->ownerEmail === null) {
            return 'Unattributed — exported without a signed-in user';
        }

        return trim(($this->ownerName ?? '').
            ($this->ownerEmail !== null ? " <{$this->ownerEmail}>" : ''));
    }

    public function period(): string
    {
        return $this->from->toDateString().' to '.$this->to->toDateString();
    }

    /**
     * The preamble rows for a CSV or a spreadsheet.
     *
     * Above the headings, blank-line separated, so a human opening it in Excel reads the
     * provenance first and a script skipping to the heading row is not confused by a merged
     * header block.
     *
     * @return list<list<string>>
     */
    public function headerRows(): array
    {
        $rows = [
            ['Report', $this->title],
            ['Period', $this->period()],
            ['Generated', $this->generated()->toDateTimeString().' ('.
                $this->generated()->format('T').')'],
            ['Report owner', $this->owner()],
        ];

        if ($this->dataAsAt !== null) {
            // Separate from "generated", and the distinction matters: a report generated this
            // morning from a projection computed last Tuesday is a stale report with a fresh
            // timestamp, which is the most misleading document this module can produce.
            $rows[] = ['Figures computed', $this->dataAsAt];
        }

        foreach ($this->filters as $label => $value) {
            $rows[] = ['Filter — '.$label, $value === null || $value === '' ? 'All' : $value];
        }

        // A blank row, so the heading row below is unambiguous to both a person and a parser.
        $rows[] = [];

        return $rows;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'period' => $this->period(),
            'from' => $this->from->toDateString(),
            'to' => $this->to->toDateString(),
            'generated_at' => $this->generated()->toDateTimeString(),
            'timezone' => $this->generated()->format('T'),
            'owner' => $this->owner(),
            'data_as_at' => $this->dataAsAt,
            'filters' => array_map(
                fn ($v) => $v === null || $v === '' ? 'All' : $v, $this->filters),
        ];
    }

    /** A filename that carries the period, so two downloads do not overwrite each other. */
    public function filename(string $key, string $extension): string
    {
        return sprintf('sheq-%s-%s-to-%s.%s',
            str_replace('_', '-', $key),
            $this->from->toDateString(), $this->to->toDateString(), $extension);
    }
}
