<?php

namespace App\Modules\Finance\Tax\Services;

use App\Modules\Finance\Tax\Exceptions\TaxControlException;
use App\Modules\Finance\Tax\Models\TaxCarryForward;
use App\Modules\Finance\Tax\Models\TaxImportBatch;
use App\Modules\Finance\Tax\Models\TaxLiability;
use App\Modules\Finance\Tax\Models\TaxPeriod;
use App\Modules\Finance\Tax\Models\TaxRegistration;
use App\Modules\Finance\Tax\Models\TaxReturn;
use App\Modules\Finance\Tax\Models\TaxReturnForm;
use App\Modules\Finance\Tax\Models\TaxReturnLine;
use App\Modules\Finance\Tax\Models\TaxType;
use App\Modules\Finance\Tax\Support\TaxBusinesses;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Historical tax data import (SRS §23).
 *
 *   preview  parse the CSV, validate every row (mandatory fields, types, the business, duplicates in
 *            the file and against what already exists), and keep the result as a batch with its
 *            row-level errors, counts and money total. Nothing else is written.
 *   commit   a second person signs off; only valid rows are written, each carrying the batch id.
 *            Corrected rows are re-imported as a new batch — failed rows are never half-written.
 *
 * Imported data says what it is: registrations note their source, liabilities are marked as opening
 * balances, and a historical return without a SARS reference *and* an evidence reference is
 * `unverified` — never presented as a verified submission.
 */
class TaxImportService
{
    public const KINDS = [
        'registrations' => ['business', 'tax', 'reference', 'effective_from', 'effective_to', 'filing_frequency', 'year_end_month', 'status', 'preparer_email', 'reviewer_email'],
        'liabilities' => ['business', 'tax', 'period_end', 'description', 'source', 'amount', 'paid', 'due_date'],
        'returns' => ['business', 'tax', 'period_end', 'amount_payable', 'outcome', 'submission_reference', 'submitted_on', 'evidence_reference'],
        'carry_forwards' => ['business', 'kind', 'year_end', 'balance', 'basis'],
    ];

    private const REQUIRED = [
        'registrations' => ['tax', 'effective_from', 'filing_frequency'],
        'liabilities' => ['tax', 'description', 'source', 'amount'],
        'returns' => ['tax', 'period_end', 'amount_payable', 'outcome'],
        'carry_forwards' => ['kind', 'year_end', 'balance', 'basis'],
    ];

    public function __construct(
        private readonly TaxBusinesses $businesses,
        private readonly TaxRegistrationService $registrations,
        private readonly TaxPeriodService $periods,
        private readonly TaxAudit $audit,
    ) {
    }

    /** The documented template: headers plus one example row (§23 "documented import template"). */
    public function template(string $kind): array
    {
        $examples = [
            'registrations' => ['', 'VAT', '4123456789', '2019-03-01', '', 'bi_monthly_even', '', 'active', 'preparer@company.co.za', 'reviewer@company.co.za'],
            'liabilities' => ['', 'VAT', '2026-02-28', 'VAT201 Jan–Feb 2026 per SARS statement', 'opening_balance', '125000.00', '0.00', '2026-03-31'],
            'returns' => ['', 'VAT', '2026-02-28', '125000.00', 'accepted', 'EF-12345678', '2026-03-27', 'DMS-2026-0042'],
            'carry_forwards' => ['', 'assessed_loss', '2025-02-28', '1500000.00', 'ITA34 dated 2025-11-10'],
        ];

        return [self::KINDS[$kind], $examples[$kind]];
    }

