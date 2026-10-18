<?php

namespace App\Modules\Finance\Accounting\Services;

use App\Modules\Finance\Accounting\Models\FinBankAccount;
use App\Modules\Finance\Accounting\Models\FinBankStatementLine;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Imports a bank statement (CSV / XLSX / OFX / QIF) into statement lines with
 * duplicate detection. The caller can pin the file type, a bank preset, and the
 * date format so ambiguous dates (01/02) and amounts import correctly.
 */
class StatementImportService
{
    public const FILE_TYPES = [
        'auto'          => 'Auto-detect',
        'csv'           => 'CSV (generic)',
        'standard_bank' => 'Standard Bank CSV',
        'absa'          => 'ABSA CSV',
        'fnb'           => 'FNB CSV',
        'nedbank'       => 'Nedbank CSV',
        'ofx'           => 'OFX / QFX',
        'qif'           => 'QIF',
    ];

    public const DATE_FORMATS = [
        'auto'       => 'Auto',
        'DD/MM/YYYY' => 'd/m/Y',
        'MM/DD/YYYY' => 'm/d/Y',
        'YYYY-MM-DD' => 'Y-m-d',
        'DD-MM-YYYY' => 'd-m-Y',
        'YYYYMMDD'   => 'Ymd',
    ];

    /** @return array{imported: int, skipped: int, errors: array<int, string>} */
    public function import(FinBankAccount $bank, UploadedFile $file, array $opts = []): array
    {
        $type = $opts['file_type'] ?? 'auto';
        $dateFmt = self::DATE_FORMATS[$opts['date_format'] ?? 'auto'] ?? null;
        if ($type === 'auto') {
            $type = $this->detectType($file);
        }

        $rows = match ($type) {
            'ofx'   => $this->parseOfx($file),
            'qif'   => $this->parseQif($file),
            default => $this->parseCsv($file),
        };

        return $this->persist($bank, $rows, $dateFmt);
    }

    /** @param array<int, array{date:?string, description:string, reference:?string, amount:?float, balance:?float}> $rows */
    private function persist(FinBankAccount $bank, array $rows, ?string $dateFmt): array
    {
        $imported = $skipped = 0;
        $errors = [];

        foreach ($rows as $i => $row) {
            $n = $i + 1;
            if (empty($row['date'])) {
                continue;
            }
            $date = $this->parseDate($row['date'], $dateFmt);
            if (! $date) {
                $errors[] = "Row {$n}: unreadable date “{$row['date']}”.";
                continue;
            }
            $amount = $row['amount'];
            if ($amount === null) {
                $errors[] = "Row {$n}: no amount.";
                continue;
            }

            $desc = trim((string) ($row['description'] ?? ''));
            $ref = trim((string) ($row['reference'] ?? '')) ?: null;
            $hash = md5($bank->id . '|' . $date->toDateString() . '|' . number_format($amount, 2, '.', '') . '|' . $desc . '|' . $ref);

            if (FinBankStatementLine::where('bank_account_id', $bank->id)->where('hash', $hash)->exists()) {
                $skipped++;
                continue;
            }

            FinBankStatementLine::create([
                'bank_account_id' => $bank->id, 'statement_date' => $date, 'description' => Str::limit($desc, 250, ''),
                'reference' => $ref, 'amount' => $amount, 'balance' => $row['balance'] ?? null, 'hash' => $hash,
                'status' => 'unmatched', 'created_by' => Auth::id(),
            ]);
            $imported++;
        }

        return ['imported' => $imported, 'skipped' => $skipped, 'errors' => $errors];
    }

    private function detectType(UploadedFile $file): string
    {
        $ext = Str::lower($file->getClientOriginalExtension());
        if (in_array($ext, ['ofx', 'qfx'], true)) {
            return 'ofx';
        }
        if ($ext === 'qif') {
            return 'qif';
        }
        if (in_array($ext, ['csv', 'txt', 'xls', 'xlsx'], true)) {
            return 'csv';
        }
        // Sniff content for OFX/QIF markers.
        $head = Str::lower((string) file_get_contents($file->getRealPath(), false, null, 0, 512));
        if (str_contains($head, '<ofx') || str_contains($head, 'ofxheader')) {
            return 'ofx';
        }
        if (str_starts_with(ltrim($head), '!type')) {
            return 'qif';
        }

        return 'csv';
    }

    // ── CSV ────────────────────────────────────────────────────────────────
    private function parseCsv(UploadedFile $file): array
    {
        $matrix = IOFactory::load($file->getRealPath())->getActiveSheet()->toArray(null, true, false, false);
        if (! $matrix) {
            return [];
        }
        $first = array_map(fn ($c) => Str::of((string) $c)->lower()->trim()->replace(' ', '_')->toString(), $matrix[0]);
        $hasHeader = (bool) array_intersect($first, ['date', 'transaction_date', 'amount', 'description', 'narrative', 'details', 'debit', 'credit']);

        if ($hasHeader) {
            array_shift($matrix);
            return array_map(fn ($r) => $this->csvRow($this->assoc($first, $r)), $matrix);
        }

        // Headerless: best-effort positional guess (date, description, amount[, balance]).
        return array_map(function ($r) {
            $cells = array_values($r);
            return [
                'date' => $cells[0] ?? null,
                'description' => $cells[1] ?? '',
                'reference' => null,
                'amount' => $this->num($cells[2] ?? null),
                'balance' => $this->num($cells[3] ?? null),
            ];
        }, $matrix);
    }

