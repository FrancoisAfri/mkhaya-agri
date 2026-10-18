<?php

namespace App\Modules\Finance\Accounting\Services;

use App\Modules\Finance\Accounting\Models\FinAccount;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Bulk import of the chart of accounts from CSV or XLSX (1.1). Bad rows are
 * skipped and reported rather than blocking the whole file. Parents are resolved
 * in a second pass, so parent rows may appear in any order.
 */
class CoaImportService
{
    private const TYPES = ['asset', 'liability', 'equity', 'income', 'expense'];

    public const TEMPLATE_HEADERS = ['code', 'name', 'type', 'parent_code', 'is_postable', 'subtype', 'tax_code'];

    /**
     * @return array{imported: int, updated: int, skipped: array<int, array{row: int, code: string, reason: string}>}
     */
    public function import(UploadedFile $file, ?int $companyId = null): array
    {
        $rows = $this->readRows($file);
        $imported = $updated = 0;
        $skipped = [];
        $parentLinks = []; // code => parent_code, applied in pass 2

        foreach ($rows as $i => $row) {
            $line = $i + 2; // 1-based + header row
            $code = trim((string) ($row['code'] ?? ''));
            $name = trim((string) ($row['name'] ?? ''));
            $type = strtolower(trim((string) ($row['type'] ?? '')));

            if ($code === '' && $name === '') {
                continue; // blank line
            }
            if ($code === '') {
                $skipped[] = ['row' => $line, 'code' => '—', 'reason' => 'Missing account code.'];
                continue;
            }
            if ($name === '') {
                $skipped[] = ['row' => $line, 'code' => $code, 'reason' => 'Missing account name.'];
                continue;
            }
            if (! in_array($type, self::TYPES, true)) {
                $skipped[] = ['row' => $line, 'code' => $code, 'reason' => "Invalid type '{$type}' (asset|liability|equity|income|expense)."];
                continue;
            }

            $account = FinAccount::updateOrCreate(
                ['company_id' => $companyId, 'code' => $code],
                [
                    'name'           => $name,
                    'type'           => $type,
                    'normal_balance' => FinAccount::NORMAL_BALANCE[$type],
                    'subtype'        => $this->nullable($row['subtype'] ?? null),
                    'tax_code'       => $this->nullable($row['tax_code'] ?? null),
                    'is_postable'    => $this->bool($row['is_postable'] ?? true),
                    'is_active'      => true,
                ],
            );

            $account->wasRecentlyCreated ? $imported++ : $updated++;

            $parentCode = trim((string) ($row['parent_code'] ?? ''));
            if ($parentCode !== '' && $parentCode !== $code) {
                $parentLinks[$code] = $parentCode;
            }
        }

        $this->linkParents($parentLinks, $companyId, $skipped);

        return ['imported' => $imported, 'updated' => $updated, 'skipped' => $skipped];
    }

    /** A blank CSV with the expected header row. */
    public function templateCsv(): string
    {
        return implode(',', self::TEMPLATE_HEADERS) . "\n"
            . "1000,Current Assets,asset,,0,,\n"
            . "1100,Bank,asset,1000,1,,\n"
            . "4000,Sales,income,,1,,\n";
    }

    private function linkParents(array $links, ?int $companyId, array &$skipped): void
    {
        if (! $links) {
            return;
        }

        $byCode = FinAccount::query()
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->whereIn('code', array_merge(array_keys($links), array_values($links)))
            ->get()->keyBy('code');

        foreach ($links as $code => $parentCode) {
            $child = $byCode[$code] ?? null;
            $parent = $byCode[$parentCode] ?? null;
            if (! $child) {
                continue;
            }
            if (! $parent) {
                $skipped[] = ['row' => 0, 'code' => $code, 'reason' => "Parent code '{$parentCode}' not found — imported without a parent."];
                continue;
            }
            if ($child->parent_id !== $parent->id) {
                $child->update(['parent_id' => $parent->id]);
            }
        }
    }

    /** @return array<int, array<string, string>> rows keyed by normalised header */
    private function readRows(UploadedFile $file): array
    {
        $sheet = IOFactory::load($file->getRealPath())->getActiveSheet();
        $matrix = $sheet->toArray(null, true, false, false);
        if (! $matrix) {
            return [];
        }

        $headers = array_map(
            fn ($h) => Str::of((string) $h)->lower()->trim()->replace(' ', '_')->toString(),
            array_shift($matrix)
        );

        return array_map(function ($row) use ($headers) {
            $assoc = [];
            foreach ($headers as $idx => $key) {
                if ($key !== '') {
                    $assoc[$key] = $row[$idx] ?? null;
                }
            }
            return $assoc;
        }, $matrix);
    }

    private function nullable($v): ?string
    {
        $v = trim((string) $v);
        return $v === '' ? null : $v;
    }

    private function bool($v): bool
    {
        if (is_bool($v)) {
            return $v;
        }
        return in_array(strtolower(trim((string) $v)), ['1', 'true', 'yes', 'y', 'postable'], true);
    }
}
