<?php

namespace Database\Seeders;

use App\Modules\Operations\ProjectManagement\Models\ProjectTemplate;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;

/**
 * The starter template library (§3).
 *
 * These are deliberately *skeletons*, not complete plans. A template that ships forty tasks nobody
 * recognises gets deleted on first use; one that lays out the six phases everybody agrees on and
 * leaves the detail to the team gets kept and extended. So each one carries the stage structure,
 * the gates and the risks that are genuinely common to that kind of work — the parts people forget
 * — and stops there.
 *
 * Marked `is_builtin`, which means a tenant hides rather than deletes them: a delete would come
 * back on the next upgrade, and a tenant who removed a template would have to remove it again.
 *
 * Idempotent on (company_id, name), so re-running does not multiply the library.
 */
class ProjectManagementTemplateSeeder extends Seeder
{
    public function run(): void
    {
        if (! Schema::hasTable('pm_project_templates')) {
            return;
        }

        foreach ($this->templates() as $spec) {
            ProjectTemplate::withoutGlobalScopes()->updateOrCreate(
                ['company_id' => null, 'name' => $spec['name']],
                [...$spec, 'is_builtin' => true, 'is_active' => true],
            );
        }
    }

    /** @return list<array<string,mixed>> */
    private function templates(): array
    {
        return [
            [
                'name'        => 'Residential building',
                'industry'    => 'construction',
                'sub_type'    => 'Residential',
                'description' => 'Phases, hold points and the risks that recur on every housing job.',
                'payload'     => [
                    'wbs' => [
                        ['key' => 'a', 'name' => 'Enabling works', 'node_type' => 'phase'],
                        ['key' => 'b', 'name' => 'Substructure', 'node_type' => 'phase'],
                        ['key' => 'c', 'name' => 'Superstructure', 'node_type' => 'phase'],
                        ['key' => 'd', 'name' => 'Services', 'node_type' => 'phase'],
                        ['key' => 'e', 'name' => 'Finishes', 'node_type' => 'phase'],
                        ['key' => 'f', 'name' => 'Handover', 'node_type' => 'phase'],
                    ],
                    'tasks' => [
                        ['name' => 'Site establishment', 'wbs' => 'a', 'start_offset_days' => 0, 'duration_days' => 10],
                        ['name' => 'Bulk earthworks', 'wbs' => 'a', 'start_offset_days' => 10, 'duration_days' => 15],
                        ['name' => 'Foundations', 'wbs' => 'b', 'start_offset_days' => 25, 'duration_days' => 25],
                        ['name' => 'Slab', 'wbs' => 'b', 'start_offset_days' => 50, 'duration_days' => 15],
                        ['name' => 'Structure to roof height', 'wbs' => 'c', 'start_offset_days' => 65, 'duration_days' => 45],
                        ['name' => 'Roof', 'wbs' => 'c', 'start_offset_days' => 110, 'duration_days' => 20],
                        ['name' => 'First fix services', 'wbs' => 'd', 'start_offset_days' => 120, 'duration_days' => 25],
                        ['name' => 'Second fix services', 'wbs' => 'd', 'start_offset_days' => 160, 'duration_days' => 20],
                        ['name' => 'Plaster and paint', 'wbs' => 'e', 'start_offset_days' => 150, 'duration_days' => 35],
                        ['name' => 'Practical completion', 'wbs' => 'f', 'start_offset_days' => 195, 'duration_days' => 1, 'is_milestone' => true],
                    ],
                    'gates' => [
                        ['stage' => 'planning', 'name' => 'Design freeze', 'criteria' => 'Drawings issued for construction; bill priced; permits in hand.'],
                        ['stage' => 'execution', 'name' => 'Foundation hold point', 'criteria' => 'Engineer has inspected and signed off before any concrete is poured.'],
                        ['stage' => 'closing', 'name' => 'Practical completion', 'criteria' => 'Snag list closed out; certificates issued; as-builts handed over.'],
                    ],
                    'risks' => [
                        ['title' => 'Ground conditions differ from the geotechnical report', 'category' => 'technical', 'probability' => 3, 'impact' => 4, 'strategy' => 'reduce', 'mitigation' => 'Trial pits before foundations are priced.'],
                        ['title' => 'Wet season stops earthworks', 'category' => 'external', 'probability' => 4, 'impact' => 3, 'strategy' => 'reduce', 'mitigation' => 'Programme earthworks outside the rainy months; record every rain day in the diary.'],
                        ['title' => 'Late statutory approvals', 'category' => 'legal', 'probability' => 3, 'impact' => 4, 'strategy' => 'reduce', 'mitigation' => 'Submit early and track weekly.'],
                        ['title' => 'Material price escalation', 'category' => 'commercial', 'probability' => 3, 'impact' => 3, 'strategy' => 'transfer', 'mitigation' => 'Fix supplier rates for the first six months.'],
                    ],
                ],
            ],
            [
                'name'        => 'Road construction',
                'industry'    => 'construction',
                'sub_type'    => 'Roads',
                'description' => 'Layerworks, surfacing and the hold points a resident engineer will insist on.',
                'payload'     => [
                    'wbs' => [
                        ['key' => 'a', 'name' => 'Site clearance', 'node_type' => 'phase'],
                        ['key' => 'b', 'name' => 'Earthworks', 'node_type' => 'phase'],
                        ['key' => 'c', 'name' => 'Layerworks', 'node_type' => 'phase'],
                        ['key' => 'd', 'name' => 'Surfacing', 'node_type' => 'phase'],
                        ['key' => 'e', 'name' => 'Ancillary works', 'node_type' => 'phase'],
                    ],
                    'tasks' => [
                        ['name' => 'Clear and grub', 'wbs' => 'a', 'start_offset_days' => 0, 'duration_days' => 10],
                        ['name' => 'Cut to fill', 'wbs' => 'b', 'start_offset_days' => 10, 'duration_days' => 30],
                        ['name' => 'Subbase', 'wbs' => 'c', 'start_offset_days' => 40, 'duration_days' => 20],
                        ['name' => 'Base course', 'wbs' => 'c', 'start_offset_days' => 60, 'duration_days' => 20],
                        ['name' => 'Prime and seal', 'wbs' => 'd', 'start_offset_days' => 80, 'duration_days' => 15],
                        ['name' => 'Road markings and signage', 'wbs' => 'e', 'start_offset_days' => 95, 'duration_days' => 10],
                    ],
                    'gates' => [
                        ['stage' => 'execution', 'name' => 'Layer acceptance', 'criteria' => 'Density and level results accepted before the next layer is placed.'],
                        ['stage' => 'closing', 'name' => 'Final surface acceptance', 'criteria' => 'Riding quality measured and accepted; as-builts submitted.'],
                    ],
                    'risks' => [
                        ['title' => 'Borrow pit material fails specification', 'category' => 'quality', 'probability' => 3, 'impact' => 4, 'strategy' => 'reduce', 'mitigation' => 'Test each source before committing to it.'],
                        ['title' => 'Traffic accommodation incidents', 'category' => 'safety', 'probability' => 3, 'impact' => 5, 'strategy' => 'reduce', 'mitigation' => 'Approved TA plan, reviewed weekly on site.'],
                        ['title' => 'Community disruption stops work', 'category' => 'external', 'probability' => 3, 'impact' => 4, 'strategy' => 'reduce', 'mitigation' => 'Liaison officer appointed before establishment.'],
                    ],
                ],
            ],
            [
                'name'        => 'Software delivery',
                'industry'    => 'it',
                'sub_type'    => 'Software development',
                'description' => 'Discovery through hypercare, with the gates that stop a release going out untested.',
                'payload'     => [
                    'wbs' => [
                        ['key' => 'a', 'name' => 'Discovery', 'node_type' => 'phase'],
                        ['key' => 'b', 'name' => 'Design', 'node_type' => 'phase'],
                        ['key' => 'c', 'name' => 'Build', 'node_type' => 'phase'],
                        ['key' => 'd', 'name' => 'Test', 'node_type' => 'phase'],
                        ['key' => 'e', 'name' => 'Release', 'node_type' => 'phase'],
                        ['key' => 'f', 'name' => 'Hypercare', 'node_type' => 'phase'],
                    ],
                    'tasks' => [
                        ['name' => 'Requirements workshops', 'wbs' => 'a', 'start_offset_days' => 0, 'duration_days' => 10],
                        ['name' => 'Solution architecture', 'wbs' => 'b', 'start_offset_days' => 10, 'duration_days' => 10],
                        ['name' => 'Build', 'wbs' => 'c', 'start_offset_days' => 20, 'duration_days' => 60],
                        ['name' => 'System testing', 'wbs' => 'd', 'start_offset_days' => 70, 'duration_days' => 15],
                        ['name' => 'User acceptance testing', 'wbs' => 'd', 'start_offset_days' => 85, 'duration_days' => 15],
                        ['name' => 'Go live', 'wbs' => 'e', 'start_offset_days' => 100, 'duration_days' => 1, 'is_milestone' => true],
                        ['name' => 'Hypercare', 'wbs' => 'f', 'start_offset_days' => 101, 'duration_days' => 20],
                    ],
                    'gates' => [
                        ['stage' => 'planning', 'name' => 'Requirements signed off', 'criteria' => 'Scope agreed in writing by the business owner.'],
                        ['stage' => 'execution', 'name' => 'UAT exit', 'criteria' => 'No open critical or high defects; business owner has signed acceptance.'],
                        ['stage' => 'closing', 'name' => 'Go-live readiness', 'criteria' => 'Cutover plan rehearsed; rollback tested; support handover done.'],
                    ],
                    'risks' => [
                        ['title' => 'Scope grows without a change request', 'category' => 'commercial', 'probability' => 4, 'impact' => 4, 'strategy' => 'reduce', 'mitigation' => 'Every scope movement goes through the change register.'],
                        ['title' => 'Key person unavailable', 'category' => 'resource', 'probability' => 3, 'impact' => 4, 'strategy' => 'reduce', 'mitigation' => 'Pair on critical components; document as you go.'],
                        ['title' => 'Data migration quality', 'category' => 'technical', 'probability' => 4, 'impact' => 4, 'strategy' => 'reduce', 'mitigation' => 'Profile the source data in discovery, not at cutover.'],
                        ['title' => 'Third-party integration is not ready', 'category' => 'external', 'probability' => 3, 'impact' => 4, 'strategy' => 'reduce', 'mitigation' => 'Contract test stubs early and agree a date in writing.'],
                    ],
                ],
            ],
            [
                'name'        => 'Marketing campaign',
                'industry'    => 'marketing',
                'sub_type'    => 'Campaign',
                'description' => 'Brief to post-campaign review, with the approval gate before anything goes public.',
                'payload'     => [
                    'wbs' => [
                        ['key' => 'a', 'name' => 'Brief and strategy', 'node_type' => 'phase'],
                        ['key' => 'b', 'name' => 'Creative', 'node_type' => 'phase'],
                        ['key' => 'c', 'name' => 'Production', 'node_type' => 'phase'],
                        ['key' => 'd', 'name' => 'Launch', 'node_type' => 'phase'],
                        ['key' => 'e', 'name' => 'Review', 'node_type' => 'phase'],
                    ],
                    'tasks' => [
                        ['name' => 'Agree the brief', 'wbs' => 'a', 'start_offset_days' => 0, 'duration_days' => 5],
                        ['name' => 'Concepts', 'wbs' => 'b', 'start_offset_days' => 5, 'duration_days' => 10],
                        ['name' => 'Asset production', 'wbs' => 'c', 'start_offset_days' => 15, 'duration_days' => 15],
                        ['name' => 'Campaign live', 'wbs' => 'd', 'start_offset_days' => 30, 'duration_days' => 1, 'is_milestone' => true],
                        ['name' => 'Post-campaign review', 'wbs' => 'e', 'start_offset_days' => 60, 'duration_days' => 5],
                    ],
                    'gates' => [
                        ['stage' => 'execution', 'name' => 'Creative approval', 'criteria' => 'Client and legal have both signed off before anything is published.'],
                    ],
                    'risks' => [
                        ['title' => 'Approval cycles run longer than planned', 'category' => 'schedule', 'probability' => 4, 'impact' => 3, 'strategy' => 'reduce', 'mitigation' => 'Book the approvers\' time when the plan is made, not when the work is ready.'],
                        ['title' => 'Brand or legal objection after production', 'category' => 'legal', 'probability' => 2, 'impact' => 4, 'strategy' => 'avoid', 'mitigation' => 'Legal reviews the concept, not the finished asset.'],
                    ],
                ],
            ],
            [
                'name'        => 'Staff training programme',
                'industry'    => 'training',
                'sub_type'    => 'Training',
                'description' => 'Needs analysis through evaluation, for accredited and internal programmes alike.',
                'payload'     => [
                    'wbs' => [
                        ['key' => 'a', 'name' => 'Needs analysis', 'node_type' => 'phase'],
                        ['key' => 'b', 'name' => 'Design', 'node_type' => 'phase'],
                        ['key' => 'c', 'name' => 'Delivery', 'node_type' => 'phase'],
                        ['key' => 'd', 'name' => 'Evaluation', 'node_type' => 'phase'],
                    ],
                    'tasks' => [
                        ['name' => 'Skills gap analysis', 'wbs' => 'a', 'start_offset_days' => 0, 'duration_days' => 10],
                        ['name' => 'Course material design', 'wbs' => 'b', 'start_offset_days' => 10, 'duration_days' => 15],
                        ['name' => 'Pilot session', 'wbs' => 'c', 'start_offset_days' => 25, 'duration_days' => 2],
                        ['name' => 'Roll-out', 'wbs' => 'c', 'start_offset_days' => 30, 'duration_days' => 30],
                        ['name' => 'Impact evaluation', 'wbs' => 'd', 'start_offset_days' => 90, 'duration_days' => 5],
                    ],
                    'gates' => [
                        ['stage' => 'execution', 'name' => 'Pilot review', 'criteria' => 'Pilot feedback incorporated before the full roll-out is booked.'],
                    ],
                    'risks' => [
                        ['title' => 'Attendance below the level that makes it worthwhile', 'category' => 'resource', 'probability' => 3, 'impact' => 3, 'strategy' => 'reduce', 'mitigation' => 'Line managers commit their teams in writing before dates are set.'],
                    ],
                ],
            ],
        ];
    }
}
