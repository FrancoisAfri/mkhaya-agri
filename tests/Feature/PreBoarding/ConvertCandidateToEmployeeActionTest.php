<?php

namespace Tests\Feature\PreBoarding;

use App\Modules\HR\Contracts\Models\Contract;
use App\Modules\HR\PreBoarding\Actions\ConvertCandidateToEmployeeAction;
use App\Modules\HR\PreBoarding\Enums\HrPersonStatus;
use App\Modules\HR\PreBoarding\Enums\PreBoardingStatus;
use App\Modules\HR\PreBoarding\Models\HrPerson;
use App\Modules\HR\PreBoarding\Models\PreBoardingPlan;
use App\Modules\HR\PreBoarding\Models\PreBoardingTask;

class ConvertCandidateToEmployeeActionTest extends PreBoardingTestCase
{
    private ConvertCandidateToEmployeeAction $action;

    protected function setUp(): void
    {
        parent::setUp();
        $this->action = app(ConvertCandidateToEmployeeAction::class);
    }

    public function test_creates_hr_person_from_contract(): void
    {
        $contract = Contract::factory()->signed()->create();

        $person = $this->action->execute($contract, $this->hrManager->id);

        $this->assertInstanceOf(HrPerson::class, $person);
        $this->assertEquals($contract->candidate_id, $person->candidate_id);
        $this->assertEquals($contract->id, $person->contract_id);
    }

    public function test_hr_person_defaults_to_pre_boarding_status(): void
    {
        $contract = Contract::factory()->signed()->create();

        $person = $this->action->execute($contract, $this->hrManager->id);

        $this->assertEquals(HrPersonStatus::PreBoarding, $person->fresh()->status);
    }

    public function test_creates_pre_boarding_plan_for_hr_person(): void
    {
        $contract = Contract::factory()->signed()->create();

        $person = $this->action->execute($contract, $this->hrManager->id);

        $this->assertDatabaseHas('pre_boarding_plans', [
            'hr_person_id' => $person->id,
            'status'       => PreBoardingStatus::Pending->value,
        ]);
    }

    public function test_seeds_default_tasks_on_plan(): void
    {
        $contract = Contract::factory()->signed()->create();

        $person = $this->action->execute($contract, $this->hrManager->id);
        $plan   = $person->preBoardingPlans()->first();

        $this->assertGreaterThanOrEqual(1, $plan->tasks()->count());
    }

    public function test_plan_has_7_day_due_date(): void
    {
        $contract = Contract::factory()->signed()->create();

        $person = $this->action->execute($contract, $this->hrManager->id);
        $plan   = $person->preBoardingPlans()->first();

        $this->assertNotNull($plan->due_date);
        // Compared as a calendar date, not an instant: due_date is cast to `date`, so it is always
        // midnight, and asserting against now()+7d was really asserting "the suite runs at 00:00".
        $this->assertSame(
            now()->addDays(7)->toDateString(),
            $plan->due_date->toDateString()
        );
    }

    public function test_creates_uuid_on_hr_person(): void
    {
        $contract = Contract::factory()->signed()->create();

        $person = $this->action->execute($contract, $this->hrManager->id);

        $this->assertNotNull($person->uuid);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $person->uuid
        );
    }
}
