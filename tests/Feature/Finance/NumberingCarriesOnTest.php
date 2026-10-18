<?php

namespace Tests\Feature\Finance;

use App\Modules\Finance\Accounting\Models\FinEntity;
use App\Modules\Finance\Accounting\Models\FinInvoice;
use App\Modules\Finance\Accounting\Models\FinNumberSeries;
use App\Modules\Finance\Accounting\Models\FinParty;
use App\Modules\Finance\Accounting\Models\FinQuote;
use App\Modules\Finance\Accounting\Services\CurrentEntity;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * "Quote number is not following properly."
 *
 * A business gets a number series the first time it issues a document, and it started that series
 * at one. But a business can already own documents: the books a tenant had before businesses
 * existed were renumbered into the default one. So a company whose last quote was QUO-000115
 * issued its next as QUO-000001 — sorting to the bottom of its own list, and sending a client a
 * quote number lower than the one before it.
 *
 * The series now carries on from the highest number already issued under its prefix.
 */
class NumberingCarriesOnTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;

    private FinEntity $mk;

    private FinEntity $move;

    protected function setUp(): void
    {
        parent::setUp();

        $companyId = DB::table('companies')->min('id');
        if ($companyId === null) {
            $this->markTestSkipped('No company in the test database.');
        }

        $this->companyId = (int) $companyId;
        app(CurrentCompany::class)->set($this->companyId);
        DB::statement("SELECT set_config('app.company_id', ?, false)", [(string) $companyId]);

        $this->mk   = $this->entity('Mkhaya MK');
        $this->move = $this->entity('Mkhaya Move');
    }

    protected function tearDown(): void
    {
        app(CurrentEntity::class)->clear();
        app(CurrentCompany::class)->clear();
        parent::tearDown();
    }

    private function entity(string $name): FinEntity
    {
        return FinEntity::withoutGlobalScopes()->create([
            'company_id' => $this->companyId,
            'name'       => $name.' '.Str::random(4),
            'status'     => 'active',
        ]);
    }

    private function quote(FinEntity $entity, string $number): void
    {
        app(CurrentEntity::class)->run($entity->id, function () use ($number) {
            $party = FinParty::create(['kind' => 'customer', 'name' => 'Client '.Str::random(5)]);

            FinQuote::create([
                'company_id' => $this->companyId,
                'party_id'   => $party->id,
                'number'     => $number,
                'quote_date' => now()->toDateString(),
                'currency'   => 'ZAR',
                'status'     => 'draft',
                'total'      => 100.00,
            ]);
        });
    }

    private function nextQuoteNumber(FinEntity $entity): string
    {
        return app(CurrentEntity::class)->run(
            $entity->id,
            fn () => FinNumberSeries::next($this->companyId, 'quote', 'QUO-')
        );
    }

    public function test_the_next_quote_follows_the_last_one(): void
    {
        // The books this business inherited, renumbered into it when businesses were introduced.
        $this->quote($this->mk, 'QUO-000115');

        $this->assertSame(
            'QUO-000116',
            $this->nextQuoteNumber($this->mk),
            'A business with quotes up to 000115 issued its next one from the beginning.'
        );
    }

    public function test_a_business_with_no_history_still_starts_at_one(): void
    {
        $this->assertSame('QUO-000001', $this->nextQuoteNumber($this->move));
    }

    public function test_one_businesss_history_does_not_push_anothers_numbering(): void
    {
        $this->quote($this->mk, 'QUO-000115');

        // Mkhaya Move is a new set of books. Its first quote is its first quote, whatever the
        // business beside it has issued.
        $this->assertSame('QUO-000001', $this->nextQuoteNumber($this->move));
        $this->assertSame('QUO-000116', $this->nextQuoteNumber($this->mk));
    }

    public function test_invoices_carry_on_too(): void
    {
        app(CurrentEntity::class)->run($this->mk->id, function () {
            $party = FinParty::create(['kind' => 'customer', 'name' => 'Client '.Str::random(5)]);

            FinInvoice::create([
                'company_id'   => $this->companyId,
                'party_id'     => $party->id,
                'direction'    => 'ar',
                'number'       => 'INV-000042',
                'invoice_date' => now()->toDateString(),
                'due_date'     => now()->addDays(30)->toDateString(),
                'currency'     => 'ZAR',
                'status'       => 'posted',
                'total'        => 1150.00,
            ]);
        });

        $this->assertSame(
            'INV-000043',
            app(CurrentEntity::class)->run(
                $this->mk->id,
                fn () => FinNumberSeries::next($this->companyId, 'ar_invoice', 'INV-')
            )
        );
    }

    public function test_a_hand_typed_reference_does_not_run_the_sequence_away(): void
    {
        // Somebody typing their own number must not push the next one into the millions, and a
        // non-numeric tail must not be cast to something arbitrary.
        $this->quote($this->mk, 'QUO-2026/ESKOM');
        $this->quote($this->mk, 'QUO-000007');

        $this->assertSame('QUO-000008', $this->nextQuoteNumber($this->mk));
    }

    public function test_the_series_keeps_counting_after_it_is_created(): void
    {
        $this->quote($this->mk, 'QUO-000115');

        $this->assertSame('QUO-000116', $this->nextQuoteNumber($this->mk));
        $this->assertSame('QUO-000117', $this->nextQuoteNumber($this->mk));
        $this->assertSame('QUO-000118', $this->nextQuoteNumber($this->mk));
    }
}
