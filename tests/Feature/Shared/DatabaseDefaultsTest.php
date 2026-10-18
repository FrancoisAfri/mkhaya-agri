<?php

namespace Tests\Feature\Shared;

use App\Modules\CRM\Models\CrmDeal;
use App\Modules\CRM\Models\CrmPipeline;
use App\Modules\CRM\Models\CrmProduct;
use App\Modules\CRM\Models\CrmStage;
use App\Modules\HR\LMS\Models\LmsCertification;
use App\Shared\Traits\KeepsDatabaseDefaults;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A blank optional field means the same as an omitted one.
 *
 * `NOT NULL DEFAULT …` protects an insert that leaves the column out. It does nothing for one that
 * names the column and passes null — which is what an optional form field does every time somebody
 * leaves it blank: empty input, `ConvertEmptyStringsToNull`, a `nullable` rule, and a column that
 * refuses it. Reported from the live site as a 500 on adding a deal before its value was known.
 *
 * The rule applied is not invented: it is the default the schema has already declared. What is
 * tested hardest below is what the trait must *not* do, because the failure mode there is silent
 * and much worse than the crash it replaces.
 */
class DatabaseDefaultsTest extends TestCase
{
    use DatabaseTransactions;

    private const NOT_A_LITERAL = "\0__no_literal__";

    private function defaultsOf(object $model): array
    {
        $method = new \ReflectionMethod($model, 'resolveNullDefaults');
        $method->setAccessible(true);

        return $method->invoke($model);
    }

    private function literal(string $default): mixed
    {
        $model = new CrmProduct;
        $method = new \ReflectionMethod($model, 'literalDefault');
        $method->setAccessible(true);

        return $method->invoke($model, $default);
    }

    // ── What it must never do ────────────────────────────────────────────────

    public function test_the_tenancy_session_default_is_never_inlined(): void
    {
        /*
         * The one that would have been a catastrophe. 158 NOT NULL `company_id` columns default to
         * the row-level-security session variable:
         *
         *     (NULLIF(current_setting('app.company_id', true), ''))::bigint
         *
         * Inlining that is not a crash on every column it reaches — it is a tenancy failure, which
         * is the quietest bug this system can have.
         */
        $this->assertSame(
            self::NOT_A_LITERAL,
            $this->literal("(NULLIF(current_setting('app.company_id'::text, true), ''::text))::bigint"),
        );
    }

    public function test_defaults_only_the_database_can_evaluate_are_left_alone(): void
    {
        // A sequence, a clock and a uuid generator each produce a different value per row. Freezing
        // any of them into a PHP constant would hand every row the same one.
        foreach (['nextval(\'things_id_seq\')', 'CURRENT_TIMESTAMP', 'now()', 'gen_random_uuid()'] as $expression) {
            $this->assertSame(self::NOT_A_LITERAL, $this->literal($expression), $expression);
        }
    }

    public function test_a_column_with_no_default_is_left_alone(): void
    {
        /*
         * Nothing to fall back on, so filling it would put invented data in a column somebody
         * deliberately made mandatory. That case wants a validation rule or a nullable column.
         */
        $this->assertSame(self::NOT_A_LITERAL, $this->literal(''));
        $this->assertArrayNotHasKey('title', $this->defaultsOf(new CrmDeal));
    }

    public function test_a_nullable_column_is_left_alone(): void
    {
        // Null means "not known" there, and it is a legitimate thing to store.
        $defaults = $this->defaultsOf(new CrmDeal);

        $this->assertArrayNotHasKey('expected_close_date', $defaults);
        $this->assertArrayNotHasKey('source', $defaults);
    }

    // ── What it does ─────────────────────────────────────────────────────────

    public function test_a_blank_field_gets_the_default_the_schema_declares(): void
    {
        $pipeline = CrmPipeline::first() ?? CrmPipeline::create([
            'uuid' => (string) Str::uuid(), 'name' => 'Default', 'is_default' => true,
        ]);
        $stage = CrmStage::where('pipeline_id', $pipeline->id)->first() ?? CrmStage::create([
            'uuid' => (string) Str::uuid(), 'pipeline_id' => $pipeline->id,
            'name' => 'New', 'probability' => 10, 'sort_order' => 1,
        ]);

        $deal = CrmDeal::create([
            'uuid' => (string) Str::uuid(), 'title' => 'Value unknown so far',
            'pipeline_id' => $pipeline->id, 'stage_id' => $stage->id,
            'amount' => null, 'currency' => null,
        ]);

        $this->assertSame('0.00', (string) $deal->fresh()->amount);
        $this->assertSame('ZAR', $deal->fresh()->currency);
    }

    public function test_a_value_somebody_supplied_is_never_touched(): void
    {
        // Including a deliberate zero, which is a real answer rather than an absent one.
        $product = CrmProduct::create([
            'uuid' => (string) Str::uuid(), 'name' => 'Priced in dollars',
            'type' => 'service', 'price' => 0, 'currency' => 'USD',
        ]);

        $this->assertSame('USD', $product->fresh()->currency);
        $this->assertSame('0.00', (string) $product->fresh()->price);
    }

    public function test_a_quoted_number_comes_back_as_a_number(): void
    {
        // Postgres prints `'30'::smallint`. The string would work by coercion and read as a bug to
        // anybody comparing strictly.
        $this->assertSame(30, $this->literal("'30'::smallint"));
        $this->assertSame(1.5, $this->literal("'1.5'::numeric"));
        $this->assertTrue($this->literal('true'));
        $this->assertFalse($this->literal('false'));
    }

    public function test_a_json_default_arrives_decoded(): void
    {
        /*
         * A json column's default is a document and the model casts the attribute to an array.
         * Passing the raw string through would store it double-encoded — valid JSON containing a
         * JSON string, which reads back as nonsense.
         */
        $this->assertSame([90, 60, 30, 14, 7, 1], $this->defaultsOf(new LmsCertification)['reminder_days']);
    }

    public function test_a_model_may_state_its_own_defaults(): void
    {
        // The escape hatch, for a case where the schema alone does not make the intent clear.
        $model = new class extends \Illuminate\Database\Eloquent\Model {
            use KeepsDatabaseDefaults;

            protected $table = 'crm_deals';

            protected array $nullDefaults = ['currency' => 'GBP'];
        };

        $this->assertSame(['currency' => 'GBP'], $this->defaultsOf($model));
    }

    public function test_a_missing_table_does_not_stop_a_model_saving(): void
    {
        /*
         * This runs during migrations and on a fresh install, where the table may not exist yet. A
         * model refusing to save because it could not introspect itself would be a worse bug than
         * the one being fixed.
         */
        $model = new class extends \Illuminate\Database\Eloquent\Model {
            use KeepsDatabaseDefaults;

            protected $table = 'a_table_that_does_not_exist';
        };

        $this->assertSame([], $this->defaultsOf($model));
    }
}
