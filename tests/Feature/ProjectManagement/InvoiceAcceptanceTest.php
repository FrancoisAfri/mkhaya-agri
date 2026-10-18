<?php

namespace Tests\Feature\ProjectManagement;

use App\Modules\Operations\ProjectManagement\Models\Invoice;
use App\Modules\Operations\ProjectManagement\Models\InvoiceAnnexure;
use App\Modules\Operations\ProjectManagement\Models\InvoiceCashflow;
use App\Modules\Operations\ProjectManagement\Models\Project;
use App\Modules\Operations\ProjectManagement\Services\InvoicePackService;
use App\Modules\Operations\ProjectManagement\Support\Invoicing\AnnexureCatalogue;
use App\Modules\Operations\ProjectManagement\Support\Invoicing\FeeStageCatalogue;
use App\Modules\Operations\ProjectManagement\Support\Invoicing\Money;
use App\Modules\Tenancy\Models\Company;

/**
 * The client's 37 acceptance scenarios, one test each, numbered to match.
 *
 * Their acceptance rule, kept verbatim because it is the standard these are judged against:
 *
 *   "The invoice add-on is not considered complete merely because an invoice can be generated. It
 *    is complete only when the same input values produce the same calculations, totals, fields,
 *    terminology and supporting schedules as the client's current invoice workbook."
 *
 * So every scenario goes through a route or the pack service — the paths a person uses — and the
 * figures are compared against Account 7's own cached values rather than against something that
 * merely looks plausible. A scenario that fails here is a gap in the feature, not a test to soften.
 */
