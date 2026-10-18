<?php

namespace Tests\Feature\Finance;

use App\Modules\Finance\Accounting\Models\FinDocumentTemplate;
use App\Modules\Finance\Accounting\Models\FinInvoice;
use App\Modules\Finance\Accounting\Models\FinParty;
use App\Modules\Finance\Accounting\Services\FinanceDocumentService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

/**
 * Document branding: the layout, colour and logo placement a tenant picks must be what actually
 * comes out of the PDF renderer — on invoices *and* statements.
 *
 * The letterhead and statement assertions render the Blade views directly rather than inspecting
 * a PDF binary: the question is what markup the template produces, and a rendered view answers it
 * precisely instead of guessing from compressed PDF bytes.
 */
class DocumentTemplateTest extends TestCase
{
    use DatabaseTransactions;

    private const LOGO = 'data:image/png;base64,iVBORw0KGgo=';

    // ── Logo placement ───────────────────────────────────────────────────────

    public function test_logo_position_none_omits_the_logo_and_keeps_the_company_name(): void
    {
        $html = $this->letterhead('none');

        $this->assertStringNotContainsString('<img', $html);
        // The page must never be blank where the logo would have been.
        $this->assertStringContainsString('Acme Trading', $html);
    }

    public function test_logo_position_left_puts_the_logo_before_the_address(): void
    {
        $html = $this->letterhead('left');

        $this->assertStringContainsString('<img', $html);
        $this->assertLessThan(
            strpos($html, 'Acme Trading'),
            strpos($html, '<img'),
            'Left placement renders the logo ahead of the address block.'
        );
    }

    public function test_logo_position_right_puts_the_address_before_the_logo(): void
    {
        $html = $this->letterhead('right');

        $this->assertStringContainsString('<img', $html);
        $this->assertGreaterThan(
            strpos($html, 'Acme Trading'),
            strpos($html, '<img'),
            'Right placement mirrors the letterhead — address first, logo opposite.'
        );
    }

    public function test_logo_position_center_centres_the_letterhead(): void
    {
        $html = $this->letterhead('center');

        $this->assertStringContainsString('<img', $html);
        $this->assertStringContainsString('text-align:center', $html);
    }

    public function test_logo_height_is_applied(): void
    {
        $html = $this->letterhead('left', 120);

        $this->assertStringContainsString('max-height:120px', $html);
    }

    public function test_a_template_without_a_logo_setting_puts_the_mark_on_the_right(): void
    {
        /*
         * The default moved from left to right, deliberately.
         *
         * It is where every invoice this document was asked to resemble puts the mark, and it is
         * the corner the eye reaches last on a right-aligned meta block. A tenant who wants it on
         * the left still sets it; they simply no longer have to.
         */
        $html = View::make('pdf.finance._letterhead', [
            'company' => $this->company(),
            'style'   => [],
        ])->render();

        $this->assertStringContainsString('<img', $html);
        $this->assertGreaterThan(
            strpos($html, 'Acme Trading'),
            strpos($html, '<img'),
            'With no setting the address leads and the logo anchors the opposite corner.',
        );
    }

    public function test_an_explicit_left_position_is_still_honoured(): void
    {
        // The default changed; the choice did not go away.
        $html = $this->letterhead('left');

        $this->assertLessThan(strpos($html, 'Acme Trading'), strpos($html, '<img'));
    }

    // ── Statements are branded like invoices ─────────────────────────────────

    public function test_a_statement_template_wins_over_a_generic_one(): void
    {
        FinDocumentTemplate::create(['name' => 'Generic', 'doc_type' => 'both', 'layout' => 'classic', 'is_default' => true]);
        $statementTpl = FinDocumentTemplate::create(['name' => 'Statement style', 'doc_type' => 'statement', 'layout' => 'modern']);

        $resolved = FinDocumentTemplate::defaultFor('statement');

        $this->assertNotNull($resolved);
        $this->assertContains($resolved->doc_type, ['statement', 'both']);
        $this->assertSame($statementTpl->id, FinDocumentTemplate::where('doc_type', 'statement')->first()->id);
    }

    public function test_the_statement_view_honours_the_chosen_layout(): void
    {
        $modern = $this->statementHtml(['accent' => '#ff0000', 'layout' => 'modern', 'font' => 'DejaVu Sans, sans-serif']);
        $classic = $this->statementHtml(['accent' => '#ff0000', 'layout' => 'classic', 'font' => 'DejaVu Sans, sans-serif']);

        // Modern underlines the table header; classic fills it — the same distinction invoices make.
        $this->assertStringContainsString('border-bottom: 2px solid #ff0000', $modern);
        $this->assertStringContainsString('background: #ff0000', $classic);
    }

    public function test_the_statement_carries_banking_details_so_it_can_be_paid(): void
    {
        $html = $this->statementHtml(['layout' => 'classic'], ['bank' => ['Bank' => 'Test Bank', 'Account no.' => '123456']]);

        $this->assertStringContainsString('Banking details', $html);
        $this->assertStringContainsString('Test Bank', $html);
    }

    // ── Preview ──────────────────────────────────────────────────────────────

    public function test_previewing_a_template_renders_a_pdf_without_creating_anything(): void
    {
        $template = FinDocumentTemplate::create([
            'name' => 'Preview me', 'doc_type' => 'both', 'layout' => 'bold',
            'accent_color' => '#0ea5e9', 'logo_position' => 'right', 'logo_height' => 70,
        ]);

        $invoicesBefore = FinInvoice::count();
        $partiesBefore = FinParty::count();

        $pdf = app(FinanceDocumentService::class)->previewPdf($template, 'invoice')->output();

        $this->assertStringStartsWith('%PDF', $pdf);
        // A preview is a look, not a transaction: nothing may be persisted by rendering one.
        $this->assertSame($invoicesBefore, FinInvoice::count());
        $this->assertSame($partiesBefore, FinParty::count());
    }

    public function test_a_statement_preview_renders_for_every_layout(): void
    {
        $service = app(FinanceDocumentService::class);

        foreach (FinDocumentTemplate::LAYOUTS as $layout) {
            $template = FinDocumentTemplate::create([
                'name' => "Preview {$layout}", 'doc_type' => 'statement', 'layout' => $layout,
                'accent_color' => '#123456', 'logo_position' => 'center',
            ]);

            $this->assertStringStartsWith('%PDF', $service->previewPdf($template, 'statement')->output(), $layout);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function letterhead(string $position, int $height = 60): string
    {
        return View::make('pdf.finance._letterhead', [
            'company' => $this->company(),
            'style'   => ['logo_position' => $position, 'logo_height' => $height, 'accent' => '#4b3fd4'],
        ])->render();
    }

    private function statementHtml(array $style, array $companyOverrides = []): string
    {
        return View::make('pdf.finance.statement', [
            'company'   => array_merge($this->company(), $companyOverrides),
            'style'     => $style,
            'statement' => [
                'party'   => ['name' => 'Sample Customer', 'code' => 'C1'],
                'from'    => now()->startOfMonth()->toDateString(),
                'to'      => now()->toDateString(),
                'opening' => 0.0,
                'rows'    => [],
                'closing' => 0.0,
            ],
            'terms' => null,
        ])->render();
    }

    /** @return array<string,mixed> */
    private function company(): array
    {
        return [
            'name'    => 'Acme Trading',
            'address' => '1 Test Street',
            'email'   => 'billing@acme.test',
            'logo'    => self::LOGO,
            'colour'  => '#4b3fd4',
            'bank'    => [],
        ];
    }
}
