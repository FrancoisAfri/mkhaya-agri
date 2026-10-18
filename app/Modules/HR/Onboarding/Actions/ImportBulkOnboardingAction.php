<?php

namespace App\Modules\HR\Onboarding\Actions;

use App\Modules\HR\Onboarding\Models\OnboardingBulkImport;
use App\Modules\HR\Onboarding\Models\OnboardingPlan;
use App\Modules\HR\Onboarding\Models\OnboardingTemplate;
use App\Modules\HR\PreBoarding\Enums\HrPersonStatus;
use App\Modules\HR\PreBoarding\Models\HrPerson;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Turn an uploaded spreadsheet into onboarding plans.
 *
 * Every row is its own transaction rather than the file being one. A batch of two hundred people
 * where row 147 has a malformed date should onboard a hundred and ninety-nine and tell you about
 * 147 — rolling the whole thing back would mean re-uploading the file, and re-uploading a file that
 * partly succeeded is how you get duplicates. The trade is that a failed run leaves real rows
 * behind, which is why the import record keeps the per-row errors: you fix those rows and upload
 * only them.
 */
class ImportBulkOnboardingAction
{
    /** The columns the downloadable template carries, in order. */
    public const COLUMNS = [
        'first_name', 'surname', 'email', 'phone', 'id_number', 'employee_number',
        'job_title', 'department', 'employment_type', 'start_date', 'template',
    ];

    public const REQUIRED = ['first_name', 'surname', 'start_date'];

    /**
     * @param  list<array<string, string|null>>  $rows
     */
    public function execute(array $rows, int $createdBy, ?int $defaultTemplateId = null): OnboardingBulkImport
    {
        $companyId = app(CurrentCompany::class)->id();

        $import = OnboardingBulkImport::create([
            'filename' => 'upload.csv',
            'total_rows' => count($rows),
            'default_template_id' => $defaultTemplateId,
            'created_by' => $createdBy,
            'company_id' => $companyId,
            'status' => 'processing',
        ]);

        $errors = [];
        $created = 0;

        foreach ($rows as $index => $row) {
            // Spreadsheet line number, not array index: the person fixing the file is looking at a
            // spreadsheet where row 1 is the header.
            $line = $index + 2;

            try {
                $this->importRow($row, $createdBy, $companyId, $defaultTemplateId, $import->id);
                $created++;
            } catch (\Throwable $e) {
                $errors[] = ['row' => $line, 'name' => trim(($row['first_name'] ?? '') . ' ' . ($row['surname'] ?? '')), 'error' => $e->getMessage()];
            }
        }

        $import->update([
            'created_count' => $created,
            'failed_count' => count($errors),
            'row_errors' => $errors,
            'status' => 'completed',
        ]);

        return $import->fresh();
    }

    /** @param array<string, string|null> $row */
    private function importRow(array $row, int $createdBy, ?int $companyId, ?int $defaultTemplateId, int $importId): void
    {
        $data = $this->validateRow($row);

        DB::transaction(function () use ($data, $createdBy, $companyId, $defaultTemplateId, $importId) {
            $template = $this->resolveTemplate($data['template'] ?? null, $defaultTemplateId);
            $startDate = Carbon::parse($data['start_date']);

            $person = HrPerson::create([
                // No candidate and no contract: this person did not come through recruitment, which
                // is the entire point of a bulk import.
                'candidate_id' => null,
                'contract_id' => null,
                'first_name' => $data['first_name'],
                'surname' => $data['surname'],
                'email' => $data['email'] ?? null,
                'phone' => $data['phone'] ?? null,
                'id_number' => $data['id_number'] ?? null,
                'employee_number' => $data['employee_number'] ?? null,
                'job_title' => $data['job_title'] ?? null,
                'department' => $data['department'] ?? null,
                'started_on' => $startDate->toDateString(),
                'status' => HrPersonStatus::Onboarding->value,
                'created_by' => $createdBy,
                'company_id' => $companyId,
                'source' => 'bulk_import',
                'bulk_import_id' => $importId,
            ]);

            $plan = OnboardingPlan::create([
                'hr_person_id' => $person->id,
                // A copy of the catalogue template is not made per person: the plan's tasks are
                // materialised below, so the template is a reference for provenance only.
                'template_id' => $template?->isGlobal() ? null : $template?->id,
                'start_date' => $startDate->toDateString(),
                'end_date' => $startDate->copy()->addDays(90)->toDateString(),
                'status' => 'active',
                'created_by' => $createdBy,
                'company_id' => $companyId,
            ]);

            $tasks = $template
                ? $template->loadMissing('templateTasks')->toTaskArray($startDate)
                : [];

            foreach ($tasks as $order => $task) {
                $plan->tasks()->create([
                    'type' => $task['type'],
                    'title' => $task['title'],
                    'description' => $task['description'],
                    'due_date' => $task['due_date'],
                    'sort_order' => $order,
                    'company_id' => $companyId,
                ]);
            }
        });
    }

    /**
     * @param  array<string, string|null>  $row
     * @return array<string, string|null>
     */
    private function validateRow(array $row): array
    {
        $row = array_map(fn ($v) => is_string($v) ? (trim($v) ?: null) : $v, $row);

        $validator = Validator::make($row, [
            'first_name' => ['required', 'string', 'max:100'],
            'surname' => ['required', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:150'],
            'phone' => ['nullable', 'string', 'max:40'],
            'id_number' => ['nullable', 'string', 'max:40'],
            'employee_number' => ['nullable', 'string', 'max:40'],
            'job_title' => ['nullable', 'string', 'max:100'],
            'department' => ['nullable', 'string', 'max:100'],
            'employment_type' => ['nullable', 'string', 'in:permanent,contract,part_time'],
            'start_date' => ['required', 'date'],
            'template' => ['nullable', 'string', 'max:150'],
        ]);

        if ($validator->fails()) {
            throw new \RuntimeException(implode(' ', $validator->errors()->all()));
        }

        return $validator->validated();
    }

    /**
     * Find the template a row asked for, by catalogue key or by name.
     *
     * Both the tenant's own templates and the shared catalogue are in scope — RLS already decides
     * which rows are visible, so this does not filter on company_id and cannot accidentally exclude
     * the catalogue by doing so.
     */
    private function resolveTemplate(?string $reference, ?int $defaultTemplateId): ?OnboardingTemplate
    {
        if ($reference !== null && $reference !== '') {
            $template = OnboardingTemplate::query()
                ->where('catalogue_key', $reference)
                ->orWhere('name', $reference)
                ->first();

            if (! $template) {
                throw new \RuntimeException("No onboarding template matches “{$reference}”.");
            }

            return $template;
        }

        return $defaultTemplateId ? OnboardingTemplate::find($defaultTemplateId) : null;
    }

    /** The CSV a user downloads: a header row and one worked example. */
    public static function templateCsv(): string
    {
        $example = [
            'Nomsa', 'Dlamini', 'nomsa.dlamini@example.com', '0821234567', '9001015800086',
            'EMP1042', 'Site Foreman', 'Operations', 'permanent',
            now()->addDays(14)->toDateString(), 'construction',
        ];

        $out = fopen('php://temp', 'r+');
        fputcsv($out, self::COLUMNS);
        fputcsv($out, $example);
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        return $csv;
    }
}