    private function assoc(array $headers, array $row): array
    {
        $out = [];
        foreach ($headers as $i => $key) {
            if ($key !== '') {
                $out[$key] = $row[$i] ?? null;
            }
        }
        return $out;
    }

    private function csvRow(array $row): array
    {
        return [
            'date'        => $row['date'] ?? $row['transaction_date'] ?? $row['posting_date'] ?? null,
            'description' => $row['description'] ?? $row['narrative'] ?? $row['details'] ?? $row['memo'] ?? '',
            'reference'   => $row['reference'] ?? $row['ref'] ?? $row['payment_reference'] ?? null,
            'amount'      => $this->csvAmount($row),
            'balance'     => $this->num($row['balance'] ?? null),
        ];
    }

    private function csvAmount(array $row): ?float
    {
        if (isset($row['amount']) && $row['amount'] !== '') {
            return $this->num($row['amount']);
        }
        $in = $this->num($row['money_in'] ?? $row['credit'] ?? $row['deposit'] ?? $row['credit_amount'] ?? null) ?? 0;
        $out = $this->num($row['money_out'] ?? $row['debit'] ?? $row['withdrawal'] ?? $row['debit_amount'] ?? null) ?? 0;

        return ($in || $out) ? round($in - $out, 2) : null;
    }

    // ── OFX / QFX ────────────────────────────────────────────────────────────
    private function parseOfx(UploadedFile $file): array
    {
        $raw = (string) file_get_contents($file->getRealPath());
        preg_match_all('/<STMTTRN>(.*?)<\/STMTTRN>/is', $raw, $blocks);
        $rows = [];
        foreach ($blocks[1] as $b) {
            $get = fn (string $tag) => (preg_match('/<' . $tag . '>([^<\r\n]+)/i', $b, $m)) ? trim($m[1]) : null;
            $date = $get('DTPOSTED');
            $rows[] = [
                'date' => $date ? substr($date, 0, 8) : null, // YYYYMMDD(HHMMSS) → keep date
                'description' => $get('NAME') ?: $get('MEMO') ?: '',
                'reference' => $get('CHECKNUM') ?: $get('FITID'),
                'amount' => $this->num($get('TRNAMT')),
                'balance' => null,
            ];
        }

        return $rows;
    }

    // ── QIF ───────────────────────────────────────────────────────────────────
    private function parseQif(UploadedFile $file): array
    {
        $lines = preg_split('/\r\n|\r|\n/', (string) file_get_contents($file->getRealPath()));
        $rows = [];
        $cur = [];
        foreach ($lines as $line) {
            if ($line === '' || str_starts_with($line, '!')) {
                continue;
            }
            $code = $line[0];
            $val = trim(substr($line, 1));
            match ($code) {
                'D' => $cur['date'] = str_replace("'", '/', $val),
                'T', 'U' => $cur['amount'] = $this->num($val),
                'P' => $cur['description'] = $val,
                'M' => $cur['memo'] = $val,
                'N' => $cur['reference'] = $val,
                '^' => (function () use (&$rows, &$cur) {
                    if ($cur) {
                        $rows[] = [
                            'date' => $cur['date'] ?? null,
                            'description' => $cur['description'] ?? $cur['memo'] ?? '',
                            'reference' => $cur['reference'] ?? null,
                            'amount' => $cur['amount'] ?? null,
                            'balance' => null,
                        ];
                    }
                    $cur = [];
                })(),
                default => null,
            };
        }

        return $rows;
    }

    // ── helpers ───────────────────────────────────────────────────────────────
    private function parseDate($raw, ?string $fmt): ?Carbon
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return null;
        }
        try {
            if ($fmt) {
                $d = Carbon::createFromFormat($fmt, $raw);
                return $d ? $d->startOfDay() : null;
            }
            // Auto: prefer day-first (ZA) for ambiguous slash dates.
            if (preg_match('#^\d{1,2}/\d{1,2}/\d{2,4}$#', $raw)) {
                return Carbon::createFromFormat(strlen(explode('/', $raw)[2]) === 2 ? 'd/m/y' : 'd/m/Y', $raw)->startOfDay();
            }
            return Carbon::parse($raw)->startOfDay();
        } catch (\Throwable) {
            try {
                return Carbon::parse($raw)->startOfDay();
            } catch (\Throwable) {
                return null;
            }
        }
    }

    private function num($v): ?float
    {
        if ($v === null || $v === '') {
            return null;
        }
        $clean = preg_replace('/[^\d.\-()]/', '', (string) $v);
        if ($clean === '' || $clean === '-') {
            return null;
        }
        if (str_contains($clean, '(') || str_contains($clean, ')')) {
            $clean = '-' . preg_replace('/[()]/', '', $clean);
        }

        return round((float) $clean, 2);
    }
}
