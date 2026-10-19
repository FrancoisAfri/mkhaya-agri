<?php

namespace Tests\Feature\Agri;

use App\Modules\Agri\Models\Animal;
use App\Modules\Agri\Models\AnimalHealthRecord;
use Illuminate\Support\Facades\DB;

/**
 * Spec Module H: the assistant answers from records the asker could open, refuses topics their
 * permissions do not cover, and never changes anything.
 */
class AgriAssistantTest extends AgriTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->catalogue();
        config(['agri.assistant_narration' => false]);
    }

    private function ask($user, string $q): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($user)->postJson(route('agri.assistant.ask'), ['question' => $q]);
    }

    private function treated($farm, string $tag): void
    {
        $this->asCompany($this->company, function () use ($farm, $tag) {
            $a = Animal::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'farm_id' => $farm->id, 'species_id' => $this->speciesId('CATTLE'),
                'tag_number' => $tag, 'sex' => 'female', 'status' => 'active']);
            AnimalHealthRecord::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'farm_id' => $farm->id, 'animal_id' => $a->id,
                'event_type' => 'treatment', 'treated_on' => today(), 'product_name' => 'Penicillin', 'withdrawal_until' => today()->addDays(10)]);
        });
    }

    public function test_answers_only_from_the_farms_the_asker_can_reach(): void
    {
        $north = $this->farm('NORTH');
        $south = $this->farm('SOUTH');
        $this->member($this->supervisor, $north, 'supervisor');
        $this->treated($north, 'N-COW');
        $this->treated($south, 'S-COW');

        $mine = $this->ask($this->supervisor, 'Which animals cannot be sold because of withdrawal?')->assertOk()->json();
        $this->assertSame('withdrawal', $mine['topic']);
        $this->assertSame(['N-COW'], array_column($mine['rows'], 'animal_or_group'));
        $this->assertTrue($mine['read_only']);

        $all = $this->ask($this->admin, 'Which animals cannot be sold because of withdrawal?')->json();
        $this->assertCount(2, $all['rows']);
    }

    public function test_a_topic_outside_the_askers_permissions_is_refused_without_figures(): void
    {
        $farm = $this->farm('NORTH');
        $this->member($this->worker, $farm, 'worker');
        $this->worker->givePermissionTo(\App\Modules\Agri\Support\RoleCatalog::USE_ASSISTANT);

        $r = $this->ask($this->worker, 'What is our profit margin this year?')->assertOk()->json();
        $this->assertSame('profitability', $r['topic']);
        $this->assertTrue($r['refused']);
        $this->assertSame([], $r['rows']);
        $this->assertNotContains('profitability', array_column($this->actingAs($this->worker)->get(route('agri.assistant.index'))->viewData('page')['props']['suggestions'], 'topic'));
    }

    public function test_asking_changes_nothing_and_is_audited(): void
    {
        $farm = $this->farm('NORTH');
        $this->treated($farm, 'A1');
        $tables = ['animals', 'animal_health_records', 'farm_tasks', 'crop_plans', 'fin_journal_lines', 'fin_stock_movements'];
        $before = collect($tables)->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()]);

        foreach (['What needs my attention today?', 'How are the crops doing?', 'Which inputs are running low?', 'Which machines need a service?',
            'Which tasks are overdue?', 'Which permits are expiring?', 'How many cattle do we have?', 'What is our profit?', 'Which fields yield below plan?'] as $q) {
            $this->ask($this->admin, $q)->assertOk()->assertJsonPath('read_only', true);
        }

        $this->assertEquals($before, collect($tables)->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()]));
        $this->assertSame(9, DB::table('activity_log')->where('log_name', 'agri')->where('event', 'assistant.asked')->count());
    }

    public function test_the_narration_guard_rejects_invented_numbers(): void
    {
        $guard = app(\App\Modules\Operations\Manufacturing\Services\AssistantNarrator::class);
        $facts = json_encode(['rows' => [['head' => 120]]]);
        $this->assertTrue($guard->grounded('You have 120 head.', $facts));
        $this->assertFalse($guard->grounded('You have 135 head.', $facts));
    }

    public function test_people_without_the_assistant_permission_cannot_use_it(): void
    {
        $this->ask($this->outsider, 'anything')->assertForbidden();
        $this->actingAs($this->worker)->get(route('agri.assistant.index'))->assertForbidden();
    }
}
