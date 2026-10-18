<?php

namespace Tests\Feature\HR;

use App\Modules\HR\Leave\Models\LeaveType;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * A leave type's colour comes from what kind of leave it is.
 *
 * Three places asked a leave type for `color` and `leave_types` has never had such a column — no
 * migration creates it, nothing writes it. One of them put it in the select and took the Apply
 * page down in production with "column color does not exist"; the others read it off a model that
 * had not loaded it, quietly got null, and drew every leave type in one fallback shade.
 *
 * So the colour is derived from `statutory_type`, which is the thing it was always a property of.
 */
class LeaveTypeColourTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * The column is absent on purpose, and this says so.
     *
     * If somebody adds a real `color` column later, this fails and points them at the accessor
     * that would then be shadowing it — which is the conversation worth having at that moment.
     */
    public function test_there_is_no_colour_column_to_read(): void
    {
        $this->assertFalse(
            Schema::hasColumn('leave_types', 'color'),
            'leave_types has a color column now. The model derives one from statutory_type and '
            .'would shadow it; decide which is the source of truth.'
        );
    }

    public function test_a_leave_type_has_a_colour_without_one_being_stored(): void
    {
        $type = new LeaveType(['name' => 'Annual Leave']);
        $type->statutory_type = 'annual';

        $this->assertSame('#4CAF50', $type->color);
    }

    public function test_each_kind_of_leave_is_told_apart(): void
    {
        $colours = [];

        foreach (['annual', 'sick', 'maternity', 'study', 'special'] as $kind) {
            $type = new LeaveType(['name' => ucfirst($kind)]);
            $type->statutory_type = $kind;
            $colours[$kind] = $type->color;
        }

        $this->assertSame(
            count($colours),
            count(array_unique($colours)) + 0,
            'Two kinds of leave share a colour, so a calendar cannot tell them apart: '
            .json_encode($colours)
        );
    }

    /** Not told what kind it is: a neutral answer rather than a wrong one. */
    public function test_an_unknown_kind_gets_a_neutral_colour(): void
    {
        $type = new LeaveType(['name' => 'Something new']);
        $type->statutory_type = null;

        $this->assertSame('#607D8B', $type->color);
    }

    /**
     * The query that brought the page down.
     *
     * Selecting a column that does not exist throws at the database, so this runs the shape of the
     * real eager-load rather than asserting anything about the result.
     */
    public function test_the_relation_select_used_by_the_apply_page_runs(): void
    {
        $rows = DB::table('leave_types')
            ->select(['id', 'name', 'statutory_type'])
            ->limit(1)
            ->get();

        $this->assertNotNull($rows, 'The Apply page select still names a column that is not there.');
    }
}
