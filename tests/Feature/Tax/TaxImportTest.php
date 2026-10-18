<?php

namespace Tests\Feature\Tax;

use App\Modules\Finance\Tax\Exceptions\TaxControlException;
use App\Modules\Finance\Tax\Models\TaxComplianceDeadline;
use App\Modules\Finance\Tax\Models\TaxLiability;
use App\Modules\Finance\Tax\Models\TaxPeriod;
use App\Modules\Finance\Tax\Models\TaxRegistration;
use App\Modules\Finance\Tax\Models\TaxReturn;
use App\Modules\Finance\Tax\Services\TaxImportService;
use App\Modules\Finance\Tax\Services\TaxPeriodService;
use App\Modules\Finance\Tax\Support\TaxPermissions;
use Illuminate\Http\UploadedFile;

/**
 * SRS §23 / TAX-022: historical import — template, validation with row-level errors, totals,
 * second-person sign-off, and history that never pretends to be verified.
 */
class TaxImportTest extends TaxTestCase
{
    private \App\Core\User\Models\User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->user('admin', [TaxPermissions::VIEW, TaxPermissions::ADMIN]);
    }

    public function test_tax_022_counts_totals_and_row_errors_are_reported_before_anything_is_written(): void
    {
        $csv = $this->csv([
            ['business', 'tax', 'period_end', 'description', 'source', 'amount', 'paid', 'due_date'],
            ['', 'VAT', '2025-12-31', 'VAT201 Nov–Dec 2025 per SARS statement', 'opening_balance', '125000.00', '25000.00', '2026-01-30'],
            ['', 'VAT', '2025-12-31', 'VAT201 Nov–Dec 2025 per SARS statement', 'opening_balance', '125000.00', '25000.00', '2026-01-30'], // duplicate
            ['', 'XYZ', '2025-12-31', 'Bad tax', 'opening_balance', '10.00', '', ''],
            ['', 'VAT', '31/12/2025', 'Bad date', 'penalty', 'ten', '', ''],
        ]);

        $this->actingAs($this->admin);
        $batch = app(TaxImportService::class)->preview('liabilities', $csv, 'opening.csv', 'Sage Pastel');

        $this->assertSame([4, 1, 3], [$batch->row_count, $batch->valid_count, $batch->error_count]);
        $this->assertSame('125000.00', (string) $batch->amount_total, 'Only valid rows count towards the total.');
        $errors = collect($batch->rows)->pluck('errors', 'line');
        $this->assertStringContainsString('Duplicate of row 2', implode(' ', $errors[3]));
        $this->assertStringContainsString('Unknown tax', implode(' ', $errors[4]));
        $this->assertSame(0, TaxLiability::count(), 'A preview writes nothing.');

        try {
            app(TaxImportService::class)->commit($batch, 'Agreed to the SARS statement');
            $this->fail('The uploader cannot sign off.');
        } catch (TaxControlException) {
        }

        $this->actingAs($this->reviewer);
        app(TaxImportService::class)->commit($batch->fresh(), 'Agreed to the SARS statement of account dated 2026-01-15');
        $l = TaxLiability::firstOrFail();
        $this->assertSame(['125000.00', '25000.00', 'part_paid', $batch->id], [(string) $l->original_amount, (string) $l->paid_amount, $l->status, $l->import_batch_id]);
        $this->assertStringContainsString('Sage Pastel', $l->notes);
    }

    public function test_historical_returns_without_evidence_are_unverified_and_old_deadlines_are_not_chased(): void
    {
        $this->actingAs($this->admin);
        $regs = app(TaxImportService::class)->preview('registrations', $this->csv([
            ['business', 'tax', 'reference', 'effective_from', 'effective_to', 'filing_frequency', 'year_end_month', 'status', 'preparer_email', 'reviewer_email'],
            ['', 'VAT', '4123456789', '2024-01-01', '', 'bi_monthly_even', '', 'active', $this->preparer->email, $this->reviewer->email],
        ]), 'regs.csv', 'Spreadsheet');
        $this->actingAs($this->reviewer);
        app(TaxImportService::class)->commit($regs, 'Agreed to SARS registration letters');

        $reg = TaxRegistration::firstOrFail();
        $this->assertTrue($reg->applicability_confirmed, 'The sign-off confirms the obligation.');
        app(TaxPeriodService::class)->generate($reg, now()->parse('2026-06-30'));
        $past = TaxComplianceDeadline::whereDate('due_date', '<', today())->count();
        $this->assertGreaterThan(0, $past);
        $this->assertSame($past, TaxComplianceDeadline::whereDate('due_date', '<', today())->where('status', 'waived')->count(),
            'Deadlines that passed before the registration was confirmed are history, not overdue work.');

        $this->actingAs($this->admin);
        $returns = app(TaxImportService::class)->preview('returns', $this->csv([
            ['business', 'tax', 'period_end', 'amount_payable', 'outcome', 'submission_reference', 'submitted_on', 'evidence_reference'],
            ['', 'VAT', '2024-02-29', '41000.00', 'accepted', 'EF-111', '2024-03-28', 'DMS-0042'],
            ['', 'VAT', '2024-04-30', '38000.00', 'accepted', '', '', ''],
        ]), 'returns.csv', 'eFiling history');
        $this->actingAs($this->reviewer);
        app(TaxImportService::class)->commit($returns, 'Per eFiling return history');

        $verified = TaxReturn::where('submission_reference', 'EF-111')->firstOrFail();
        $unverified = TaxReturn::where('amount_payable', '38000.00')->firstOrFail();
        $this->assertSame(['imported', 'accepted'], [$verified->purpose, $verified->submission_status]);
        $this->assertSame('unverified', $unverified->submission_status, 'No evidence: never shown as a verified submission.');
        $this->assertSame('closed', $verified->period->status);
        $this->assertNotSame('closed', $unverified->period->status, 'Unverified history does not close its period.');
    }

    public function test_the_import_screen_and_template_work_through_http(): void
    {
        $this->actingAs($this->admin)->get(route('tax.import.template', 'returns'))->assertOk();
        $this->actingAs($this->admin)->post(route('tax.import.preview'), [
            'kind' => 'carry_forwards', 'source_system' => 'Spreadsheet',
            'file' => UploadedFile::fake()->createWithContent('cf.csv', "business,kind,year_end,balance,basis\n,assessed_loss,2025-02-28,1500000.00,ITA34 dated 2025-11-10\n"),
        ])->assertRedirect();
        $this->actingAs($this->admin)->get(route('tax.import.index'))->assertOk()
            ->assertInertia(fn ($p) => $p->component('Modules/Finance/Tax/Import')->where('batches.0.valid', 1));
        $this->actingAs($this->preparer)->get(route('tax.import.index'))->assertForbidden();

        // The approver signs batches off, so sees them — but only an administrator uploads.
        $approver = $this->user('import-approver', [TaxPermissions::VIEW, TaxPermissions::APPROVE]);
        $this->actingAs($approver)->get(route('tax.import.index'))->assertOk()
            ->assertInertia(fn ($p) => $p->where('can.commit', true)->where('can.upload', false));
        $this->actingAs($approver)->get(route('tax.import.template', 'returns'))->assertForbidden();
    }

    private function csv(array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'taximp');
        $h = fopen($path, 'w');
        foreach ($rows as $r) {
            fputcsv($h, $r);
        }
        fclose($h);

        return $path;
    }
}
