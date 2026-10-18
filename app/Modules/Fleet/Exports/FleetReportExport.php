<?php

namespace App\Modules\Fleet\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class FleetReportExport implements FromArray, WithHeadings, WithStyles, ShouldAutoSize
{
    public function __construct(
        private readonly array $headers,
        private readonly array $rows,
    ) {}

    public function array(): array   { return $this->rows; }
    public function headings(): array { return $this->headers; }

    public function styles(Worksheet $sheet): array
    {
        return [1 => ['font' => ['bold' => true]]];
    }
}
