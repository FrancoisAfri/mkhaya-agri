<?php

namespace App\Modules\Policies\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;

class PolicyReportRowsExport implements FromArray, WithHeadings, ShouldAutoSize
{
    public function __construct(private readonly array $rows)
    {
    }

    public function headings(): array
    {
        if (empty($this->rows)) {
            return [];
        }

        return array_keys($this->rows[0]);
    }

    public function array(): array
    {
        if (empty($this->rows)) {
            return [];
        }

        $headings = $this->headings();

        return array_map(function ($row) use ($headings) {
            return array_map(fn ($key) => $row[$key] ?? null, $headings);
        }, $this->rows);
    }
}