    public function preview(string $kind, string $path, string $fileName, string $sourceSystem): TaxImportBatch
    {
        if (! isset(self::KINDS[$kind])) {
            throw new TaxControlException('Unknown import type.');
        }
        [$header, $rows] = $this->parse($path);
        $missing = array_diff(self::REQUIRED[$kind], $header);
        if ($missing) {
            throw new TaxControlException('The file is missing column(s): ' . implode(', ', $missing) . '. Download the template for the expected layout.');
        }

        $seen = [];
        $parsed = [];
        $total = '0';
        foreach ($rows as $i => $values) {
            $row = array_combine($header, array_pad(array_slice($values, 0, count($header)), count($header), ''));
            $row = array_map(fn ($v) => trim((string) $v), $row);
            $errors = $this->validateRow($kind, $row);
            $key = $this->dedupeKey($kind, $row);
            if ($key && isset($seen[$key])) {
                $errors[] = "Duplicate of row {$seen[$key]} in this file.";
            }
            $seen[$key] ??= $i + 2;
            $amount = $row['amount'] ?? $row['amount_payable'] ?? $row['balance'] ?? null;
            if (! $errors && is_numeric($amount)) {
                $total = bcadd($total, (string) $amount, 2);
            }
            $parsed[] = ['line' => $i + 2, 'data' => $row, 'errors' => $errors];
        }

        $valid = count(array_filter($parsed, fn ($r) => ! $r['errors']));
        $batch = TaxImportBatch::create([
            'kind' => $kind, 'source_system' => $sourceSystem, 'file_name' => $fileName, 'file_hash' => hash_file('sha256', $path),
            'status' => 'previewed', 'row_count' => count($parsed), 'valid_count' => $valid, 'error_count' => count($parsed) - $valid,
            'amount_total' => $total, 'rows' => $parsed, 'uploaded_by' => Auth::id(),
        ]);
        $this->audit->record('import.previewed', $batch, ['context' => ['kind' => $kind, 'file' => $fileName, 'source' => $sourceSystem,
            'rows' => count($parsed), 'valid' => $valid, 'total' => $total]]);

        return $batch;
    }

    /** Write the valid rows. Signed off by someone other than the uploader (§23 "authorised sign-off"). */
    public function commit(TaxImportBatch $batch, string $comment): TaxImportBatch
    {
        if ($batch->status !== 'previewed') {
            throw new TaxControlException('This import has already been ' . $batch->status . '.');
        }
        if ($batch->uploaded_by && $batch->uploaded_by === Auth::id()) {
            $this->audit->denied('import.commit', $batch, 'The uploader cannot sign off their own import.');
            throw new TaxControlException('You uploaded this file, so someone else has to sign off the import.');
        }
        if ($batch->valid_count === 0) {
            throw new TaxControlException('No row is valid; there is nothing to import.');
        }

        return DB::transaction(function () use ($batch, $comment) {
            $created = 0;
            foreach ($batch->rows as $row) {
                if ($row['errors']) {
                    continue;
                }
                // Re-validated at commit: something may have changed since the preview.
                if ($errors = $this->validateRow($batch->kind, $row['data'])) {
                    throw new TaxControlException("Row {$row['line']} is no longer valid: " . implode(' ', $errors) . ' Preview the file again.');
                }
                $this->write($batch, $row['data']);
                $created++;
            }

            $batch->forceFill(['status' => 'committed', 'signed_off_by' => Auth::id(), 'signed_off_at' => now(), 'sign_off_comment' => $comment,
                'summary' => ['created' => $created, 'skipped_with_errors' => $batch->error_count]])->save();
            $this->audit->record('import.committed', $batch, ['reason' => $comment,
                'after' => ['kind' => $batch->kind, 'created' => $created, 'total' => (string) $batch->amount_total, 'source' => $batch->source_system]]);

            return $batch;
        });
    }

    public function reject(TaxImportBatch $batch, string $reason): TaxImportBatch
    {
        if ($batch->status !== 'previewed') {
            throw new TaxControlException('Only a previewed import can be rejected.');
        }
        $batch->forceFill(['status' => 'rejected', 'sign_off_comment' => $reason, 'signed_off_by' => Auth::id(), 'signed_off_at' => now()])->save();
        $this->audit->record('import.rejected', $batch, ['reason' => $reason]);

        return $batch;
    }

    // ── Validation ───────────────────────────────────────────────────────────

