<?php

namespace App\Modules\Workflow\Support;

/**
 * The node palette (PRS §4.1) and what each node routes on.
 *
 * `outcomes` are the labels a node's outgoing edges may carry; a node with outcomes routes by the
 * outcome it finished with, a decision routes by its edges' conditions, a parallel split follows
 * every edge, and the rest follow their single unlabelled edge.
 */
final class NodeTypes
{
    public const TYPES = [
        'start' => ['label' => 'Start', 'icon' => 'player-play', 'color' => 'success', 'outcomes' => []],
        'end' => ['label' => 'End', 'icon' => 'flag', 'color' => 'error', 'outcomes' => []],
        'user_task' => ['label' => 'Task', 'icon' => 'user-check', 'color' => 'primary', 'outcomes' => ['completed', 'returned']],
        'approval' => ['label' => 'Approval', 'icon' => 'circle-check', 'color' => 'success', 'outcomes' => ['approved', 'rejected', 'returned']],
        'review' => ['label' => 'Review', 'icon' => 'eye-check', 'color' => 'info', 'outcomes' => ['completed', 'returned']],
        'decision' => ['label' => 'Decision', 'icon' => 'arrows-split', 'color' => 'warning', 'outcomes' => []],
        'parallel' => ['label' => 'Parallel split', 'icon' => 'arrows-split-2', 'color' => 'deep-purple', 'outcomes' => []],
        'join' => ['label' => 'Join', 'icon' => 'arrows-join-2', 'color' => 'deep-purple', 'outcomes' => []],
        'delay' => ['label' => 'Wait', 'icon' => 'hourglass', 'color' => 'blue-grey', 'outcomes' => []],
        'notification' => ['label' => 'Notify', 'icon' => 'bell', 'color' => 'orange', 'outcomes' => []],
        'action' => ['label' => 'Automated action', 'icon' => 'bolt', 'color' => 'teal', 'outcomes' => []],
        'document' => ['label' => 'Generate document', 'icon' => 'file-text', 'color' => 'indigo', 'outcomes' => []],
        'subworkflow' => ['label' => 'Sub-workflow', 'icon' => 'hierarchy-3', 'color' => 'cyan', 'outcomes' => ['completed', 'rejected']],
        'exception' => ['label' => 'Manual intervention', 'icon' => 'alert-octagon', 'color' => 'red', 'outcomes' => ['resolved', 'cancelled']],
    ];

    /** Nodes that wait for a person. */
    public const HUMAN = ['user_task', 'approval', 'review', 'exception'];

    public const ASSIGNEE_TYPES = [
        'user' => 'A named person',
        'role' => 'Everyone with a role',
        'department_head' => 'Head of a department',
        'department_members' => 'Members of a department',
        'line_manager' => "The requester's line manager",
        'requester' => 'The requester',
        'record_owner' => "The record's owner",
        'budget_holder' => 'The budget holder',
        'field' => 'A person chosen on the form',
        'process_owner' => 'The process owner',
    ];

    public const ESCALATIONS = [
        'notify_assignee' => 'Remind the assignee',
        'notify_manager' => "Notify the assignee's manager",
        'reassign_manager' => "Reassign to the assignee's manager",
        'notify_owner' => 'Notify the process owner',
        'raise_priority' => 'Raise the priority',
        'exception' => 'Raise an exception',
    ];

    public static function outcomes(string $type): array
    {
        return self::TYPES[$type]['outcomes'] ?? [];
    }

    public static function routesByOutcome(string $type): bool
    {
        return self::outcomes($type) !== [];
    }
}
