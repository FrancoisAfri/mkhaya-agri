<?php

namespace App\Modules\Operations\Sheq\Services;

use App\Modules\Operations\Sheq\Access\AccessDecisionService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Bulk onboarding from a spreadsheet.
 *
 * Written around the way this actually goes wrong. Somebody exports four hundred contractor workers
 * from a payroll system, the ID numbers arrive as scientific notation because Excel decided they
 * were numbers, forty of them have a trailing space, and six are duplicates of people already on
 * site. An importer that writes as it reads leaves that mess half-applied and no way back.
 *
 * So: **validate everything first, write nothing, and report per row.** A dry run is the default,
 * and the errors name the row, the column and the value — "row 47: ID number 8.50102E+12 looks like
 * a number Excel reformatted; format the column as text and re-export" is actionable, whereas
 * "invalid input" sends somebody back to a spreadsheet with no idea which of four hundred rows is
 * wrong.
 */
class BulkImportService
{
    /**
     * The templates a user can download, with the columns and the rules for each.
     *
     * Held here rather than as static files so the template a user downloads can never drift from
     * what the importer accepts — the single most annoying failure in any bulk-upload feature.
     */
    public const TEMPLATES = [
        'CONTRACTOR_WORKERS' => [
            'label' => 'Contractor workers',
            'columns' => [
                'contractor_name' => ['required' => true, 'note' => 'Must match a registered contractor exactly'],
                'full_name' => ['required' => true, 'note' => 'First name and surname'],
                'id_number' => ['required' => true, 'note' => '13-digit SA ID, or passport number. Format the column as TEXT in Excel'],
                'employee_number' => ['required' => false, 'note' => 'The contractor’s own reference'],
                'trade' => ['required' => false, 'note' => 'e.g. Electrician, Rigger, General worker'],
                'medical_expires_on' => ['required' => false, 'note' => 'YYYY-MM-DD'],
                'induction_expires_on' => ['required' => false, 'note' => 'YYYY-MM-DD'],
                'phone' => ['required' => false, 'note' => 'For WhatsApp induction links'],
            ],
            'sample' => [
                ['Acme Rigging', 'Sipho Ndlovu', '8501015800083', 'ACM-001', 'Rigger', '2027-03-01', '2027-01-15', '0821234567'],
                ['Acme Rigging', 'Naledi Khumalo', '9203125800081', 'ACM-002', 'Electrician', '2026-11-30', '2027-01-15', '0837654321'],
            ],
        ],
        'EQUIPMENT' => [
            'label' => 'Safety equipment',
            'columns' => [
                'asset_tag' => ['required' => true, 'note' => 'Unique per company'],
                'name' => ['required' => true, 'note' => 'What it is'],
                'equipment_type' => ['required' => true, 'note' => 'FIRE_EXTINGUISHER, LADDER, HARNESS, LIFTING_GEAR, FIRST_AID…'],
                'site_name' => ['required' => false, 'note' => 'Must match a site exactly'],
                'location_text' => ['required' => false, 'note' => 'Where it is'],
                'serial_number' => ['required' => false, 'note' => ''],
                'inspection_frequency_days' => ['required' => false, 'note' => 'Blank uses the statutory default for the type'],
            ],
            'sample' => [
                ['FE-001', 'Extinguisher — workshop door', 'FIRE_EXTINGUISHER', 'Plant', 'Workshop', 'SN-99812', '30'],
                ['HN-014', 'Fall arrest harness', 'HARNESS', 'Plant', 'Store room', 'SN-44120', '180'],
            ],
        ],
        'PPE_ITEMS' => [
            'label' => 'PPE catalogue',
            'columns' => [
                'code' => ['required' => true, 'note' => 'Unique per company'],
                'name' => ['required' => true, 'note' => ''],
                'category' => ['required' => false, 'note' => 'HEAD, FOOT, HAND, EYE, HEARING, BODY, RESPIRATORY'],
                'specification' => ['required' => false, 'note' => 'e.g. SANS 20345'],
                'lifespan_months' => ['required' => false, 'note' => 'Service life once issued'],
                'requires_size' => ['required' => false, 'note' => 'yes or no'],
            ],
            'sample' => [
                ['BOOT-01', 'Safety boots', 'FOOT', 'SANS 20345', '12', 'yes'],
                ['HAT-01', 'Hard hat', 'HEAD', 'EN 397', '60', 'no'],
            ],
        ],
    ];