    /** @return list<string> */
    private function validateRow(string $kind, array $r): array
    {
        $e = [];
        foreach (self::REQUIRED[$kind] as $col) {
            if (($r[$col] ?? '') === '') {
                $e[] = "{$col} is required.";
            }
        }
        if ($e) {
            return $e;
        }

        $business = $this->business($r['business'] ?? '');
        if ($business === null) {
            $e[] = ($r['business'] ?? '') === '' ? 'Name the business (this company keeps tax for more than one).' : "Unknown business \"{$r['business']}\".";
        }
        $type = isset($r['tax']) ? TaxType::where('code', strtoupper($r['tax']))->first() : null;
        if (isset($r['tax']) && ! $type) {
            $e[] = "Unknown tax \"{$r['tax']}\".";
        }
        foreach (['effective_from', 'effective_to', 'period_end', 'due_date', 'submitted_on', 'year_end'] as $col) {
            if (($r[$col] ?? '') !== '' && ! $this->isDate($r[$col])) {
                $e[] = "{$col} must be a date (YYYY-MM-DD).";
            }
        }
        foreach (['amount', 'paid', 'amount_payable', 'balance'] as $col) {
            if (($r[$col] ?? '') !== '' && ! is_numeric($r[$col])) {
                $e[] = "{$col} must be a number.";
            }
        }
        if ($e) {
            return $e;
        }

        return array_merge($e, match ($kind) {
            'registrations' => $this->checkRegistration($r, $business, $type),
            'liabilities' => in_array($r['source'], ['opening_balance', 'assessment', 'interest', 'penalty', 'return'], true) ? [] : ['source must be opening_balance, assessment, interest, penalty or return.'],
            'returns' => $this->checkReturn($r, $business, $type),
            'carry_forwards' => $this->checkCarryForward($r, $business),
        });
    }

    private function checkRegistration(array $r, int $business, TaxType $type): array
    {
        $e = [];
        if (! in_array($r['filing_frequency'], $type->frequencies ?? [], true)) {
            $e[] = "{$type->code} cannot be filed {$r['filing_frequency']}.";
        }
        if (($r['status'] ?? '') !== '' && ! in_array($r['status'], ['active', 'pending'], true)) {
            $e[] = 'status must be active or pending.';
        }
        foreach (['preparer_email', 'reviewer_email'] as $col) {
            if (($r[$col] ?? '') !== '' && ! $this->userId($r[$col])) {
                $e[] = "No user {$r[$col]} in this company.";
            }
        }
        $exists = TaxRegistration::query()->where('entity_id', $business)->where('tax_type_id', $type->id)->whereNotIn('status', ['deregistered'])->exists();
        if ($exists) {
            $e[] = "This business already has a {$type->code} registration.";
        }

        return $e;
    }

    private function checkReturn(array $r, int $business, TaxType $type): array
    {
        $e = [];
        if (! in_array($r['outcome'], ['submitted', 'accepted', 'rejected'], true)) {
            $e[] = 'outcome must be submitted, accepted or rejected.';
        }
        if (! TaxReturnForm::forDate(['VAT' => 'VAT201', 'PAYE' => 'EMP201'][$type->code] ?? '-', $r['period_end'])) {
            $e[] = "Historical returns are imported for VAT and PAYE only.";
        }
        $registration = $this->registrationFor($business, $type);
        if (! $registration) {
            $e[] = "Import or record the {$type->code} registration first.";
        } elseif (Carbon::parse($r['period_end'])->lt($registration->effective_from)) {
            $e[] = 'The period ends before the registration\'s effective date.';
        } elseif ($period = $this->periodFor($registration, $r['period_end'])) {
            if (TaxReturn::query()->where('period_id', $period->id)->whereNotIn('status', ['superseded', 'rejected'])->exists()) {
                $e[] = "{$period->label} already has a return.";
            }
        }

        return $e;
    }

    private function checkCarryForward(array $r, int $business): array
    {
        $e = [];
        if (! in_array($r['kind'], ['assessed_loss', 'allowance_balance'], true)) {
            $e[] = 'kind must be assessed_loss or allowance_balance.';
        }
        if (mb_strlen($r['basis']) < 10) {
            $e[] = 'basis must say where the balance comes from.';
        }
        if (TaxCarryForward::query()->where('entity_id', $business)->where('kind', $r['kind'])->whereDate('year_end', $r['year_end'])->exists()) {
            $e[] = 'A balance for that year already exists.';
        }

        return $e;
    }

    // ── Writing ──────────────────────────────────────────────────────────────

    private function write(TaxImportBatch $batch, array $r): void
    {
        $business = $this->business($r['business'] ?? '');
        $type = isset($r['tax']) ? TaxType::byCode($r['tax']) : null;
        $note = "Imported from {$batch->source_system} ({$batch->file_name}), batch {$batch->uuid}.";

        match ($batch->kind) {
            'registrations' => $this->writeRegistration($batch, $r, $business, $type, $note),
            'liabilities' => $this->writeLiability($batch, $r, $business, $type, $note),
            'returns' => $this->writeReturn($batch, $r, $business, $type, $note),
            'carry_forwards' => TaxCarryForward::create(['entity_id' => $business, 'kind' => $r['kind'], 'year_end' => $r['year_end'],
                'opening' => '0.00', 'movement' => bcadd($r['balance'], '0', 2), 'closing' => bcadd($r['balance'], '0', 2), 'source' => 'opening_balance',
                'note' => $r['basis'] . ' — ' . $note, 'import_batch_id' => $batch->id, 'created_by' => Auth::id()]),
        };
    }

