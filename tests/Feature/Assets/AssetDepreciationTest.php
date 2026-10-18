<?php

namespace Tests\Feature\Assets;

use App\Modules\Assets\Actions\GenerateDepreciationScheduleAction;
use App\Modules\Assets\Actions\PostDepreciationEntryAction;
use App\Modules\Assets\Models\AssetDepreciationEntry;
use App\Modules\Assets\Models\AssetDepreciationSchedule;
use App\Modules\Assets\Models\Assets;

class AssetDepreciationTest extends AssetTestCase
{
    public function test_straight_line_generates_correct_monthly_amount(): void
    {
        $asset = Assets::factory()->create();

        $schedule = (new GenerateDepreciationScheduleAction())->execute(
            $asset,
            [
                'depreciation_method' => 'straight_line',
                'useful_life_years'   => 5,
                'purchase_cost'       => 120000,
                'salvage_value'       => 0,
            ],
            $this->assetManager->id,
        );

        $this->assertSame(60, (int) $schedule->total_periods);
        $this->assertDatabaseCount('asset_depreciation_entries', 60);

        $first = AssetDepreciationEntry::where('schedule_id', $schedule->id)
            ->where('period_sequence', 1)->first();

        // 120000 / 60 = 2000/month
        $this->assertEqualsWithDelta(2000, (float) $first->depreciation_amount, 0.01);
        $this->assertEqualsWithDelta(120000, (float) $first->opening_book_value, 0.01);
        $this->assertEqualsWithDelta(118000, (float) $first->closing_book_value, 0.01);

        $last = AssetDepreciationEntry::where('schedule_id', $schedule->id)
            ->where('period_sequence', 60)->first();

        $this->assertEqualsWithDelta(0, (float) $last->closing_book_value, 0.01);
    }

    public function test_reducing_balance_schedule_generates_declining_amounts(): void
    {
        $asset = Assets::factory()->create();

        $schedule = (new GenerateDepreciationScheduleAction())->execute(
            $asset,
            [
                'depreciation_method' => 'reducing_balance',
                'useful_life_years'   => 5,
                'purchase_cost'       => 100000,
                'salvage_value'       => 10000,
            ],
            $this->assetManager->id,
        );

        $entry1 = AssetDepreciationEntry::where('schedule_id', $schedule->id)
            ->where('period_sequence', 1)->first();
        $entry12 = AssetDepreciationEntry::where('schedule_id', $schedule->id)
            ->where('period_sequence', 12)->first();
        $entry48 = AssetDepreciationEntry::where('schedule_id', $schedule->id)
            ->where('period_sequence', 48)->first();

        // Reducing balance produces declining monthly depreciation
        $this->assertGreaterThan((float) $entry12->depreciation_amount, (float) $entry1->depreciation_amount);
        $this->assertGreaterThan((float) $entry48->depreciation_amount, (float) $entry12->depreciation_amount);

        $last = AssetDepreciationEntry::where('schedule_id', $schedule->id)
            ->orderByDesc('period_sequence')->first();
        $this->assertEqualsWithDelta(10000, (float) $last->closing_book_value, 0.01);
    }

    public function test_sars_wear_and_tear_uses_rate_from_input(): void
    {
        $asset = Assets::factory()->create();

        $schedule = (new GenerateDepreciationScheduleAction())->execute(
            $asset,
            [
                'depreciation_method' => 'sars_wear_and_tear',
                'useful_life_years'   => 3,
                'purchase_cost'       => 60000,
                'salvage_value'       => 0,
                'sars_wear_rate'      => 0.3333,
            ],
            $this->assetManager->id,
        );

        $this->assertEqualsWithDelta(0.3333, (float) $schedule->sars_wear_rate, 0.0001);

        $entry1 = AssetDepreciationEntry::where('schedule_id', $schedule->id)
            ->where('period_sequence', 1)->first();

        // 60000 * 0.3333 / 12 = 1666.5
        $this->assertEqualsWithDelta(60000 * 0.3333 / 12, (float) $entry1->depreciation_amount, 0.01);
    }

    public function test_regenerating_schedule_deactivates_previous_active_schedule(): void
    {
        $asset = Assets::factory()->create();
        $action = new GenerateDepreciationScheduleAction();

        $first = $action->execute($asset, [
            'depreciation_method' => 'straight_line',
            'useful_life_years'   => 5,
            'purchase_cost'       => 100000,
            'salvage_value'       => 0,
        ], $this->assetManager->id);

        $second = $action->execute($asset, [
            'depreciation_method' => 'straight_line',
            'useful_life_years'   => 3,
            'purchase_cost'       => 100000,
            'salvage_value'       => 0,
        ], $this->assetManager->id);

        $this->assertDatabaseHas('asset_depreciation_schedules', ['id' => $first->id, 'is_active' => false]);
        $this->assertDatabaseHas('asset_depreciation_schedules', ['id' => $second->id, 'is_active' => true]);
    }

    public function test_post_entry_requires_sequential_posting(): void
    {
        $asset = Assets::factory()->create();
        $schedule = (new GenerateDepreciationScheduleAction())->execute(
            $asset,
            ['depreciation_method' => 'straight_line', 'useful_life_years' => 1, 'purchase_cost' => 12000, 'salvage_value' => 0],
            $this->assetManager->id,
        );

        $second = AssetDepreciationEntry::where('schedule_id', $schedule->id)
            ->where('period_sequence', 2)->firstOrFail();

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);

        (new PostDepreciationEntryAction())->execute($second, $this->assetManager->id);
    }

    public function test_post_entry_marks_entry_as_posted_in_sequence(): void
    {
        $asset = Assets::factory()->create();
        $schedule = (new GenerateDepreciationScheduleAction())->execute(
            $asset,
            ['depreciation_method' => 'straight_line', 'useful_life_years' => 1, 'purchase_cost' => 12000, 'salvage_value' => 0],
            $this->assetManager->id,
        );

        $first = AssetDepreciationEntry::where('schedule_id', $schedule->id)
            ->where('period_sequence', 1)->firstOrFail();

        (new PostDepreciationEntryAction())->execute($first, $this->assetManager->id);

        $this->assertDatabaseHas('asset_depreciation_entries', [
            'id'        => $first->id,
            'is_posted' => true,
            'posted_by' => $this->assetManager->id,
        ]);
    }

    public function test_cannot_post_same_entry_twice(): void
    {
        $asset = Assets::factory()->create();
        $schedule = (new GenerateDepreciationScheduleAction())->execute(
            $asset,
            ['depreciation_method' => 'straight_line', 'useful_life_years' => 1, 'purchase_cost' => 12000, 'salvage_value' => 0],
            $this->assetManager->id,
        );

        $first = AssetDepreciationEntry::where('schedule_id', $schedule->id)
            ->where('period_sequence', 1)->firstOrFail();

        $action = new PostDepreciationEntryAction();
        $action->execute($first, $this->assetManager->id);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $action->execute($first->refresh(), $this->assetManager->id);
    }
}
