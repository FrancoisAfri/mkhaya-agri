<?php

namespace Tests\Feature\ProcurementGovernance;

use App\Core\User\Models\User;
use App\Modules\Procurement\Governance\Exceptions\DomainRuleException;
use App\Modules\Procurement\Governance\Http\Controllers\SettingsController;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * §1.6: the people who hold a country's procurement instrument can enter it, and the confirmation
 * that it is right is attributable.
 *
 * This module ships no country's statute on purpose, which only works if the table is genuinely
 * editable by whoever does hold it. The tests below cover the two halves of that: the arithmetic a
 * points table has to satisfy before it can award anything meaningful, and the sign-off — which is
 * cleared whenever the numbers change, because a confirmation attaches to the figures that were
 * confirmed and not to the framework's name.
 */
class PreferenceFrameworkEditingTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;

    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();

        $companyId = DB::table('companies')->min('id');

        if ($companyId === null) {
            $this->markTestSkipped('No company in the test database.');
        }

        $this->companyId = (int) $companyId;
        Cache::flush();
        app(CurrentCompany::class)->set($this->companyId);

        $this->userId = DB::table('users')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'email' => 'expert-'.Str::random(8).'@example.test',
            'password' => bcrypt('secret'),
            'company_id' => $this->companyId,
            'status' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs(User::find($this->userId));
    }

    protected function tearDown(): void
    {
        app(CurrentCompany::class)->clear();
        parent::tearDown();
    }

    /** @param  array<int, array<string, mixed>>|null  $bands */
    private function save(array $overrides = [], ?array $bands = null): RedirectResponse
    {
        $payload = array_merge([
            'code' => 'TEST_'.Str::upper(Str::random(6)),
            'name' => 'Test framework',
            'jurisdiction' => 'BW',
            'basis' => 'POINTS',
            'max_points' => 20,
            'price_weight' => 80,
            'preference_weight' => 20,
            'is_active' => true,
            'confirm_verified' => false,
            'bands' => $bands ?? [
                ['label' => 'Citizen owned', 'match_field' => 'is_local_content_compliant', 'match_value' => 'true', 'points' => 20],
            ],
        ], $overrides);

        $request = Request::create('/procurement-governance/settings/preference-frameworks', 'POST', $payload);
        $request->setUserResolver(fn () => User::find($this->userId));

        return app(SettingsController::class)->storeFramework($request);
    }

    private function framework(string $code): ?object
    {
        return DB::table('procgov_preference_frameworks')
            ->where('company_id', $this->companyId)
            ->where('code', $code)
            ->first();
    }

    // ── Entering a country's instrument ─────────────────────────────────────────────────────────

    public function test_an_expert_can_enter_a_framework_for_their_country(): void
    {
        // The whole point: Botswana ships empty, and someone holding the PPRA scheme fills it in.
        $code = 'BW_TEST_'.Str::upper(Str::random(4));

        $this->save(['code' => $code, 'name' => 'Citizen preference', 'jurisdiction' => 'BW'], [
            ['label' => 'Citizen owned', 'match_field' => 'is_local_content_compliant', 'match_value' => 'true', 'points' => 15],
            ['label' => 'B-BBEE level 1', 'match_field' => 'bbbee_level', 'match_value' => '1', 'points' => 20],
        ]);

        $framework = $this->framework($code);

        $this->assertSame('BW', $framework->jurisdiction);
        $this->assertSame(2, DB::table('procgov_preference_bands')->where('framework_id', $framework->id)->count());
    }

    public function test_editing_replaces_the_bands_wholesale(): void
    {
        $code = 'TEST_'.Str::upper(Str::random(6));

        $this->save(['code' => $code], [
            ['label' => 'One', 'match_field' => 'bbbee_level', 'match_value' => '1', 'points' => 20],
            ['label' => 'Two', 'match_field' => 'bbbee_level', 'match_value' => '2', 'points' => 18],
        ]);

        $this->save(['code' => $code], [
            ['label' => 'Only one now', 'match_field' => 'bbbee_level', 'match_value' => '1', 'points' => 10],
        ]);

        $bands = DB::table('procgov_preference_bands')
            ->where('framework_id', $this->framework($code)->id)
            ->get();

        $this->assertCount(1, $bands);
        $this->assertSame('Only one now', $bands->first()->label);
    }

    // ── Guards ──────────────────────────────────────────────────────────────────────────────────

    public function test_a_split_that_does_not_total_one_hundred_is_refused(): void
    {
        // Anything else silently loses or invents points on every bid scored under it.
        try {
            $this->save(['price_weight' => 80, 'preference_weight' => 30]);
            $this->fail('An 80/30 split was accepted.');
        } catch (DomainRuleException $e) {
            $this->assertStringContainsString('total 100', $e->getMessage());
        }
    }

    public function test_a_band_awarding_more_than_the_maximum_is_refused(): void
    {
        try {
            $this->save(['max_points' => 20], [
                ['label' => 'Too generous', 'match_field' => 'bbbee_level', 'match_value' => '1', 'points' => 25],
            ]);
            $this->fail('A band above the framework maximum was accepted.');
        } catch (DomainRuleException $e) {
            $this->assertStringContainsString('more than', $e->getMessage());
        }
    }

    public function test_two_bands_claiming_the_same_value_are_refused(): void
    {
        // Bands are checked in order and the first match wins, so a duplicate silently never applies.
        try {
            $this->save([], [
                ['label' => 'Level 1', 'match_field' => 'bbbee_level', 'match_value' => '1', 'points' => 20],
                ['label' => 'Level 1 again', 'match_field' => 'bbbee_level', 'match_value' => '1', 'points' => 10],
            ]);
            $this->fail('Two bands claiming B-BBEE level 1 were accepted.');
        } catch (DomainRuleException $e) {
            $this->assertStringContainsString('repeats', $e->getMessage());
        }
    }

    public function test_a_margin_framework_is_not_held_to_the_points_maximum(): void
    {
        // Margin schemes award a price advantage rather than points, so the points ceiling does not
        // apply to them — this is how Eswatini and Botswana are expected to be configured.
        $code = 'TEST_'.Str::upper(Str::random(6));

        $this->save(['code' => $code, 'basis' => 'MARGIN', 'max_points' => 0], [
            ['label' => 'Local supplier', 'match_field' => 'is_local_content_compliant', 'match_value' => 'true', 'points' => 0, 'margin_pct' => 15],
        ]);

        $band = DB::table('procgov_preference_bands')
            ->where('framework_id', $this->framework($code)->id)
            ->first();

        $this->assertEqualsWithDelta(15.0, (float) $band->margin_pct, 0.001);
    }

    // ── Confirmation ────────────────────────────────────────────────────────────────────────────

    public function test_confirming_records_who_confirmed_it_and_when(): void
    {
        $code = 'TEST_'.Str::upper(Str::random(6));

        $this->save(['code' => $code, 'confirm_verified' => true]);

        $framework = $this->framework($code);

        $this->assertTrue((bool) $framework->is_verified);
        $this->assertSame($this->userId, (int) $framework->verified_by);
        $this->assertNotNull($framework->verified_at);
    }

    public function test_editing_the_figures_clears_a_previous_confirmation(): void
    {
        // A sign-off applies to the numbers that were signed off. Letting it stand while the points
        // change would let an unreviewed table inherit someone else's authority.
        $code = 'TEST_'.Str::upper(Str::random(6));

        $this->save(['code' => $code, 'confirm_verified' => true]);
        $this->assertTrue((bool) $this->framework($code)->is_verified);

        $this->save(['code' => $code, 'confirm_verified' => false], [
            ['label' => 'Changed', 'match_field' => 'bbbee_level', 'match_value' => '1', 'points' => 5],
        ]);

        $framework = $this->framework($code);

        $this->assertFalse((bool) $framework->is_verified);
        $this->assertNull($framework->verified_by);
        $this->assertNull($framework->verified_at);
    }

    // ── Retiring ────────────────────────────────────────────────────────────────────────────────

    private function retire(int $id): RedirectResponse
    {
        $request = Request::create("/procurement-governance/settings/preference-frameworks/{$id}", 'DELETE');
        $request->setUserResolver(fn () => User::find($this->userId));

        return app(SettingsController::class)->destroyFramework($request, $id);
    }

    public function test_a_framework_can_be_retired(): void
    {
        $code = 'TEST_'.Str::upper(Str::random(6));
        $this->save(['code' => $code]);

        $this->retire((int) $this->framework($code)->id);

        $this->assertNotNull($this->framework($code)->deleted_at);
    }

    public function test_a_framework_a_tender_is_scored_under_is_not_retired(): void
    {
        $code = 'TEST_'.Str::upper(Str::random(6));
        $this->save(['code' => $code]);

        DB::table('procgov_tender_reviews')->insert([
            'uuid' => (string) Str::uuid(),
            'company_id' => $this->companyId,
            'reference' => 'TR-'.Str::upper(Str::random(6)),
            'title' => 'Tender using this framework',
            'currency' => 'ZAR',
            'preference_framework_code' => $code,
            'methodology' => 'PRICE_PREFERENCE',
            'status' => 'EVALUATION',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        try {
            $this->retire((int) $this->framework($code)->id);
            $this->fail('A framework a tender is scored under was retired.');
        } catch (DomainRuleException $e) {
            $this->assertStringContainsString('tender', strtolower($e->getMessage()));
        }

        $this->assertNull($this->framework($code)->deleted_at);
    }
}
