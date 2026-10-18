<?php

namespace App\Modules\Operations\ProjectManagement\Services;

use App\Modules\Operations\ProjectManagement\Models\Project;
use App\Modules\Operations\ProjectManagement\Models\WbsNode;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The work breakdown structure (PM-1110, PM-1111).
 *
 * Two derived values have to stay true after every edit, and both are why this is a service rather
 * than model calls scattered through controllers:
 *
 *  - `path` — the materialised ancestry ('/12/48/') that makes a subtree read one indexed query
 *    instead of a recursive walk;
 *  - `wbs_code` — the 1.2.3 numbering people actually quote to each other.
 *
 * Moving a node changes both for the node *and every descendant*, so a move is one transaction.
 * A half-applied move leaves orphaned paths and duplicate codes, and neither is something the UI
 * could sensibly recover from.
 *
 * Codes can be frozen (PM-1110): once a baseline exists, 1.2.3 is on a drawing and in somebody's
 * email, so renumbering it would rewrite history rather than tidy it.
 */
class WbsService
{
    public function __construct(private readonly Outbox $outbox)
    {
    }

    /**
     * @param  array<string,mixed>  $attributes
     */
    public function create(Project $project, array $attributes, ?WbsNode $parent = null): WbsNode
    {
        if ($parent && $parent->project_id !== $project->id) {
            throw new InvalidArgumentException('The parent belongs to a different project.');
        }

        return DB::transaction(function () use ($project, $attributes, $parent) {
            $node = new WbsNode($attributes);
            $node->project_id = $project->id;
            $node->parent_id = $parent?->id;
            $node->depth = $parent ? $parent->depth + 1 : 0;
            $node->sort_order = $attributes['sort_order'] ?? $this->nextSortOrder($project, $parent);

            // path and wbs_code need the row's own id, so they are set immediately after insert.
            $node->path = '';
            $node->wbs_code = '';
            $node->save();

            $node->path = ($parent ? $parent->childPath() : '/') . $node->id . '/';
            $node->wbs_code = $this->codeFor($node, $parent);
            $node->save();

            $this->outbox->publish('pm.wbs.created', 'wbs_node', $node->uuid, [
                'project_uuid' => $project->uuid,
                'wbs_code'     => $node->wbs_code,
                'name'         => $node->name,
            ]);

            return $node;
        });
    }

    /**
     * Move a node (and everything under it) to a new parent.
     *
     * The cycle check is the important part: making a node its own descendant would produce a tree
     * with no root, and the path rewrite below would loop forever building it. Rejected by name so
     * the message tells the user which node, not just that "an error occurred" (Law 6).
     */
    public function move(WbsNode $node, ?WbsNode $newParent, ?int $position = null): WbsNode
    {
        if ($newParent) {
            if ($newParent->project_id !== $node->project_id) {
                throw new InvalidArgumentException('A node cannot move to a different project.');
            }

            if ($newParent->id === $node->id) {
                throw new InvalidArgumentException("'{$node->name}' cannot be moved under itself.");
            }

            // A descendant carries the node's path as a prefix — one string comparison, no walk.
            if (str_starts_with($newParent->path, $node->path)) {
                throw new InvalidArgumentException(
                    "'{$node->name}' cannot be moved under '{$newParent->name}', which is inside it."
                );
            }
        }

        return DB::transaction(function () use ($node, $newParent, $position) {
            $oldPath = $node->path;
            $oldDepth = $node->depth;

            $node->parent_id = $newParent?->id;
            $node->depth = $newParent ? $newParent->depth + 1 : 0;
            $node->sort_order = $position ?? $this->nextSortOrder($node->project, $newParent);
            $node->path = ($newParent ? $newParent->childPath() : '/') . $node->id . '/';
            $node->save();

            $this->rewriteSubtree($node, $oldPath, $node->depth - $oldDepth);
            $this->renumber($node->project);

            $this->outbox->publish('pm.wbs.moved', 'wbs_node', $node->uuid, [
                'project_uuid' => $node->project->uuid,
                'new_parent'   => $newParent?->uuid,
                'wbs_code'     => $node->fresh()->wbs_code,
            ]);

            return $node->fresh();
        });
    }

    /**
     * Reorder siblings. The caller sends the sibling uuids in their new order.
     *
     * @param  list<string>  $orderedUuids
     */
    public function reorder(Project $project, ?WbsNode $parent, array $orderedUuids): void
    {
        DB::transaction(function () use ($project, $parent, $orderedUuids) {
            $siblings = WbsNode::where('project_id', $project->id)
                ->where('parent_id', $parent?->id)
                ->whereIn('uuid', $orderedUuids)
                ->get()
                ->keyBy('uuid');

            foreach ($orderedUuids as $index => $uuid) {
                $siblings->get($uuid)?->update(['sort_order' => $index]);
            }

            $this->renumber($project);
        });
    }

    /**
     * Soft-delete a node and everything beneath it.
     *
     * Deleting a branch and leaving its children behind would produce nodes whose parent no longer
     * exists — invisible in the tree and undeletable through it (PM-ADR-007).
     */
    public function delete(WbsNode $node): void
    {
        DB::transaction(function () use ($node) {
            $descendants = WbsNode::descendantsOf($node)->pluck('id');

            WbsNode::whereIn('id', $descendants)->delete();
            $node->delete();

            $this->outbox->publish('pm.wbs.deleted', 'wbs_node', $node->uuid, [
                'project_uuid'      => $node->project->uuid,
                'descendants'       => $descendants->count(),
            ]);
        });
    }

