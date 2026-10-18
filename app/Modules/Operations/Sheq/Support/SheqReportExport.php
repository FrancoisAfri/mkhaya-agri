<?php

namespace App\Modules\Operations\Sheq\Support;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * A SHEQ report as a spreadsheet, with its provenance on the face of it (CMD-8).
 *
 * `App\Shared\Exports\TabularExport` already does headings-and-rows and is used elsewhere; this
 * does not extend it because the provenance block has to come ABOVE the heading row, which means
 * the heading is not row 1 and `WithHeadings` cannot express that. So the preamble and the
 * heading are both just rows, and `styles()` bolds the two that need it.
 */
class SheqReportExport implements FromArray, ShouldAutoSize, WithStyles
{
    private readonly int $headingRow;

    /**
     * @param  list<string>  $headings
     * @param  list<list<mixed>>  $rows
     */
    public function __construct(
        private readonly ReportProvenance $provenance,
        private readonly array $headings,
        private readonly array $rows,
    ) {
        // The provenance block, then the heading. Computed here so `styles()` and `array()`
        // cannot disagree about which row the heading is on — which would bold a data row and
        // leave the heading plain.
        $this->headingRow = count($this->provenance->headerRows()) + 1;
    }

    public function array(): array
    {
        return array_merge(
            $this->provenance->headerRows(),
            [$this->headings],
            $this->rows,
        );
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            // The four provenance labels, and the heading row.
            'A1:A'.($this->headingRow - 1) => ['font' => ['bold' => true]],
            $this->headingRow => ['font' => ['bold' => true]],
        ];
    }
}
