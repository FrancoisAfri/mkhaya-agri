<?php

namespace App\Modules\Disciplinary\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class DisciplinaryReportExport implements FromArray, WithHeadings, WithStyles, ShouldAutoSize, WithTitle
{
    public function __construct(
        private readonly string $title,
        private readonly array  $headers,
        private readonly array  $rows,
    ) {}

    public function array(): array    { return $this->rows; }
    public function headings(): array { return $this->headers; }
    public function title(): string   { return substr($this->title, 0, 31); }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']], 'fill' => ['fillType' => 'solid', 'color' => ['rgb' => '4A148C']]],
        ];
    }
}