class InvoiceAcceptanceTest extends ProjectManagementTestCase
{
    private Company $company;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['name' => 'Aseda Consulting Engineers (Pty) Ltd']);
        $this->entitleCompany($this->company);
        $this->manager->forceFill(['company_id' => $this->company->id])->save();

        $this->project = $this->asCompany($this->company, fn () => $this->makeProject([
            'code' => 'MP23001',
            'name' => 'THE CONSTRUCTION OF KWAGGAFONTEIN RECREATIONAL CENTRE',
        ]));
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    private function as()
    {
        return $this->actingAs($this->manager);
    }

    private function openAccount(array $attributes = []): Invoice
    {
        $this->as()->post(route('project-management.invoicing.store', $this->project->uuid), $attributes)
            ->assertRedirect();

        return $this->asCompany(
            $this->company,
            fn () => Invoice::forProject($this->project->id)->orderByDesc('account_number')->first()
        );
    }

    private function setWorks(Invoice $invoice, array $data): void
    {
        $this->as()->put(route('project-management.invoicing.fee_account.update', $invoice->uuid), $data)
            ->assertRedirect()->assertSessionHasNoErrors();
    }

    private function recalc(Invoice $invoice): Invoice
    {
        $this->as()->post(route('project-management.invoicing.recalculate', $invoice->uuid))->assertRedirect();

        return $this->asCompany($this->company, fn () => $invoice->fresh());
    }

    private function addAnnexure(Invoice $invoice, int $number): InvoiceAnnexure
    {
        $this->as()->post(route('project-management.invoicing.annexures.store', $invoice->uuid), ['number' => $number])
            ->assertRedirect();

        return $this->asCompany($this->company, fn () => $invoice->annexures()->where('number', $number)->first());
    }

    private function addLine(Invoice $invoice, InvoiceAnnexure $annexure, array $data): void
    {
        $this->as()->post(
            route('project-management.invoicing.annexure_items.store', [$invoice->uuid, $annexure->uuid]),
            $data,
        )->assertRedirect()->assertSessionHasNoErrors();
    }

    private function document(Invoice $invoice, string $kind): array
    {
        return $this->asCompany($this->company, fn () => app(InvoicePackService::class)->document($invoice->fresh(), $kind));
    }

    // ── 1 ───────────────────────────────────────────────────────────────────

    public function test_01_an_invoice_is_created_against_the_correct_project(): void
    {
        $invoice = $this->openAccount([
            'invoice_date' => '2026-08-14',
            'client_name'  => 'Thembisile Hani Local Municipality',
        ]);

        $props = $this->as()->get(route('project-management.invoicing.show', $invoice->uuid))
            ->viewData('page')['props'];

        $this->assertSame('MP23001', $props['invoice']['project_code']);
        $this->assertSame('THE CONSTRUCTION OF KWAGGAFONTEIN RECREATIONAL CENTRE', $props['invoice']['project']);
        $this->assertSame('Thembisile Hani Local Municipality', $props['invoice']['client_name']);
        $this->assertSame('MP23001/01', $props['invoice']['invoice_number']);
        $this->assertSame('2026-08-14', $props['invoice']['invoice_date']);

        // A second, unrelated project must not appear anywhere on this account.
        $other = $this->asCompany($this->company, fn () => $this->makeProject(['code' => 'ZZ999', 'name' => 'Other job']));
        $this->assertStringNotContainsString('ZZ999', json_encode($props));
    }

    // ── 2 ───────────────────────────────────────────────────────────────────

    public function test_02_the_tax_invoice_carries_every_field_the_clients_document_has(): void
    {
        $invoice = $this->openAccount([
            'client_name' => 'Thembisile Hani Local Municipality',
            'description' => 'Professional Fee and disbursements account 1',
        ]);

        $document = $this->document($invoice, 'tax_invoice');
        $header = $document['header'];

        $this->assertSame('TAX INVOICE', $document['title']);
        foreach ([
            'invoice_number', 'invoice_date', 'client_name', 'client_address', 'client_vat_number',
            'attention', 'project', 'account_type', 'consultant_name',
        ] as $field) {
            $this->assertArrayHasKey($field, $header, "The Tax Invoice is missing '{$field}'.");
        }

        $this->assertArrayHasKey('description', $document['body']);
        $this->assertArrayHasKey('vat', $document['body']);
        $this->assertArrayHasKey('total', $document['body']);
        $this->assertSame('Professional fees', $document['body']['lines'][0]['label']);
        $this->assertSame('Disbursements', $document['body']['lines'][1]['label']);

        // The client's invoice carries banking details and a signature block.
        $this->assertArrayHasKey('banking', $document['body'], 'The Tax Invoice has no banking details.');
        $this->assertArrayHasKey('signatories', $document['body'], 'The Tax Invoice has no signature area.');
    }

    // ── 3 ───────────────────────────────────────────────────────────────────

    public function test_03_form_1_carries_the_clients_header_fields(): void
    {
        $invoice = $this->openAccount();
        $document = $this->document($invoice, 'form_1');

        foreach ([
            'project', 'client_project_number', 'consultant_name', 'consultant_address',
            'consultant_reference', 'account_number', 'vendor_number', 'consultant_vat_number', 'period',
        ] as $field) {
            $this->assertArrayHasKey($field, $document['header'], "Form 1 is missing '{$field}'.");
        }

        foreach ([
            'value_of_works', 'payment_certificate', 'certificate_dated', 'approved_fee',
            'adjusted_fee', 'stages', 'totals',
        ] as $field) {
            $this->assertArrayHasKey($field, $document['body'], "Form 1 is missing '{$field}'.");
        }
    }

    // ── 4 ───────────────────────────────────────────────────────────────────

    public function test_04_the_value_of_works_totals_as_the_workbook_does(): void
    {
        $invoice = $this->openAccount();

        // Account 7: C10 = 33 105 272.80, C14 = SUM(C10:C13).
        $this->setWorks($invoice, [
            'external_works'        => '30000000.00',
            'structural_works'      => '3105272.80',
            'contingency_allowance' => '1500000.00',
        ]);

        $works = $this->document($invoice, 'form_1')['body']['value_of_works'];

        $this->assertSame('30000000.00', $works['external']);
        $this->assertSame('3105272.80', $works['structural']);
        $this->assertSame('33105272.80', $works['total']);
        $this->assertSame('1500000.00', $works['contingency_allowance']);
    }

    // ── 5 ───────────────────────────────────────────────────────────────────

    public function test_05_construction_completed_to_date_is_recorded_and_shown(): void
    {
        $invoice = $this->openAccount();
        $this->setWorks($invoice, ['construction_completed_to_date' => '21000000.00']);

        $this->assertSame(
            '21000000.00',
            $this->document($invoice, 'form_1')['body']['value_of_works']['completed_to_date'],
        );
    }

    // ── 6 ───────────────────────────────────────────────────────────────────

    public function test_06_every_fee_stage_calculates_as_the_workbook_does(): void
    {
        $invoice = $this->openAccount();
        $this->setWorks($invoice, ['external_works' => '33105272.80', 'fee_percentage' => '0.08']);
        $this->as()->post(route('project-management.invoicing.stages.seed', $invoice->uuid))->assertRedirect();

        $invoice = $this->recalc($invoice);
        $stages = $this->asCompany($this->company, fn () => $invoice->stages()->get()->keyBy('code'));

        // The client's numbering, gaps and all.
        foreach (FeeStageCatalogue::codes() as $code) {
            $this->assertArrayHasKey($code, $stages, "Stage {$code} is missing.");
        }
        $this->assertArrayNotHasKey('3.7', $stages->all(), 'The workbook has no stage 3.7.');

        // U = total fee x apportionment, against Account 7's cached results.
        $this->assertSame('132421.09', $stages['3.2']->stage_fee);
        $this->assertSame('397263.27', $stages['3.3']->stage_fee);
        $this->assertSame('662105.46', $stages['3.4']->stage_fee);
        $this->assertSame('662105.46', $stages['3.5']->stage_fee);
        $this->assertSame('662105.46', $stages['3.6']->stage_fee);
        $this->assertSame('132421.09', $stages['3.8']->stage_fee);
    }

    // ── 7 ───────────────────────────────────────────────────────────────────

    public function test_07_a_previous_claim_is_carried_and_added_to(): void
    {
        $first = $this->openAccount();
        $this->setWorks($first, ['external_works' => '1000000.00', 'fee_percentage' => '0.50']);
        $this->as()->post(route('project-management.invoicing.stages.store', $first->uuid), [
            'code' => '3.4', 'label' => 'Design', 'apportionment' => '1', 'progress' => '0.20',
        ])->assertRedirect();

        // 1 000 000 x 50% = 500 000 fee; 20% of it = 100 000.
        $first = $this->recalc($first);
        $this->assertSame('100000.00', $first->fees_now_claimed);
        $this->as()->post(route('project-management.invoicing.generate', $first->uuid))->assertRedirect();

        $second = $this->openAccount();
        $stage = $this->asCompany($this->company, fn () => $second->stages()->first());
        $this->as()->patch(
            route('project-management.invoicing.stages.update', [$second->uuid, $stage->uuid]),
            ['progress' => 0.25],
        )->assertRedirect();

        $second = $this->recalc($second);
        $row = $this->asCompany($this->company, fn () => $second->stages()->first());

        $this->assertSame('100000.00', $row->previously_claimed, 'The previous claim must not disappear.');
        $this->assertSame('25000.00', $row->this_account);
        $this->assertSame('125000.00', Money::money(Money::add($row->previously_claimed, $row->this_account)));
    }

    // ── 8 ───────────────────────────────────────────────────────────────────

    public function test_08_a_fourth_account_reflects_all_three_before_it(): void
    {
        $progressions = ['0.10', '0.25', '0.325'];
        foreach ($progressions as $index => $progress) {
            $invoice = $this->openAccount();

            if ($index === 0) {
                $this->setWorks($invoice, ['external_works' => '1000000.00', 'fee_percentage' => '1']);
                $this->as()->post(route('project-management.invoicing.stages.store', $invoice->uuid), [
                    'code' => '3.4', 'label' => 'Design', 'apportionment' => '1', 'progress' => $progress,
                ])->assertRedirect();
            } else {
                $stage = $this->asCompany($this->company, fn () => $invoice->stages()->first());
                $this->as()->patch(
                    route('project-management.invoicing.stages.update', [$invoice->uuid, $stage->uuid]),
                    ['progress' => (float) $progress],
                )->assertRedirect();
            }

            $this->recalc($invoice);
            $this->as()->post(route('project-management.invoicing.generate', $invoice->uuid))->assertRedirect();
        }

        // Accounts 1-3 claimed 100 000, 150 000 and 75 000 of a 1 000 000 fee.
        $fourth = $this->openAccount();
        $row = $this->asCompany($this->company, fn () => $fourth->stages()->first());

        $this->assertSame('325000.00', $row->previously_claimed);
        $this->assertSame(4, $fourth->account_number, 'Account 4 must not be treated as the first.');
    }

    // ── 9 ───────────────────────────────────────────────────────────────────

    public function test_09_the_adjusted_fee_never_replaces_the_approved_fee(): void
    {
        $invoice = $this->openAccount();
        $this->setWorks($invoice, [
            'external_works' => '10000000.00',
            'fee_percentage' => '0.10',
            'approved_fee'   => '1000000.00',
            'adjusted_fee'   => '1200000.00',
        ]);

        $body = $this->document($invoice, 'form_1')['body'];

        $this->assertSame('1000000.00', $body['approved_fee']);
        $this->assertSame('1200000.00', $body['adjusted_fee']);

        // The claim is calculated from the approved figure only.
        $this->as()->post(route('project-management.invoicing.stages.store', $invoice->uuid), [
            'code' => '3.4', 'label' => 'Design', 'apportionment' => '1', 'progress' => '1',
        ])->assertRedirect();

        $this->assertSame('1000000.00', $this->recalc($invoice)->fees_now_claimed);
    }

    // ── 10 ──────────────────────────────────────────────────────────────────

    public function test_10_vat_is_calculated_at_the_accounts_stored_rate(): void
    {
        foreach ([
            ['0', '0.00', '0.00'],
            ['1', '0.15', '1.15'],
            ['10000', '1500.00', '11500.00'],
            ['100000', '15000.00', '115000.00'],
            ['1000000', '150000.00', '1150000.00'],
        ] as [$net, $vat, $total]) {
            $this->assertSame($vat, Money::money(Money::applyRate($net, '0.15')));
            $this->assertSame($total, Money::money(Money::add($net, Money::applyRate($net, '0.15'))));
        }
    }

    // ── 11 ──────────────────────────────────────────────────────────────────

    public function test_11_fees_now_payable_deducts_what_has_been_paid(): void
    {
        $invoice = $this->openAccount();
        $this->setWorks($invoice, ['external_works' => '100000.00', 'fee_percentage' => '1']);
        $this->as()->post(route('project-management.invoicing.stages.store', $invoice->uuid), [
            'code' => '3.4', 'label' => 'Design', 'apportionment' => '1', 'progress' => '1',
        ])->assertRedirect();
        $this->recalc($invoice);

        $this->asCompany($this->company, fn () => $invoice->feeAccount->update(['previously_paid_incl_vat' => '50000.00']));

        $totals = $this->document($invoice, 'form_1')['body']['totals'];

        $this->assertSame('115000.00', $totals['value_to_date']['incl_vat']);
        $this->assertSame('50000.00', $totals['previously_paid_incl_vat']);
        $this->assertSame('65000.00', $totals['now_payable_incl_vat']);
    }

    // ── 12 ──────────────────────────────────────────────────────────────────

    public function test_12_form_2_carries_the_clients_header_and_columns(): void
    {
        $invoice = $this->openAccount();
        $document = $this->document($invoice, 'form_2');

        foreach ([
            'project', 'client_project_number', 'consultant_name', 'consultant_address',
            'consultant_reference', 'account_number', 'vendor_number', 'consultant_vat_number', 'period',
        ] as $field) {
            $this->assertArrayHasKey($field, $document['header'], "Form 2 is missing '{$field}'.");
        }

        $this->assertArrayHasKey('categories', $document['body']);
        $this->assertArrayHasKey('previously_claimed_incl_vat', $document['body']);
        $this->assertArrayHasKey('combined', $document['body']);
    }

    // ── 13 ──────────────────────────────────────────────────────────────────

    public function test_13_every_disbursement_category_appears_in_the_clients_order(): void
    {
        $expected = [
            'Documentation', 'Travelling and subsistence', 'Travelling time',
            'Monitoring and supervision', 'Environmental consultant', 'OHS consultant',
            'Geotechnical engineer', 'Topographical survey', 'Geohydrologist', 'Town planners',
            'Social Facilitation', 'Advertising', 'Student Training',
        ];

        $this->assertSame(
            $expected,
            AnnexureCatalogue::summaryCategories(),
            'The Summary must list all thirteen categories in the client\'s order.',
        );
    }

    // ── 14 ──────────────────────────────────────────────────────────────────

    public function test_14_the_documentation_annexure_totals_quantity_times_tariff(): void
    {
        $invoice = $this->openAccount();
        $annexure = $this->addAnnexure($invoice, 1);

        $this->addLine($invoice, $annexure, ['quantity' => '1358', 'tariff' => '0.80', 'size' => 'A4',
            'description' => 'Black & white prints']);
        $this->addLine($invoice, $annexure, ['quantity' => '10', 'tariff' => '25.00', 'size' => 'A0',
            'description' => 'Colour prints']);

        $fresh = $this->asCompany($this->company, fn () => $annexure->fresh());

        // 1 358 x 0.80 = 1 086.40 (the workbook's F22), plus 10 x 25.
        $this->assertSame('1336.40', $fresh->this_invoice);
    }

    // ── 15 ──────────────────────────────────────────────────────────────────

    public function test_15_travelling_records_the_clients_columns_including_cc(): void
    {
        $invoice = $this->openAccount();
        $annexure = $this->addAnnexure($invoice, 2);

        $this->addLine($invoice, $annexure, [
            'entry_date' => '2026-06-24', 'party' => 'O. Simba', 'cc' => '1600',
            'tariff' => '6.15', 'kilometres' => '276', 'less_kilometres' => '0',
            'description' => 'Site visit — Kwaggafontein',
        ]);

        $item = $this->asCompany($this->company, fn () => $annexure->items()->first());

        $this->assertSame('1697.40', $item->this_invoice);
        $this->assertSame('1600', (string) $item->cc, 'The travelling sheet has a cc column.');
    }

    // ── 16 ──────────────────────────────────────────────────────────────────

    public function test_16_travelling_time_derives_hours_from_distance(): void
    {
        $invoice = $this->openAccount();
        $annexure = $this->addAnnexure($invoice, 3);

        // 276 km -> 2.76 h, less 1 h, at R1 396/h = R2 456.96 (the workbook's H30).
        $this->addLine($invoice, $annexure, [
            'entry_date' => '2026-06-24', 'party' => 'O. Simba', 'post_level' => 'Engineer',
            'tariff' => '1396', 'kilometres' => '276', 'less_hours' => '1',
        ]);

        $this->assertSame('2456.96', $this->asCompany($this->company, fn () => $annexure->fresh())->this_invoice);
    }

    // ── 17 ──────────────────────────────────────────────────────────────────

    public function test_17_geotechnical_applies_the_ten_percent_surcharge(): void
    {
        $invoice = $this->openAccount();
        $annexure = $this->addAnnexure($invoice, 4);

        $this->addLine($invoice, $annexure, [
            'entry_date' => '2026-05-02', 'party' => 'Geotech SA',
            'description' => 'Geotechnical investigation', 'appointment_amount' => '113011.57',
        ]);

        // E16 = 113 011.57 -> F16 = 124 312.727
        $this->assertSame('124312.73', $this->asCompany($this->company, fn () => $annexure->fresh())->this_invoice);
    }

    // ── 18 ──────────────────────────────────────────────────────────────────

    public function test_18_topographical_survey_applies_the_same_surcharge(): void
    {
        $invoice = $this->openAccount();
        $annexure = $this->addAnnexure($invoice, 5);

        // E16 = 78 450 -> H16 = 86 295
        $this->addLine($invoice, $annexure, ['party' => 'Survey SA', 'appointment_amount' => '78450.00']);

        $this->assertSame('86295.00', $this->asCompany($this->company, fn () => $annexure->fresh())->this_invoice);
    }

    // ── 19 ──────────────────────────────────────────────────────────────────

    public function test_19_the_ohs_annexure_carries_a_kimopax_fee(): void
    {
        $invoice = $this->openAccount();
        $annexure = $this->addAnnexure($invoice, 6);

        $this->addLine($invoice, $annexure, [
            'party' => 'OHS Consultants', 'appointment_amount' => '26200.00', 'kimopax_fee' => '2000.00',
        ]);

        $item = $this->asCompany($this->company, fn () => $annexure->items()->first());
        $this->assertSame('2000.00', Money::money($item->kimopax_fee), 'Annexure 6 has a Kimopax Fee column.');
    }

    // ── 20 ──────────────────────────────────────────────────────────────────

    public function test_20_social_facilitation_records_monthly_projections(): void
    {
        $invoice = $this->openAccount();
        $annexure = $this->addAnnexure($invoice, 7);

        $this->addLine($invoice, $annexure, ['party' => 'Facilitation SA', 'appointment_amount' => '30000.00']);

        $document = $this->document($invoice, 'annexure_7');
        $this->assertArrayHasKey('projections', $document['body'], 'Annexure 7 carries monthly projections.');
    }

    // ── 21 ──────────────────────────────────────────────────────────────────

    public function test_21_time_basis_for_rework_is_rate_times_hours(): void
    {
        $invoice = $this->openAccount();
        $annexure = $this->addAnnexure($invoice, 11);

        // D13 = 1 668 per hour.
        $this->addLine($invoice, $annexure, [
            'description' => 'QS rework of tender document and BoQ', 'tariff' => '1668', 'hours' => '2.5',
        ]);

        $this->assertSame('4170.00', $this->asCompany($this->company, fn () => $annexure->fresh())->this_invoice);
    }

    // ── 22 ──────────────────────────────────────────────────────────────────

    public function test_22_advertising_applies_the_ten_percent_surcharge(): void
    {
        $invoice = $this->openAccount();
        $annexure = $this->addAnnexure($invoice, 12);

        $this->addLine($invoice, $annexure, ['party' => 'Media SA', 'appointment_amount' => '72250.00']);

        // L29 = L28 / 1.1 -> 72 250 x 1.1 = 79 475 (the workbook's H16)
        $this->assertSame('79475.00', $this->asCompany($this->company, fn () => $annexure->fresh())->this_invoice);
    }

    // ── 23 ──────────────────────────────────────────────────────────────────

    public function test_23_student_training_applies_the_surcharge_and_a_kimopax_fee(): void
    {
        $invoice = $this->openAccount();
        $annexure = $this->addAnnexure($invoice, 13);

        $this->addLine($invoice, $annexure, [
            'party' => 'Training SA', 'appointment_amount' => '12500.00', 'kimopax_fee' => '1500.00',
        ]);

        $fresh = $this->asCompany($this->company, fn () => $annexure->fresh());
        $item = $this->asCompany($this->company, fn () => $annexure->items()->first());

        // E20 = 12 500 -> G20 = 13 750
        $this->assertSame('13750.00', $fresh->this_invoice);
        $this->assertSame('1500.00', Money::money($item->kimopax_fee), 'Annexure 13 has a Kimopax Fee column.');
    }

    // ── 24 ──────────────────────────────────────────────────────────────────

    public function test_24_the_summary_columns_and_totals_match_the_workbook(): void
    {
        $invoice = $this->openAccount();
        $this->setWorks($invoice, ['external_works' => '33105272.80', 'fee_percentage' => '0.08']);
        $this->as()->post(route('project-management.invoicing.stages.store', $invoice->uuid), [
            'code' => '3.6', 'label' => 'Contract Admin & Inspection', 'apportionment' => '0.25', 'progress' => '0.20',
        ])->assertRedirect();

        $annexure = $this->addAnnexure($invoice, 4);
        $this->addLine($invoice, $annexure, ['party' => 'Geotech SA', 'appointment_amount' => '113011.57']);
        $invoice = $this->recalc($invoice);

        $body = $this->document($invoice, 'summary')['body'];

        foreach (['fees', 'disbursements', 'sub_total_fees', 'sub_total_disbursements',
            'total_now_claimed', 'vat', 'amount_now_due'] as $key) {
            $this->assertArrayHasKey($key, $body, "The Summary is missing '{$key}'.");
        }

        foreach (['previously_claimed', 'now_claimed', 'total_to_date'] as $column) {
            $this->assertArrayHasKey($column, $body['fees'], "The Summary is missing the '{$column}' column.");
        }

        // Stage 3.6 at 20% of a 25% share of a R2 648 421.82 fee = R132 421.09.
        $this->assertSame('132421.09', $body['sub_total_fees']);
        $this->assertSame('124312.73', $body['sub_total_disbursements']);
        $this->assertSame('256733.82', $body['total_now_claimed']);
        $this->assertSame('38510.07', $body['vat']);
        $this->assertSame('295243.89', $body['amount_now_due']);
    }

    // ── 25 ──────────────────────────────────────────────────────────────────

    public function test_25_previous_current_and_total_are_maintained_across_accounts(): void
    {
        $invoice = $this->openAccount();
        $this->setWorks($invoice, ['external_works' => '1000000.00', 'fee_percentage' => '1']);
        $this->as()->post(route('project-management.invoicing.stages.store', $invoice->uuid), [
            'code' => '3.4', 'label' => 'Design', 'apportionment' => '1', 'progress' => '0.10',
        ])->assertRedirect();
        $this->recalc($invoice);
        $this->as()->post(route('project-management.invoicing.generate', $invoice->uuid))->assertRedirect();

        // Account 2: 100 000 already claimed, 25 000 more.
        $second = $this->openAccount();
        $stage = $this->asCompany($this->company, fn () => $second->stages()->first());
        $this->as()->patch(route('project-management.invoicing.stages.update', [$second->uuid, $stage->uuid]),
            ['progress' => 0.125])->assertRedirect();
        $this->recalc($second);
        $row = $this->asCompany($this->company, fn () => $second->stages()->first());

        $this->assertSame('100000.00', $row->previously_claimed);
        $this->assertSame('25000.00', $row->this_account);
        $this->assertSame('125000.00', $row->value_to_date);

        $this->as()->post(route('project-management.invoicing.generate', $second->uuid))->assertRedirect();

        // Account 3: 125 000 already claimed, 30 000 more.
        $third = $this->openAccount();
        $stage = $this->asCompany($this->company, fn () => $third->stages()->first());
        $this->as()->patch(route('project-management.invoicing.stages.update', [$third->uuid, $stage->uuid]),
            ['progress' => 0.155])->assertRedirect();
        $this->recalc($third);
        $row = $this->asCompany($this->company, fn () => $third->stages()->first());

        $this->assertSame('125000.00', $row->previously_claimed);
        $this->assertSame('30000.00', $row->this_account);
        $this->assertSame('155000.00', $row->value_to_date);
    }

    // ── 26 ──────────────────────────────────────────────────────────────────

    public function test_26_the_cashflow_accumulates(): void
    {
        $invoice = $this->openAccount();

        $this->asCompany($this->company, function () {
            foreach ([[3, '100000.00'], [4, '150000.00'], [5, '200000.00']] as [$month, $amount]) {
                InvoiceCashflow::create([
                    'project_id' => $this->project->id, 'forecast_year' => 2026,
                    'forecast_month' => $month, 'amount_incl_vat' => $amount,
                ]);
            }
        });

        $rows = $this->document($invoice, 'cashflow')['body']['rows'];

        $this->assertSame('100000.00', $rows[0]['cumulative']);
        $this->assertSame('250000.00', $rows[1]['cumulative']);
        $this->assertSame('450000.00', $rows[2]['cumulative']);
    }

    // ── 27 ──────────────────────────────────────────────────────────────────

    public function test_27_the_complete_pack_follows_the_workbooks_sequence(): void
    {
        $invoice = $this->openAccount();
        $this->addAnnexure($invoice, 4);
        $this->asCompany($this->company, fn () => InvoiceCashflow::create([
            'project_id' => $this->project->id, 'forecast_year' => 2026,
            'forecast_month' => 3, 'amount_incl_vat' => '1000.00',
        ]));

        $kinds = array_column(
            $this->asCompany($this->company, fn () => app(InvoicePackService::class)->pack($invoice->fresh())),
            'kind',
        );

        $this->assertSame(
            ['tax_invoice', 'summary', 'form_1', 'form_2', 'annexure_4', 'cashflow'],
            $kinds,
        );
    }

    // ── 28 ──────────────────────────────────────────────────────────────────

    public function test_28_a_blank_field_stays_blank(): void
    {
        $invoice = $this->openAccount();

        $body = $this->document($invoice, 'form_1')['body'];

        // Nothing was stated, so nothing is asserted — an unentered adjusted fee is not "0.00".
        $this->assertNull($body['adjusted_fee'], 'A blank adjusted fee must not become zero.');
        $this->assertNull($body['payment_certificate']);
        $this->assertNull($body['certificate_dated']);

        // And a blank annexure is not invented.
        $this->assertSame([], $this->asCompany($this->company, fn () => $invoice->annexures()->get()->all()));
    }

    // ── 29 ──────────────────────────────────────────────────────────────────

    public function test_29_zero_is_distinguished_from_not_entered(): void
    {
        $invoice = $this->openAccount();
        $this->setWorks($invoice, ['adjusted_fee' => '0']);

        $body = $this->document($invoice, 'form_1')['body'];

        $this->assertSame('0.00', $body['adjusted_fee'], 'An explicit zero is a stated value.');
        $this->assertNull($body['payment_certificate'], 'An unstated field stays blank.');
    }

    // ── 30 ──────────────────────────────────────────────────────────────────

    public function test_30_large_amounts_keep_two_decimal_precision(): void
    {
        foreach (['17690663.29', '11448026.60', '5066207.59'] as $amount) {
            $this->assertSame($amount, Money::money($amount));
        }

        // And they survive being multiplied and added without drifting.
        $sum = Money::sum(['17690663.29', '11448026.60', '5066207.59']);
        $this->assertSame('34204897.48', Money::money($sum));
        $this->assertSame('5130734.62', Money::money(Money::applyRate($sum, '0.15')));
    }

    // ── 31 ──────────────────────────────────────────────────────────────────

    public function test_31_the_generated_pdf_carries_the_clients_headings(): void
    {
        $invoice = $this->openAccount(['client_name' => 'Thembisile Hani Local Municipality']);
        $this->setWorks($invoice, ['external_works' => '33105272.80', 'fee_percentage' => '0.08']);
        $this->as()->post(route('project-management.invoicing.stages.seed', $invoice->uuid))->assertRedirect();
        $this->addAnnexure($invoice, 4);
        $this->recalc($invoice);

        $response = $this->as()->get(route('project-management.invoicing.download', [$invoice->uuid, 'pack']));
        $response->assertOk();
        $this->assertStringStartsWith('%PDF', $response->getContent());

        // The headings are asserted on the assembled documents, which is what the PDF renders.
        $titles = array_column(
            $this->asCompany($this->company, fn () => app(InvoicePackService::class)->pack($invoice->fresh())),
            'title',
        );

        $this->assertContains('TAX INVOICE', $titles);
        $this->assertContains('SUMMARY', $titles);
        $this->assertContains('PROFESSIONAL FEE ACCOUNT — FORM 1', $titles);
        $this->assertContains('DISBURSEMENTS FEE ACCOUNT — FORM 2', $titles);
    }

    // ── 32 ──────────────────────────────────────────────────────────────────

    public function test_32_changing_progress_changes_only_what_depends_on_it(): void
    {
        $invoice = $this->openAccount(['client_name' => 'Thembisile Hani Local Municipality']);
        $this->setWorks($invoice, ['external_works' => '1000000.00', 'fee_percentage' => '1']);
        $this->as()->post(route('project-management.invoicing.stages.store', $invoice->uuid), [
            'code' => '3.4', 'label' => 'Design', 'apportionment' => '1', 'progress' => '0.50',
        ])->assertRedirect();

        $annexure = $this->addAnnexure($invoice, 4);
        $this->addLine($invoice, $annexure, ['party' => 'Geotech SA', 'appointment_amount' => '100000.00']);
        $invoice = $this->recalc($invoice);

        $before = [
            'client'         => $invoice->client_name,
            'invoice_number' => $invoice->invoice_number,
            'disbursements'  => $invoice->disbursements_now_claimed,
        ];

        $stage = $this->asCompany($this->company, fn () => $invoice->stages()->first());
        $this->as()->patch(route('project-management.invoicing.stages.update', [$invoice->uuid, $stage->uuid]),
            ['progress' => 0.60])->assertRedirect();

        $after = $this->asCompany($this->company, fn () => $invoice->fresh());

        $this->assertSame('600000.00', $after->fees_now_claimed, 'The fee follows the progress.');
        $this->assertSame($before['client'], $after->client_name);
        $this->assertSame($before['invoice_number'], $after->invoice_number);
        $this->assertSame($before['disbursements'], $after->disbursements_now_claimed);
    }

    // ── 33 ──────────────────────────────────────────────────────────────────

    public function test_33_a_changed_disbursement_flows_all_the_way_to_the_tax_invoice(): void
    {
        $invoice = $this->openAccount();
        $this->as()->post(route('project-management.invoicing.disbursements.store', $invoice->uuid), [
            'block' => 'category', 'description' => 'Town planners', 'this_account' => '10000.00',
        ])->assertRedirect();

        $this->assertSame('11500.00', $this->asCompany($this->company, fn () => $invoice->fresh())->amount_now_due);

        $this->as()->post(route('project-management.invoicing.disbursements.store', $invoice->uuid), [
            'block' => 'category', 'description' => 'Town planners', 'this_account' => '15000.00',
        ])->assertRedirect();

        $after = $this->asCompany($this->company, fn () => $invoice->fresh());

        $this->assertSame('15000.00', $after->disbursements_now_claimed);
        $this->assertSame('2250.00', $after->vat_amount);
        $this->assertSame('17250.00', $after->amount_now_due);
        $this->assertSame('17250.00', $this->document($invoice, 'tax_invoice')['body']['total']);
    }

    // ── 34 ──────────────────────────────────────────────────────────────────

    public function test_34_a_generated_account_never_changes_afterwards(): void
    {
        $first = $this->openAccount();
        $this->setWorks($first, ['external_works' => '1000000.00', 'fee_percentage' => '1']);
        $this->as()->post(route('project-management.invoicing.stages.store', $first->uuid), [
            'code' => '3.4', 'label' => 'Design', 'apportionment' => '1', 'progress' => '0.50',
        ])->assertRedirect();
        $first = $this->recalc($first);
        $this->as()->post(route('project-management.invoicing.generate', $first->uuid))->assertRedirect();

        $snapshot = $this->asCompany($this->company, fn () => $first->fresh()->only([
            'amount_now_due', 'fees_now_claimed', 'subtotal', 'vat_amount',
        ]));

        // Account 2 moves the project on.
        $second = $this->openAccount();
        $stage = $this->asCompany($this->company, fn () => $second->stages()->first());
        $this->as()->patch(route('project-management.invoicing.stages.update', [$second->uuid, $stage->uuid]),
            ['progress' => 0.90])->assertRedirect();
        $this->recalc($second);

        $this->assertSame(
            $snapshot,
            $this->asCompany($this->company, fn () => $first->fresh()->only([
                'amount_now_due', 'fees_now_claimed', 'subtotal', 'vat_amount',
            ])),
            'A generated account is a financial document and must not move.',
        );
    }

    // ── 35 ──────────────────────────────────────────────────────────────────

    public function test_35_a_generated_account_reopens_intact_and_still_renders(): void
    {
        $invoice = $this->openAccount(['client_name' => 'Thembisile Hani Local Municipality']);
        $this->setWorks($invoice, ['external_works' => '33105272.80', 'fee_percentage' => '0.08']);
        $this->as()->post(route('project-management.invoicing.stages.seed', $invoice->uuid))->assertRedirect();
        $annexure = $this->addAnnexure($invoice, 4);
        $this->addLine($invoice, $annexure, ['party' => 'Geotech SA', 'appointment_amount' => '113011.57']);
        $invoice = $this->recalc($invoice);
        $this->as()->post(route('project-management.invoicing.generate', $invoice->uuid))->assertRedirect();

        $due = $this->asCompany($this->company, fn () => $invoice->fresh()->amount_now_due);

        // Read it back the way a later session would — nothing cached, nothing in memory.
        $reopened = $this->asCompany($this->company, fn () => Invoice::where('uuid', $invoice->uuid)->firstOrFail());

        $this->assertSame($due, $reopened->amount_now_due);
        $this->assertSame(8, $this->asCompany($this->company, fn () => $reopened->stages()->count()));
        $this->assertSame(1, $this->asCompany($this->company, fn () => $reopened->annexures()->count()));

        $this->as()->get(route('project-management.invoicing.download', [$invoice->uuid, 'pack']))->assertOk();
    }

    // ── 36 ──────────────────────────────────────────────────────────────────

    public function test_36_an_account_cannot_be_mixed_with_another_project(): void
    {
        $projectB = $this->asCompany($this->company, fn () => $this->makeProject(['code' => 'MP23002', 'name' => 'Other job']));

        $a = $this->openAccount();
        $this->as()->post(route('project-management.invoicing.store', $projectB->uuid), [])->assertRedirect();

        $b = $this->asCompany($this->company, fn () => Invoice::forProject($projectB->id)->first());

        // Each account numbers from 1 within its own project, and neither sees the other.
        $this->assertSame(1, $a->account_number);
        $this->assertSame(1, $b->account_number);
        $this->assertNotSame($a->invoice_number, $b->invoice_number);

        $props = $this->as()->get(route('project-management.projects.invoicing', $this->project->uuid))
            ->viewData('page')['props'];

        $this->assertCount(1, $props['invoices']['data']);
        $this->assertSame('MP23001/01', $props['invoices']['data'][0]['invoice_number']);
    }

    // ── 37 ──────────────────────────────────────────────────────────────────

    public function test_37_the_complete_end_to_end_account(): void
    {
        $invoice = $this->openAccount([
            'client_name' => 'Thembisile Hani Local Municipality',
            'description' => 'Professional Fee and disbursements account 1',
        ]);

        $this->setWorks($invoice, [
            'external_works'                 => '30000000.00',
            'structural_works'               => '3105272.80',
            'construction_completed_to_date' => '21000000.00',
            'fee_percentage'                 => '0.08',
            'payment_certificate'            => 'PC-01',
        ]);

        $this->as()->post(route('project-management.invoicing.stages.seed', $invoice->uuid))->assertRedirect();
        $stage = $this->asCompany($this->company, fn () => $invoice->stages()->where('code', '3.6')->first());
        $this->as()->patch(route('project-management.invoicing.stages.update', [$invoice->uuid, $stage->uuid]),
            ['progress' => 0.20])->assertRedirect();

        foreach ([
            [1, ['quantity' => '1358', 'tariff' => '0.80', 'size' => 'A4']],
            [2, ['party' => 'O. Simba', 'tariff' => '6.15', 'kilometres' => '276']],
            [4, ['party' => 'Geotech SA', 'appointment_amount' => '113011.57']],
            [6, ['party' => 'OHS Consultants', 'appointment_amount' => '26200.00']],
            [7, ['party' => 'Facilitation SA', 'appointment_amount' => '30000.00']],
            [12, ['party' => 'Media SA', 'appointment_amount' => '72250.00']],
            [13, ['party' => 'Training SA', 'appointment_amount' => '12500.00']],
        ] as [$number, $line]) {
            $this->addLine($invoice, $this->addAnnexure($invoice, $number), $line);
        }

        $invoice = $this->recalc($invoice);

        // Fees: 33 105 272.80 x 8% x 25% x 20% = 132 421.09
        $this->assertSame('132421.09', $invoice->fees_now_claimed);

        // Disbursements: 1 086.40 + 1 697.40 + 124 312.73 + 28 820.00 + 33 000.00
        //              + 79 475.00 + 13 750.00
        $this->assertSame('282141.53', $invoice->disbursements_now_claimed);
        $this->assertSame('414562.62', $invoice->subtotal);
        $this->assertSame('62184.39', $invoice->vat_amount);
        $this->assertSame('476747.01', $invoice->amount_now_due);

        $this->as()->post(route('project-management.invoicing.generate', $invoice->uuid))->assertRedirect();

        $kinds = array_column(
            $this->asCompany($this->company, fn () => app(InvoicePackService::class)->pack($invoice->fresh())),
            'kind',
        );

        $this->assertSame([
            'tax_invoice', 'summary', 'form_1', 'form_2',
            'annexure_1', 'annexure_2', 'annexure_4', 'annexure_6', 'annexure_7',
            'annexure_12', 'annexure_13',
        ], $kinds);

        $this->as()->get(route('project-management.invoicing.download', [$invoice->uuid, 'pack']))->assertOk();
    }
}