    private function writeRegistration(TaxImportBatch $batch, array $r, int $business, TaxType $type, string $note): void
    {
        $reg = $this->registrations->create([
            'entity_id' => $business, 'tax_type_id' => $type->id, 'reference' => $r['reference'] ?: null,
            'effective_from' => $r['effective_from'], 'effective_to' => ($r['effective_to'] ?? '') ?: null,
            'filing_frequency' => $r['filing_frequency'], 'year_end_month' => ($r['year_end_month'] ?? '') ?: null,
            'preparer_id' => $this->userId($r['preparer_email'] ?? ''), 'reviewer_id' => $this->userId($r['reviewer_email'] ?? ''),
            'notes' => $note, 'reason' => $note,
        ]);
        $reg->forceFill(['import_batch_id' => $batch->id])->save();

        // The batch's sign-off is the confirmation that the obligation applies (TAX-REG-003). If
        // the row is incomplete the registration stays pending and says what it needs.
        if (($r['status'] ?? 'active') === 'active' && $this->registrations->missingDetails($reg, forConfirmation: true) === []) {
            $this->registrations->confirmApplicability($reg, "Signed off with import batch {$batch->uuid}.");
        }
    }

    private function writeLiability(TaxImportBatch $batch, array $r, int $business, TaxType $type, string $note): void
    {
        $registration = $this->registrationFor($business, $type);
        $period = $registration && ($r['period_end'] ?? '') !== '' ? $this->periodFor($registration, $r['period_end']) : null;
        $amount = bcadd($r['amount'], '0', 2);
        $paid = bcadd(($r['paid'] ?? '') ?: '0', '0', 2);
        $status = match (true) {
            bccomp($amount, '0', 2) < 0 => 'refund_due',
            bccomp($paid, '0', 2) === 0 => 'open',
            bccomp($paid, $amount, 2) < 0 => 'part_paid',
            bccomp($paid, $amount, 2) === 0 => 'paid',
            default => 'overpaid',
        };

        TaxLiability::create(['entity_id' => $business, 'tax_type_id' => $type->id, 'registration_id' => $registration?->id, 'period_id' => $period?->id,
            'source' => $r['source'] === 'return' ? 'opening_balance' : $r['source'], 'description' => $r['description'], 'original_amount' => $amount,
            'paid_amount' => $paid, 'due_date' => ($r['due_date'] ?? '') ?: null, 'status' => $status,
            'notes' => $note . (bccomp($paid, '0', 2) !== 0 ? ' Paid before migration, per the source system.' : ''),
            'import_batch_id' => $batch->id, 'created_by' => Auth::id()]);
    }