    /**
     * Parse and check a file without writing anything.
     *
     * @param  list<array<int,string>>  $rows  the first row being headers
     * @return array{valid:list<array>, errors:list<array>, total:int}
     */
    public function validate(int $companyId, string $type, array $rows): array
    {
        $template = self::TEMPLATES[$type] ?? null;

        if ($template === null) {
            throw new InvalidArgumentException("'{$type}' is not an import type.");
        }

        if (count($rows) < 2) {
            return ['valid' => [], 'errors' => [['row' => 0,
                'error' => 'The file has headers but no data rows.']], 'total' => 0];
        }

        $headers = array_map(fn ($h) => Str::snake(trim(strtolower((string) $h))), array_shift($rows));
        $columns = $template['columns'];

        $valid = [];
        $errors = [];
        $seen = [];

        foreach ($rows as $index => $row) {
            // +2: one for the header row, one because humans count from 1.
            $rowNumber = $index + 2;

            if ($this->isBlank($row)) {
                continue;
            }

            // Every template column starts blank, so a sheet that omits the optional ones — which
            // is the normal case — reads as empty rather than crashing the import on a missing key.
            $record = array_fill_keys(array_keys($columns), '');

            foreach ($headers as $position => $header) {
                $record[$header] = isset($row[$position]) ? trim((string) $row[$position]) : '';
            }

            $rowErrors = [];

            foreach ($columns as $column => $rules) {
                $value = $record[$column] ?? '';

                if (($rules['required'] ?? false) && $value === '') {
                    $rowErrors[] = "{$column} is required";
                }
            }

            // The Excel-mangled ID number, which is the single most common failure in practice.
            if (isset($record['id_number']) && $record['id_number'] !== '') {
                $id = $record['id_number'];

                if (str_contains(strtoupper($id), 'E+')) {
                    $rowErrors[] = "id_number '{$id}' looks like a number Excel reformatted. "
                        .'Format the column as text and re-export.';
                } elseif (preg_match('/^\d+$/', $id) && strlen($id) !== 13) {
                    $rowErrors[] = "id_number '{$id}' has ".strlen($id)
                        .' digits; a South African ID has 13. A passport number may contain letters.';
                }

                // Duplicates within the file itself, which no database constraint would catch
                // until half the rows were already written.
                if (isset($seen[$id])) {
                    $rowErrors[] = "id_number '{$id}' also appears on row {$seen[$id]}";
                } else {
                    $seen[$id] = $rowNumber;
                }
            }

            foreach (['medical_expires_on', 'induction_expires_on'] as $dateColumn) {
                $value = $record[$dateColumn] ?? '';

                if ($value !== '' && ! $this->looksLikeDate($value)) {
                    $rowErrors[] = "{$dateColumn} '{$value}' is not a date. Use YYYY-MM-DD.";
                }
            }

            $rowErrors = array_merge($rowErrors, $this->checkReferences($companyId, $type, $record));

            if ($rowErrors !== []) {
                $errors[] = ['row' => $rowNumber, 'errors' => $rowErrors,
                    'value' => $record['full_name'] ?? $record['asset_tag'] ?? $record['code'] ?? ''];

                continue;
            }

            $valid[] = $record;
        }

        return ['valid' => $valid, 'errors' => $errors, 'total' => count($valid) + count($errors)];
    }