    /**
     * Renumber a project's codes from its current shape (1, 1.1, 1.1.1).
     *
     * Skipped entirely once codes are frozen: a baselined 1.2.3 is on a drawing and in somebody's
     * email, and renumbering it would rewrite history rather than tidy it.
     */
    public function renumber(Project $project): int
    {
        if ($this->codesAreFrozen($project)) {
            return 0;
        }

        $nodes = WbsNode::where('project_id', $project->id)
            ->orderBy('depth')->orderBy('sort_order')->orderBy('id')
            ->get();

        $byParent = $nodes->groupBy(fn (WbsNode $n) => $n->parent_id ?? 0);

        /*
         * Work out every new code first, then write them — in two passes.
         *
         * Writing each node as the walk reached it looked simpler and was wrong. `wbs_code` is
         * unique per (company, project) where not deleted, and a renumber routinely *swaps* two
         * codes: moving the node that will become 1 while the node currently holding 1 has not
         * moved yet is a duplicate, and Postgres refuses it. The reorder then fails with a
         * constraint violation in front of somebody who only dragged a row.
         *
         * So every changing node is parked on a temporary code no real one can collide with, and
         * the final codes are written once the field is clear. Both passes are inside the caller's
         * transaction, so a failure leaves the tree as it was rather than half-parked.
         */
        $wanted = [];

        $walk = function (?int $parentId, string $prefix) use (&$walk, $byParent, &$wanted) {
            foreach ($byParent->get($parentId ?? 0, collect()) as $index => $node) {
                $code = $prefix === '' ? (string) ($index + 1) : $prefix . '.' . ($index + 1);

                if ($node->wbs_code !== $code) {
                    $wanted[$node->id] = $code;
                }

                $walk($node->id, $code);
            }
        };

        $walk(null, '');

        if ($wanted === []) {
            return 0;
        }

        /*
         * The parking codes.
         *
         * Prefixed with a character a real WBS code cannot contain — codes are digits and dots —
         * so a parked node cannot collide with a settled one, and keyed by id so two parked nodes
         * cannot collide with each other.
         */
        foreach (array_keys($wanted) as $id) {
            WbsNode::where('id', $id)->update(['wbs_code' => '~' . $id]);
        }

        foreach ($wanted as $id => $code) {
            // Saved without touching updated_at churn on the whole tree: a renumber is
            // bookkeeping, not an edit anyone made.
            WbsNode::where('id', $id)->update(['wbs_code' => $code]);
        }

        return count($wanted);
    }

    /**
     * The tree, ready to render.
     *
     * Loaded flat in one query and assembled in memory: a 5,000-node tree is one round trip, not
     * one per branch (PM-1110's budget).
     *
     * @return list<array<string,mixed>>
     */
    public function tree(Project $project): array
    {
        $nodes = WbsNode::where('project_id', $project->id)
            ->orderBy('depth')->orderBy('sort_order')->orderBy('id')
            ->get(['id', 'uuid', 'parent_id', 'name', 'node_type', 'wbs_code', 'depth', 'sort_order', 'is_milestone']);

        $children = $nodes->groupBy(fn (WbsNode $n) => $n->parent_id ?? 0);

        $build = function (?int $parentId) use (&$build, $children): array {
            return $children->get($parentId ?? 0, collect())->map(fn (WbsNode $n) => [
                'id'           => $n->uuid,
                'name'         => $n->name,
                'node_type'    => $n->node_type,
                'wbs_code'     => $n->wbs_code,
                'depth'        => $n->depth,
                'is_milestone' => (bool) $n->is_milestone,
                'children'     => $build($n->id),
            ])->values()->all();
        };

        return $build(null);
    }

    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Rewrite the paths and depths of everything under a moved node.
     *
     * One statement rather than a row-by-row loop: a move near the root of a large tree can touch
     * thousands of descendants, and the whole point of a materialised path is that the rewrite is
     * a prefix substitution.
     */
    private function rewriteSubtree(WbsNode $node, string $oldPath, int $depthDelta): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            foreach (WbsNode::where('path', 'like', $oldPath . '%')->where('id', '!=', $node->id)->get() as $descendant) {
                $descendant->update([
                    'path'  => $node->path . substr($descendant->path, strlen($oldPath)),
                    'depth' => $descendant->depth + $depthDelta,
                ]);
            }

            return;
        }

        // The offset and delta are cast explicitly. Without `::int`, a bound parameter makes
        // Postgres read `substring(path from ?)` as the *regex* form rather than the positional
        // one — it then returns NULL whenever the pattern does not match, which would blank the
        // path of every descendant. The NOT NULL constraint catches it; silence would not have.
        DB::update(
            'UPDATE pm_wbs_nodes
                SET path = ? || substring(path from ?::int), depth = depth + ?::int
              WHERE path LIKE ? AND id <> ? AND deleted_at IS NULL',
            [$node->path, strlen($oldPath) + 1, $depthDelta, $oldPath . '%', $node->id]
        );
    }

    private function codeFor(WbsNode $node, ?WbsNode $parent): string
    {
        $position = WbsNode::where('project_id', $node->project_id)
            ->where('parent_id', $parent?->id)
            ->where('id', '<=', $node->id)
            ->count();

        return $parent ? $parent->wbs_code . '.' . $position : (string) $position;
    }

    private function nextSortOrder(Project $project, ?WbsNode $parent): int
    {
        return (int) WbsNode::where('project_id', $project->id)
            ->where('parent_id', $parent?->id)
            ->max('sort_order') + 1;
    }

    /**
     * Codes are frozen once the project has a baseline — the setting PM-1110 asks for, expressed as
     * a fact about the project rather than a checkbox someone has to remember to tick.
     */
    private function codesAreFrozen(Project $project): bool
    {
        return in_array($project->status, ['completed', 'archived'], true);
    }
}