    private function writeReturn(TaxImportBatch $batch, array $r, int $business, TaxType $type, string $note): void
    {
        $registration = $this->registrationFor($business, $type);
        $period = $this->periodFor($registration, $r['period_end'], create: true);
        $form = TaxReturnForm::forDate(['VAT' => 'VAT201', 'PAYE' => 'EMP201'][$type->code], $r['period_end']);
        $verified = ($r['submission_reference'] ?? '') !== '' && ($r['evidence_reference'] ?? '') !== '';
        $version = (int) TaxReturn::query()->where('period_id', $period->id)->max('version') + 1;

        $return = TaxReturn::create([
            'entity_id' => $business, 'registration_id' => $registration->id, 'period_id' => $period->id, 'tax_type_id' => $type->id,
            'form_id' => $form->id, 'version' => $version, 'reference' => sprintf('IMP-%s-%s-v%d', $form->code, $period->end_date->format('Y-m'), $version),
            'purpose' => 'imported', 'status' => $r['outcome'], 'amount_payable' => bcadd($r['amount_payable'], '0', 2),
            'due_date' => $period->filing_due_date, 'submission_status' => $verified ? $r['outcome'] : 'unverified',
            'submission_method' => 'manual_efiling', 'submission_reference' => ($r['submission_reference'] ?? '') ?: null,
            'submitted_at' => ($r['submitted_on'] ?? '') !== '' ? Carbon::parse($r['submitted_on']) : null,
            'submission_response' => $note . ($verified ? " Evidence: {$r['evidence_reference']}." : ' No SARS evidence supplied: not verified.'),
            'payment_status' => 'none', 'import_batch_id' => $batch->id, 'prepared_at' => now(),
            'snapshot' => ['imported' => true, 'batch' => $batch->uuid],
        ]);
        TaxReturnLine::create(['company_id' => $return->company_id, 'return_id' => $return->id, 'field' => 'TOTAL', 'label' => 'Amount payable / (refundable) as imported',
            'kind' => 'total', 'calculated' => $return->amount_payable, 'final' => $return->amount_payable, 'explanation' => $note]);

        // Only verified history closes the period; unverified history leaves it as it was.
        if ($verified && $r['outcome'] === 'accepted' && in_array($period->status, ['open', 'in_preparation'], true)) {
            $period->forceFill(['status' => 'closed'])->save();
            \App\Modules\Finance\Tax\Models\TaxPeriodEvent::create(['company_id' => $period->company_id, 'period_id' => $period->id,
                'from_status' => 'open', 'to_status' => 'closed', 'reason' => "Historical return imported with SARS evidence ({$r['evidence_reference']}).",
                'user_id' => Auth::id(), 'created_at' => now()]);
        }
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function business(string $name): ?int
    {
        $options = $this->businesses->options();
        if ($name === '') {
            return count($options) === 1 ? $options[0]['id'] : null;
        }
        foreach ($options as $o) {
            if (mb_strtolower($o['name']) === mb_strtolower($name)) {
                return $o['id'];
            }
        }

        return null;
    }

    private function registrationFor(int $business, TaxType $type): ?TaxRegistration
    {
        return TaxRegistration::query()->where('entity_id', $business)->where('tax_type_id', $type->id)->whereNotIn('status', ['deregistered'])->orderByDesc('id')->first();
    }

    private function periodFor(?TaxRegistration $registration, string $periodEnd, bool $create = false): ?TaxPeriod
    {
        if (! $registration) {
            return null;
        }
        $find = fn () => TaxPeriod::query()->where('registration_id', $registration->id)->whereDate('start_date', '<=', $periodEnd)->whereDate('end_date', '>=', $periodEnd)->first();
        if (! ($period = $find()) && $create) {
            $this->periods->generate($registration, Carbon::parse($periodEnd)->endOfMonth());
            $period = $find();
        }
        if (! $period && $create) {
            throw new TaxControlException("No {$registration->taxType->code} period contains {$periodEnd}; confirm the registration first.");
        }

        return $period;
    }

    private function userId(string $email): ?int
    {
        return $email === '' ? null : DB::table('users')->where('company_id', Auth::user()?->company_id)->whereRaw('LOWER(email) = ?', [mb_strtolower($email)])->value('id');
    }

    private function dedupeKey(string $kind, array $r): ?string
    {
        $parts = match ($kind) {
            'registrations' => [$r['business'] ?? '', $r['tax'] ?? ''],
            'liabilities' => [$r['business'] ?? '', $r['tax'] ?? '', $r['period_end'] ?? '', $r['description'] ?? '', $r['amount'] ?? ''],
            'returns' => [$r['business'] ?? '', $r['tax'] ?? '', $r['period_end'] ?? ''],
            'carry_forwards' => [$r['business'] ?? '', $r['kind'] ?? '', $r['year_end'] ?? ''],
        };

        return mb_strtolower(implode('|', $parts));
    }

    private function isDate(string $v): bool
    {
        return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v) !== false;
    }

    /** @return array{0: list<string>, 1: list<list<string>>} */
    private function parse(string $path): array
    {
        $handle = fopen($path, 'r');
        if (! $handle) {
            throw new TaxControlException('The file could not be read.');
        }
        $header = fgetcsv($handle) ?: [];
        $header = array_map(fn ($h) => strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $h))), $header);
        $rows = [];
        while (($line = fgetcsv($handle)) !== false) {
            if (count(array_filter($line, fn ($v) => trim((string) $v) !== '')) === 0) {
                continue;
            }
            $rows[] = $line;
            if (count($rows) > 5000) {
                fclose($handle);
                throw new TaxControlException('Import at most 5 000 rows per file.');
            }
        }
        fclose($handle);

        return [$header, $rows];
    }
}