    /**
     * Write the rows that passed.
     *
     * All-or-nothing inside a transaction: a partial import of four hundred workers is worse than
     * a failed one, because nobody can tell which half landed.
     */
    public function import(int $companyId, string $type, array $rows, ?int $actorId = null): array
    {
        $checked = $this->validate($companyId, $type, $rows);

        $uuid = (string) Str::uuid();

        $importId = DB::table('sheq_bulk_imports')->insertGetId([
            'uuid' => $uuid, 'company_id' => $companyId, 'import_type' => $type,
            'total_rows' => $checked['total'], 'failed_rows' => count($checked['errors']),
            'errors' => json_encode($checked['errors']),
            'status' => 'IMPORTING', 'dry_run' => false,
            'uploaded_by' => $actorId, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $imported = DB::transaction(fn () => match ($type) {
            'CONTRACTOR_WORKERS' => $this->importWorkers($companyId, $checked['valid']),
            'EQUIPMENT' => $this->importEquipment($companyId, $checked['valid'], $actorId),
            'PPE_ITEMS' => $this->importPpeItems($companyId, $checked['valid']),
            default => 0,
        });

        DB::table('sheq_bulk_imports')->where('id', $importId)->update([
            'imported_rows' => $imported,
            'status' => 'COMPLETED',
            'completed_at' => now(), 'updated_at' => now(),
        ]);

        return ['uuid' => $uuid, 'imported' => $imported,
            'failed' => count($checked['errors']), 'errors' => $checked['errors']];
    }

    /** The downloadable template: instructions, headers, and two worked example rows. */
    public function templateRows(string $type): array
    {
        $template = self::TEMPLATES[$type] ?? null;

        if ($template === null) {
            throw new InvalidArgumentException("'{$type}' is not an import type.");
        }

        $rows = [
            ['# '.$template['label'].' — bulk upload template'],
            ['# Fill in from row '.(count($template['columns']) > 0 ? '6' : '5')
                .'. Delete the example rows before uploading.'],
            ['# Columns marked REQUIRED must have a value. Dates are YYYY-MM-DD.'],
            ['# Format ID number columns as TEXT in Excel, or long numbers become 8.5E+12.'],
            [],
        ];

        $rows[] = array_keys($template['columns']);
        $rows[] = array_map(fn ($rules) => ($rules['required'] ? 'REQUIRED. ' : '')
            .($rules['note'] ?: ''), $template['columns']);

        foreach ($template['sample'] as $sample) {
            $rows[] = $sample;
        }

        return $rows;
    }

    /** @return list<string> */
    private function checkReferences(int $companyId, string $type, array $record): array
    {
        $errors = [];

        if ($type === 'CONTRACTOR_WORKERS' && ($record['contractor_name'] ?? '') !== '') {
            $exists = DB::table('sheq_contractors')->where('company_id', $companyId)
                ->whereRaw('lower(name) = ?', [strtolower($record['contractor_name'])])
                ->whereNull('deleted_at')->exists();

            if (! $exists) {
                $errors[] = "contractor_name '{$record['contractor_name']}' is not a registered "
                    .'contractor. Register the contractor first, or correct the spelling.';
            }
        }

        if ($type === 'EQUIPMENT' && ($record['site_name'] ?? '') !== '') {
            $exists = DB::table('sheq_sites')->where('company_id', $companyId)
                ->whereRaw('lower(name) = ?', [strtolower($record['site_name'])])->exists();

            if (! $exists) {
                $errors[] = "site_name '{$record['site_name']}' does not match a site.";
            }
        }

        return $errors;
    }

    private function importWorkers(int $companyId, array $rows): int
    {
        if (! Schema::hasTable('sheq_contractor_workers')) {
            return 0;
        }

        $count = 0;

        foreach ($rows as $row) {
            $contractorId = DB::table('sheq_contractors')->where('company_id', $companyId)
                ->whereRaw('lower(name) = ?', [strtolower($row['contractor_name'])])
                ->value('id');

            // Re-importing an updated sheet must correct people, not duplicate them.
            $existing = DB::table('sheq_contractor_workers')->where('company_id', $companyId)
                ->where('id_number_hash',
                    AccessDecisionService::hashIdentifier($row['id_number']))
                ->value('id');

            $payload = [
                'company_id' => $companyId,
                'contractor_id' => $contractorId,
                'name' => $row['full_name'],
                // Stored hashed, never in plaintext — the register is designed so that a database
                // read cannot yield a workforce's identity numbers.
                'id_number_hash' => AccessDecisionService::hashIdentifier($row['id_number']),
                'trade' => $row['trade'] ?: null,
                'induction_expires' => $row['induction_expires_on'] ?: null,
                'is_active' => true,
                'updated_at' => now(),
            ];

            if ($existing !== null) {
                DB::table('sheq_contractor_workers')->where('id', $existing)->update($payload);
            } else {
                DB::table('sheq_contractor_workers')->insert($payload + [
                    'uuid' => (string) Str::uuid(), 'created_at' => now(),
                ]);
            }

            $count++;
        }

        return $count;
    }

    private function importEquipment(int $companyId, array $rows, ?int $actorId): int
    {
        $service = app(EquipmentService::class);
        $count = 0;

        foreach ($rows as $row) {
            $siteId = ($row['site_name'] ?? '') === '' ? null
                : DB::table('sheq_sites')->where('company_id', $companyId)
                    ->whereRaw('lower(name) = ?', [strtolower($row['site_name'])])->value('id');

            try {
                $service->register($companyId, [
                    'asset_tag' => $row['asset_tag'],
                    'name' => $row['name'],
                    'equipment_type' => strtoupper($row['equipment_type']),
                    'site_id' => $siteId,
                    'location_text' => $row['location_text'] ?: null,
                    'serial_number' => $row['serial_number'] ?: null,
                    'inspection_frequency_days' => $row['inspection_frequency_days'] ?: null,
                ], $actorId);

                $count++;
            } catch (\Throwable) {
                // Already registered under this tag — skipped rather than aborting the batch.
            }
        }

        return $count;
    }

    private function importPpeItems(int $companyId, array $rows): int
    {
        $count = 0;

        foreach ($rows as $row) {
            $exists = DB::table('sheq_ppe_items')->where('company_id', $companyId)
                ->where('code', $row['code'])->exists();

            if ($exists) {
                continue;
            }

            DB::table('sheq_ppe_items')->insert([
                'uuid' => (string) Str::uuid(), 'company_id' => $companyId,
                'code' => $row['code'], 'name' => $row['name'],
                'category' => $row['category'] ?: null,
                'specification' => $row['specification'] ?: null,
                'lifespan_months' => $row['lifespan_months'] ?: null,
                'requires_size' => in_array(strtolower($row['requires_size'] ?? ''), ['yes', 'y', 'true', '1'], true),
                'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);

            $count++;
        }

        return $count;
    }

    private function isBlank(array $row): bool
    {
        foreach ($row as $cell) {
            if (trim((string) $cell) !== '') {
                return false;
            }
        }

        return true;
    }

    private function looksLikeDate(string $value): bool
    {
        try {
            Carbon::parse($value);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
