<?php

namespace Tests\Feature\CRM;

use App\Modules\CRM\Models\CrmDeal;
use App\Modules\CRM\Models\CrmKpi;
use App\Modules\CRM\Models\CrmPipeline;
use App\Modules\CRM\Models\CrmProduct;
use App\Modules\CRM\Models\CrmStage;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Leaving an optional field empty saves the record.
 *
 * There is a gap between what `NOT NULL DEFAULT …` protects and what a form sends. The default
 * covers an insert that leaves the column *out*; it does nothing for one that names the column and
 * passes null. A form with an optional field does exactly that whenever somebody leaves it blank —
 * the input arrives as an empty string, `ConvertEmptyStringsToNull` makes it null, the validator's
 * `nullable` waves it through, and the column refuses it.
 *
 * Reported from the live site: adding a deal from an account page, where the value of a new
 * opportunity is very often not known yet, answered with a 500 and
 * "null value in column amount violates not-null constraint".
 *
 * The same shape was sitting in three more places. Each test below is one of them.
 */
class OptionalFieldsSurviveSaveTest extends TestCase
{
    use DatabaseTransactions;

    private function stage(): CrmStage
    {
        $pipeline = CrmPipeline::first() ?? CrmPipeline::create([
            'uuid' => (string) Str::uuid(), 'name' => 'Default', 'is_default' => true,
        ]);

        return CrmStage::where('pipeline_id', $pipeline->id)->first() ?? CrmStage::create([
            'uuid' => (string) Str::uuid(), 'pipeline_id' => $pipeline->id,
            'name' => 'New', 'probability' => 10, 'sort_order' => 1,
        ]);
    }

    public function test_a_deal_can_be_raised_before_anybody_knows_what_it_is_worth(): void
    {
        /*
         * The reported failure. Somebody adds an opportunity from the account page the moment they
         * hear about it — which is the whole point of adding it there — and the value comes later.
         */
        $stage = $this->stage();

        $deal = CrmDeal::create([
            'uuid'        => (string) Str::uuid(),
            'title'       => 'Opportunity worth finding out about',
            'pipeline_id' => $stage->pipeline_id,
            'stage_id'    => $stage->id,
            'amount'      => null,
            'currency'    => null,
        ]);

        $this->assertSame('0.00', (string) $deal->fresh()->amount, 'An unknown value is nothing so far.');
        $this->assertSame('ZAR', $deal->fresh()->currency);
    }

    public function test_a_known_value_is_never_overwritten(): void
    {
        // The guard fills a blank; it must not touch a figure somebody typed, including a
        // deliberate zero.
        $stage = $this->stage();

        $deal = CrmDeal::create([
            'uuid' => (string) Str::uuid(), 'title' => 'Priced',
            'pipeline_id' => $stage->pipeline_id, 'stage_id' => $stage->id,
            'amount' => 250000, 'currency' => 'USD',
        ]);

        $this->assertSame('250000.00', (string) $deal->fresh()->amount);
        $this->assertSame('USD', $deal->fresh()->currency);
    }

    public function test_clearing_a_deals_value_on_edit_does_not_break_it(): void
    {
        // The edit form has the same optional field, so the same null reaches the same column.
        $stage = $this->stage();

        $deal = CrmDeal::create([
            'uuid' => (string) Str::uuid(), 'title' => 'Was priced',
            'pipeline_id' => $stage->pipeline_id, 'stage_id' => $stage->id, 'amount' => 5000,
        ]);

        $deal->update(['amount' => null]);

        $this->assertSame('0.00', (string) $deal->fresh()->amount);
    }

    public function test_a_product_priced_in_the_only_currency_the_tenant_uses(): void
    {
        // `crm_products.currency` is NOT NULL DEFAULT 'ZAR' and the field is optional, so leaving it
        // alone — which everybody does on a single-currency tenant — could not be saved.
        $product = CrmProduct::create([
            'uuid' => (string) Str::uuid(), 'name' => 'Site survey', 'type' => 'service',
            'price' => 1500, 'currency' => null,
        ]);

        $this->assertSame('ZAR', $product->fresh()->currency);
    }

    public function test_a_kpi_nobody_picked_a_colour_for(): void
    {
        $kpi = CrmKpi::create([
            'uuid' => (string) Str::uuid(), 'name' => 'Open deals', 'entity' => 'deal',
            'metric' => 'count', 'format' => 'number', 'color' => null, 'sort_order' => 1,
        ]);

        $this->assertSame('primary', $kpi->fresh()->color);
    }

    public function test_the_guard_only_covers_columns_the_schema_has_an_answer_for(): void
    {
        /*
         * Worth stating, because the tempting version of this fix is a blanket "never write null".
         * A column where null genuinely means "not known" — an expected close date, a source —
         * should stay nullable in the database and untouched here, or the guard would start
         * inventing data.
         */
        $stage = $this->stage();

        $deal = CrmDeal::create([
            'uuid' => (string) Str::uuid(), 'title' => 'Undated',
            'pipeline_id' => $stage->pipeline_id, 'stage_id' => $stage->id,
            'expected_close_date' => null, 'source' => null,
        ]);

        $this->assertNull($deal->fresh()->expected_close_date);
        $this->assertNull($deal->fresh()->source);
    }
}
