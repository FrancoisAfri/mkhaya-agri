<?php

namespace Tests\Feature\SupplierManagement;

use App\Core\User\Models\User;
use App\Modules\SupplierManagement\Models\Supplier;
use App\Modules\SupplierManagement\Models\SupplierPerformanceTemplate;
use App\Modules\SupplierManagement\Models\SupplierQuestionLibrary;
use App\Modules\SupplierManagement\Services\SupplierAppraisalService;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The supplier directory, and whether it says anything.
 *
 * It was a list and nothing else — a page you could read for a minute without learning whether
 * anything on it needed doing, and the page somebody uses to decide who to place work with. It
 * carried no indication of how the last work went, no indication of risk, and no way to find the
 * suppliers nobody has assessed.
 *
 * The distinction these hold throughout is between absent and bad. An unrated supplier is not a
 * low-risk one; a supplier never appraised is not one that scored zero; and an active supplier
 * nobody has verified is not the same as a pending one, because the first is money already going out
 * against evidence nobody collected.
 */
class SupplierDirectoryTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $companyId = DB::table('companies')->min('id');

        if ($companyId === null) {
            $this->markTestSkipped('No company in the test database.');
        }

        app(CurrentCompany::class)->set((int) $companyId);

        if (! DB::table('security_modules')->where('code_name', 'supplier_management')->where('active', 1)->exists()) {
            $categoryId = DB::table('security_categories')->value('id')
                ?? DB::table('security_categories')->insertGetId(['name' => 'Test', 'created_at' => now(), 'updated_at' => now()]);

            DB::table('security_modules')->updateOrInsert(
                ['code_name' => 'supplier_management'],
                ['uuid' => (string) Str::uuid(), 'name' => 'Supplier Management', 'path' => 'suppliers',
                    'category_id' => $categoryId, 'active' => 1, 'created_at' => now(), 'updated_at' => now()],
            );
        }

        Cache::flush();

        // A clean book, so the counts assert on what this test created rather than on the fixture
        // state of whichever database it runs against.
        Supplier::query()->forceDelete();
    }

    private function user(): User
    {
        return User::forceCreate([
            'uuid' => (string) Str::uuid(), 'email' => 'dir+'.Str::random(8).'@example.test',
            'password' => bcrypt('secret'), 'status' => 1, 'is_platform_admin' => true,
        ]);
    }

    private function supplier(array $attributes = [], array $forced = []): Supplier
    {
        $supplier = Supplier::create(array_merge([
            'uuid' => (string) Str::uuid(), 'company_name' => 'Listed '.Str::random(5),
            'payment_terms' => '30 days', 'fraud_corruption_policy_agreement' => true,
            'public_liability_insurance' => true, 'health_safety_compliance' => true,
            'is_active' => 1, 'is_preferred' => false,
        ], $attributes));

        if ($forced !== []) {
            $supplier->forceFill($forced)->saveQuietly();
        }

        return $supplier->refresh();
    }

    private function page(array $query = [])
    {
        return $this->actingAs($this->user())->get(route('suppliers.index', $query));
    }

    // ── What the page now says ──────────────────────────────────────────────

    public function test_the_directory_counts_what_is_waiting_on_somebody(): void
    {
        $this->supplier(['is_active' => 1]);
        $this->supplier(['is_active' => 0]);
        $this->supplier(['is_active' => 3]);   // pending KYC
        $this->supplier(['is_active' => 2]);   // refused

        $this->page()->assertInertia(fn ($p) => $p
            ->where('stats.total', 4)
            ->where('stats.active', 1)
            // Pending and pending-KYC are one queue to whoever has to clear them.
            ->where('stats.pending', 2)
            ->where('stats.refused', 1));
    }

    public function test_an_active_supplier_nobody_verified_is_counted_on_its_own(): void
    {
        /*
         * A pending supplier with no verification is expected. An active one is money going out
         * against evidence nobody collected, and it was invisible on this page.
         */
        $this->supplier(['is_active' => 1]);                                            // never checked
        $this->supplier(['is_active' => 1], ['last_monitored_at' => Carbon::now()]);    // checked
        $this->supplier(['is_active' => 0]);                                            // pending, not counted

        $this->page()->assertInertia(fn ($p) => $p->where('stats.unverified_active', 1));
    }

    public function test_unrated_suppliers_are_their_own_band_not_folded_into_low(): void
    {
        /*
         * "We have not assessed them" and "we assessed them and they are fine" are the same empty
         * space on most charts and opposite facts. The first is the one that should make somebody act.
         */
        $this->supplier([], ['risk_band' => 'high']);
        $this->supplier();   // no band

        $this->page()->assertInertia(fn ($p) => $p->where('riskMix', fn ($mix) => collect($mix)
            ->firstWhere('band', 'unrated')['count'] === 1
            && collect($mix)->firstWhere('band', 'high')['count'] === 1));
    }

    public function test_a_month_with_nobody_onboarded_keeps_its_place(): void
    {
        /*
         * Unlike the appraisal trend, a zero here is real rather than a gap in the record — leaving
         * it out would compress the axis and flatter a quiet year.
         */
        $this->supplier();

        $this->page()->assertInertia(fn ($p) => $p->where('growth', fn ($g) => count($g) === 12));
    }

    public function test_the_list_carries_the_last_score_so_past_work_is_visible(): void
    {
        // This is the page where somebody decides who to place work with, and it said nothing about
        // how the last work went.
        $supplier = $this->supplier();
        $template = SupplierPerformanceTemplate::create(['name' => 'T'.Str::random(4), 'status' => 'active']);
        $question = SupplierQuestionLibrary::create([
            'template_id' => $template->id, 'question' => 'Delivery', 'weight_percentage' => 100,
        ]);

        app(SupplierAppraisalService::class)->store(
            $supplier->id, null, $template->id, null,
            [['question_library_id' => $question->id, 'rating' => 4]],
        );

        $this->page()->assertInertia(fn ($p) => $p->where(
            'suppliers.data',
            fn ($rows) => abs((float) collect($rows)->firstWhere('uuid', $supplier->uuid)['last_appraisal_score'] - 80) < 0.01,
        ));
    }

    public function test_a_supplier_never_appraised_reports_nothing_rather_than_zero(): void
    {
        // Zero is the worst possible score. Absent is not a score at all.
        $supplier = $this->supplier();

        $this->page()->assertInertia(fn ($p) => $p->where(
            'suppliers.data',
            fn ($rows) => collect($rows)->firstWhere('uuid', $supplier->uuid)['last_appraisal_score'] === null,
        ));
    }

    // ── Finding things ──────────────────────────────────────────────────────

    public function test_the_unassessed_can_be_filtered_for(): void
    {
        /*
         * `unrated` has to be a filter in its own right. A band filter that only matches bands can
         * never surface the suppliers nobody has looked at, which are the ones worth finding.
         */
        $rated = $this->supplier([], ['risk_band' => 'low']);
        $unrated = $this->supplier();

        $this->page(['risk_band' => 'unrated'])->assertInertia(fn ($p) => $p->where(
            'suppliers.data',
            fn ($rows) => collect($rows)->pluck('uuid')->all() === [$unrated->uuid],
        ));
    }

    public function test_filtering_by_a_band_returns_only_that_band(): void
    {
        $high = $this->supplier([], ['risk_band' => 'high']);
        $this->supplier([], ['risk_band' => 'low']);

        $this->page(['risk_band' => 'high'])->assertInertia(fn ($p) => $p->where(
            'suppliers.data',
            fn ($rows) => collect($rows)->pluck('uuid')->all() === [$high->uuid],
        ));
    }

    public function test_every_row_carries_the_identifier_its_controls_need(): void
    {
        // Activate, refuse and delete all address a supplier, and a row without a uuid cannot.
        $supplier = $this->supplier();

        $this->page()->assertInertia(fn ($p) => $p->where(
            'suppliers.data',
            fn ($rows) => collect($rows)->firstWhere('uuid', $supplier->uuid) !== null,
        ));
    }
}
