<?php

namespace Tests\Feature\ProjectManagement;

use App\Modules\Operations\ProjectManagement\Models\Project;
use App\Modules\Operations\ProjectManagement\Models\WbsNode;
use App\Modules\Operations\ProjectManagement\Services\WbsService;
use InvalidArgumentException;

/**
 * The work breakdown structure (PM-1110, PM-1111): unlimited depth, subtree moves in one
 * transaction, and auto-numbering that survives reordering.
 */
class WbsTreeTest extends ProjectManagementTestCase
{
    private WbsService $wbs;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->wbs = app(WbsService::class);
        $this->project = $this->makeProject();
    }

    // ── Structure ────────────────────────────────────────────────────────────

    public function test_nodes_nest_to_any_depth_with_a_readable_code(): void
    {
        $phase = $this->wbs->create($this->project, ['name' => 'Substructure', 'node_type' => 'phase']);
        $deliverable = $this->wbs->create($this->project, ['name' => 'Piling'], $phase);
        $activity = $this->wbs->create($this->project, ['name' => 'Pile cap 1'], $deliverable);

        $this->assertSame('1', $phase->wbs_code);
        $this->assertSame('1.1', $deliverable->wbs_code);
        $this->assertSame('1.1.1', $activity->wbs_code);
        $this->assertSame(2, $activity->depth);
    }

    public function test_the_path_makes_a_subtree_one_query(): void
    {
        $phase = $this->wbs->create($this->project, ['name' => 'Superstructure']);
        $child = $this->wbs->create($this->project, ['name' => 'Columns'], $phase);
        $grandchild = $this->wbs->create($this->project, ['name' => 'Column C1'], $child);

        $descendants = WbsNode::descendantsOf($phase)->pluck('name')->all();

        $this->assertEqualsCanonicalizing(['Columns', 'Column C1'], $descendants);
        $this->assertStringStartsWith($phase->path, $grandchild->path);
    }

    // ── Moving ───────────────────────────────────────────────────────────────

    public function test_moving_a_node_rewrites_every_descendant_in_one_transaction(): void
    {
        $a = $this->wbs->create($this->project, ['name' => 'Phase A']);
        $b = $this->wbs->create($this->project, ['name' => 'Phase B']);
        $child = $this->wbs->create($this->project, ['name' => 'Package'], $a);
        $grandchild = $this->wbs->create($this->project, ['name' => 'Task group'], $child);

        $this->wbs->move($child, $b);

        $child = $child->fresh();
        $grandchild = $grandchild->fresh();

        // The whole branch follows: a half-applied move would leave paths pointing at the old
        // parent, which the tree could never render.
        $this->assertSame($b->id, $child->parent_id);
        $this->assertStringStartsWith($b->path, $child->path);
        $this->assertStringStartsWith($child->path, $grandchild->path);
        $this->assertSame(2, $grandchild->depth);
    }

    public function test_a_node_cannot_be_moved_inside_itself(): void
    {
        $parent = $this->wbs->create($this->project, ['name' => 'Phase A']);
        $child = $this->wbs->create($this->project, ['name' => 'Package'], $parent);

        $this->expectException(InvalidArgumentException::class);
        // Named, not a constraint code: the user needs to know which node (Law 6).
        $this->expectExceptionMessage("'Phase A' cannot be moved under 'Package', which is inside it.");

        $this->wbs->move($parent, $child);
    }

    public function test_a_node_cannot_be_moved_under_itself(): void
    {
        $node = $this->wbs->create($this->project, ['name' => 'Phase A']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot be moved under itself');

        $this->wbs->move($node, $node);
    }

    public function test_a_node_cannot_move_to_another_project(): void
    {
        $other = $this->makeProject(['code' => 'PRJ-OTHER']);
        $node = $this->wbs->create($this->project, ['name' => 'Phase A']);
        $elsewhere = $this->wbs->create($other, ['name' => 'Their phase']);

        $this->expectException(InvalidArgumentException::class);

        $this->wbs->move($node, $elsewhere);
    }

    public function test_moving_renumbers_the_tree(): void
    {
        $a = $this->wbs->create($this->project, ['name' => 'Phase A']);
        $b = $this->wbs->create($this->project, ['name' => 'Phase B']);
        $package = $this->wbs->create($this->project, ['name' => 'Package'], $a);

        $this->assertSame('1.1', $package->wbs_code);

        $this->wbs->move($package, $b);

        $this->assertSame('2.1', $package->fresh()->wbs_code);
    }

    // ── Ordering ─────────────────────────────────────────────────────────────

    public function test_reordering_siblings_renumbers_them(): void
    {
        $first = $this->wbs->create($this->project, ['name' => 'Enabling works']);
        $second = $this->wbs->create($this->project, ['name' => 'Substructure']);
        $third = $this->wbs->create($this->project, ['name' => 'Superstructure']);

        $this->wbs->reorder($this->project, null, [$third->uuid, $first->uuid, $second->uuid]);

        $this->assertSame('1', $third->fresh()->wbs_code);
        $this->assertSame('2', $first->fresh()->wbs_code);
        $this->assertSame('3', $second->fresh()->wbs_code);
    }

    public function test_codes_freeze_once_the_project_is_closed_out(): void
    {
        $first = $this->wbs->create($this->project, ['name' => 'Enabling works']);
        $second = $this->wbs->create($this->project, ['name' => 'Substructure']);

        $this->project->update(['status' => 'completed']);

        $this->wbs->reorder($this->project, null, [$second->uuid, $first->uuid]);

        // 1.2.3 is on a drawing and in somebody's email by now; renumbering would rewrite history.
        $this->assertSame('1', $first->fresh()->wbs_code);
        $this->assertSame('2', $second->fresh()->wbs_code);
    }

    // ── Deleting ─────────────────────────────────────────────────────────────

    public function test_deleting_a_branch_takes_its_children_with_it(): void
    {
        $phase = $this->wbs->create($this->project, ['name' => 'Phase A']);
        $child = $this->wbs->create($this->project, ['name' => 'Package'], $phase);
        $grandchild = $this->wbs->create($this->project, ['name' => 'Group'], $child);

        $this->wbs->delete($phase);

        // Orphans would be invisible in the tree and undeletable through it.
        $this->assertSoftDeleted('pm_wbs_nodes', ['id' => $phase->id]);
        $this->assertSoftDeleted('pm_wbs_nodes', ['id' => $child->id]);
        $this->assertSoftDeleted('pm_wbs_nodes', ['id' => $grandchild->id]);
    }

    // ── Reading ──────────────────────────────────────────────────────────────

    public function test_the_tree_is_assembled_from_one_query(): void
    {
        $phase = $this->wbs->create($this->project, ['name' => 'Phase A']);
        $this->wbs->create($this->project, ['name' => 'Package 1'], $phase);
        $this->wbs->create($this->project, ['name' => 'Package 2'], $phase);
        $this->wbs->create($this->project, ['name' => 'Phase B']);

        \DB::enableQueryLog();
        $tree = $this->wbs->tree($this->project);
        $queries = count(\DB::getQueryLog());
        \DB::disableQueryLog();

        $this->assertCount(2, $tree);
        $this->assertCount(2, $tree[0]['children']);
        // One round trip regardless of shape — a per-branch query would make a 5,000-node tree
        // thousands of them.
        $this->assertSame(1, $queries);
    }

    public function test_a_five_hundred_node_tree_builds_and_reads_quickly(): void
    {
        $phases = [];
        for ($i = 0; $i < 10; $i++) {
            $phases[] = $this->wbs->create($this->project, ['name' => "Phase {$i}"]);
        }
        foreach ($phases as $phase) {
            for ($j = 0; $j < 49; $j++) {
                $this->wbs->create($this->project, ['name' => "Package {$j}"], $phase);
            }
        }

        $started = microtime(true);
        $tree = $this->wbs->tree($this->project);
        $elapsed = microtime(true) - $started;

        $this->assertCount(10, $tree);
        $this->assertCount(49, $tree[0]['children']);
        $this->assertLessThan(1.5, $elapsed, sprintf('Reading the tree took %.2fs.', $elapsed));
    }
}
