<?php

namespace Database\Seeders;

use App\Core\Category\Models\Category;
use App\Core\Category\Models\CategoryAccess;
use App\Core\Module\Models\ModuleAccess;
use App\Core\Module\Models\Modules;
use App\Core\Ribbon\Models\Ribbon;
use App\Core\Ribbon\Models\RibbonAccess;
use App\Modules\Procurement\Governance\Support\ProcurementGovernanceHubs;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Registers Procurement Governance under "Supply Chain Management": the module row, its nav
 * ribbons, the granular permission set from §10, and access for the users who already work in that
 * area. Idempotent — safe to re-run, and re-running prunes ribbons retired from the hub config.
 */
class ProcurementGovernanceModuleSeeder extends Seeder
{
    private const LEVEL = 5; // SUPERUSER — the ribbon's own access level; hub tiers narrow it

    private const MODULE_CODE = 'procurement_governance';

    /**
     * §10 asks for granular permissions rather than one "Procurement Access", covering
     * View → Create → Edit → Submit → Evaluate → Approve → Reject → Export → Audit → Configure.
     *
     * The two reads at the end are guarded separately because confidentiality in this module is not
     * a synonym for seniority: a bid submission must be unreadable to people who can otherwise
     * administer the tender, right up until it closes.
     *
     * @return list<string>
     */
    public static function permissions(): array
    {
        return [
            // Read
            'procgov.dashboard.view',
            'procgov.plan.view',
            'procgov.tender.view',
            'procgov.deviation.view',
            'procgov.record.view',
            'procgov.document.view',
            'procgov.report.view',
            'procgov.workload.view',

            // §2 planning
            'procgov.plan.create',
            'procgov.plan.edit',
            'procgov.plan.submit',
            'procgov.plan.approve',
            'procgov.plan.activate',

            // §1 tendering and evaluation
            'procgov.tender.create',
            'procgov.tender.edit',
            'procgov.evaluate',            // the committee member's seat
            'procgov.tender.moderate',     // chairperson / authorised moderator
            'procgov.tender.recommend',
            'procgov.tender.approve',
            'procgov.committee.manage',

            // §5 deviations
            'procgov.deviation.create',
            'procgov.deviation.edit',
            'procgov.deviation.approve',

            // §6 / §8
            'procgov.record.edit',
            'procgov.document.manage',

            /*
             * The two confidentiality gates DocumentService reads. They are separate permissions,
             * not a consequence of seniority: a bid submission must be unreadable to people who can
             * otherwise administer the tender, right up until it closes. Without these rows the
             * gate fails closed — correct, but it means an appointed evaluator cannot open the bid
             * they were appointed to score, so they must exist to be grantable.
             */
            'procgov.document.confidential',
            'procgov.document.restricted',

            // §3 / §4
            'procgov.report.export',
            'procgov.workload.manage',

            // §7 and §12 — the two that must never be granted casually
            'procgov.audit.view',
            'procgov.configure',
        ];
    }

    /**
     * The pick lists a tenant starts with. Deliberately generic and fully editable — these are a
     * starting point so the dropdowns are useful on day one, not a taxonomy this module imposes.
     * §5.3's principle applies to vocabulary too.
     *
     * @return array<string, list<array{0:string,1:string}>>
     */
    public static function starterOptionLists(): array
    {
        return [
            'procurement_category' => [
                ['GOODS', 'Goods'],
                ['SERVICES', 'Services'],
                ['WORKS', 'Works / construction'],
                ['ICT', 'ICT and software'],
                ['PROFESSIONAL', 'Professional services'],
                ['CONSULTING', 'Consulting'],
                ['MAINTENANCE', 'Maintenance and repairs'],
                ['FACILITIES', 'Facilities and cleaning'],
                ['SECURITY', 'Security services'],
                ['FLEET', 'Fleet and transport'],
                ['UTILITIES', 'Utilities'],
                ['TRAINING', 'Training and development'],
                ['MEDICAL', 'Medical and laboratory'],
                ['STATIONERY', 'Stationery and office supplies'],
                ['MARKETING', 'Marketing and communications'],
            ],
            'committee_type' => [
                ['SPECIFICATION', 'Bid specification committee'],
                ['EVALUATION', 'Bid evaluation committee'],
                ['ADJUDICATION', 'Bid adjudication committee'],
                ['TECHNICAL', 'Technical evaluation panel'],
                ['STEERING', 'Procurement steering committee'],
            ],
        ];
    }

    public function run(): void
    {
        foreach (self::permissions() as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        // Roles are not created here: role design belongs to the tenant, and inventing the twelve
        // roles §10 lists as *examples* would put users in groups nobody asked for. Existing
        // platform roles that clearly map get the full set.
        foreach (['administrator', 'super admin'] as $roleName) {
            Role::where('name', $roleName)->where('guard_name', 'web')->first()?->givePermissionTo(self::permissions());
        }

        $category = Category::firstOrCreate(
            ['name' => 'Supply Chain Management'],
            [
                'active' => 1,
                'description' => 'Contracts, suppliers, procurement and vendor lifecycle management',
                'icon' => 'truck',
            ]
        );

        $module = Modules::firstOrNew(['code_name' => self::MODULE_CODE]);

        if (! $module->exists) {
            $module->uuid = (string) Str::uuid();
        }

        $module->fill([
            'active' => 1,
            'category_id' => $category->id,
            'name' => 'Procurement Governance',
            'path' => '/procurement-governance',
            'font_awesome' => 'gavel',
        ])->save();

        // Single source of truth for navigation: the hub config, so nav and tier enforcement can
        // never drift apart.
        $ribbons = ProcurementGovernanceHubs::navRibbons();
        $ribbonIds = [];

        foreach ($ribbons as $i => $r) {
            $ribbon = Ribbon::firstOrNew(['module_id' => $module->id, 'ribbon_name' => $r['name']]);

            if (! $ribbon->exists) {
                $ribbon->uuid = (string) Str::uuid();
            }

            $ribbon->fill([
                'active' => 1,
                'sort_order' => $i + 1,
                'ribbon_path' => $r['path'],
                'description' => $r['name'],
                'font_awesome' => $r['icon'],
                'access_level' => $r['access_level'] ?? self::LEVEL,
            ])->save();

            $ribbonIds[] = $ribbon->id;
        }

        $stale = Ribbon::where('module_id', $module->id)
            ->whereNotIn('ribbon_name', array_column($ribbons, 'name'))
            ->pluck('id');

        if ($stale->isNotEmpty()) {
            RibbonAccess::whereIn('ribbon_id', $stale)->delete();
            Ribbon::whereIn('id', $stale)->delete();
        }

        $this->grantAccess($category, $module, $ribbonIds);
        $this->seedStarterConfiguration();

        $this->command?->info('Procurement Governance registered under Supply Chain Management.');
    }

    /**
     * Grant access to everyone who already reaches another Supply Chain module, so the new module
     * appears for the people who work in that area. Falls back to all users on an install where
     * nothing in the category has been granted yet.
     */
    private function grantAccess(Category $category, Modules $module, array $ribbonIds): void
    {
        $categoryModuleIds = Modules::where('category_id', $category->id)->pluck('id');

        $userIds = ModuleAccess::whereIn('module_id', $categoryModuleIds)
            ->distinct()
            ->pluck('user_id')
            ->filter()
            ->all();

        if ($userIds === []) {
            $userIds = DB::table('users')->whereNull('deleted_at')->pluck('id')->all();
        }

        /*
         * Existing access rows outlive the users they point at — `security_module_access` has no
         * cascade, so a hard-deleted user leaves an orphan row behind. Granting from that list
         * unfiltered throws a foreign key violation and takes the whole migration down with it, on
         * exactly the long-lived installs where the module is most wanted. Intersecting with the
         * users that actually exist is the difference between this seeder being idempotent and
         * being a coin toss.
         */
        $userIds = DB::table('users')
            ->whereIn('id', $userIds ?: [0])
            ->whereNull('deleted_at')
            ->pluck('id')
            ->all();

        foreach ($userIds as $userId) {
            CategoryAccess::firstOrCreate(
                ['category_id' => $category->id, 'user_id' => $userId],
                ['access_level' => self::LEVEL]
            );

            ModuleAccess::firstOrCreate(
                ['module_id' => $module->id, 'user_id' => $userId],
                ['access_level' => self::LEVEL]
            );

            foreach ($ribbonIds as $ribbonId) {
                RibbonAccess::firstOrCreate(
                    ['ribbon_id' => $ribbonId, 'user_id' => $userId],
                    ['access_level' => self::LEVEL]
                );
            }
        }
    }

    /**
     * A starter configuration per existing tenant, so the module is usable on the first click.
     *
     * These are defaults, not a framework. Every threshold here is deliberately generic — a single
     * approval level, no financial bands, no required documents — because inventing a regulatory
     * regime for a tenant is precisely what §5.3 says not to do. What the tenant gets is a working
     * skeleton they can shape, rather than a blank configuration screen and a module that refuses
     * every submission until somebody finishes filling it in.
     */
    private function seedStarterConfiguration(): void
    {
        $companyIds = DB::table('companies')->pluck('id');

        foreach ($companyIds as $companyId) {
            DB::table('procgov_settings')->updateOrInsert(
                ['company_id' => $companyId],
                ['created_at' => now(), 'updated_at' => now()]
            );

            // A default approval chain per approvable subject. Without one, `submit` refuses —
            // correctly, but a tenant should not have to configure a chain before they can raise
            // their first deviation.
            foreach ([
                'deviation' => 'Deviation approval',
                'plan' => 'Procurement plan approval',
                'tender_recommendation' => 'Award recommendation approval',
                'criteria_change' => 'Evaluation criteria change approval',
            ] as $appliesTo => $name) {
                $code = 'default_'.$appliesTo;

                $exists = DB::table('procgov_approval_chains')
                    ->where('company_id', $companyId)
                    ->where('code', $code)
                    ->exists();

                if ($exists) {
                    continue;
                }

                $chainId = DB::table('procgov_approval_chains')->insertGetId([
                    'uuid' => (string) Str::uuid(),
                    'company_id' => $companyId,
                    'code' => $code,
                    'name' => $name,
                    'description' => 'Default single-level approval. Replace or extend in settings.',
                    'applies_to' => $appliesTo,
                    'specificity' => 0,
                    'is_active' => true,
                    'is_default' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::table('procgov_approval_chain_steps')->insert([
                    'company_id' => $companyId,
                    'chain_id' => $chainId,
                    'step_no' => 1,
                    'name' => 'Management approval',
                    'approver_type' => 'permission',
                    'approver_ref' => match ($appliesTo) {
                        'plan' => 'procgov.plan.approve',
                        'deviation' => 'procgov.deviation.approve',
                        default => 'procgov.tender.approve',
                    },
                    'quorum' => 1,
                    'sla_hours' => 120,
                    'is_mandatory' => true,
                    'can_delegate' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // §9 starter notification rules. Recipients are dynamic, so they keep working when
            // people change roles.
            foreach ([
                ['pending_approval', 'Pending approvals', 'PENDING_APPROVAL', 0, 3],
                ['evaluation_deadline', 'Evaluation deadline approaching', 'EVALUATION_DEADLINE', 5, null],
                ['overdue_evaluation', 'Overdue evaluations', 'OVERDUE_EVALUATION', 0, 3],
                ['expiring_document', 'Expiring documents', 'EXPIRING_DOCUMENT', 30, 7],
                ['procurement_delay', 'Delayed procurement', 'PROCUREMENT_DELAY', 0, 7],
                ['compliance_failure', 'Compliance failures', 'COMPLIANCE_FAILURE', 0, null],
            ] as [$code, $name, $event, $leadDays, $repeatDays]) {
                DB::table('procgov_notification_rules')->updateOrInsert(
                    ['company_id' => $companyId, 'code' => $code],
                    [
                        'name' => $name,
                        'event' => $event,
                        'channels' => json_encode(['database', 'mail']),
                        'recipients' => json_encode(['dynamic' => ['officer', 'unit_manager']]),
                        'lead_days' => $leadDays,
                        'repeat_every_days' => $repeatDays,
                        'is_active' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );
            }

            // §8 starter document categories — the ones nearly every procurement function needs.
            foreach ([
                ['TAX_CLEARANCE', 'Tax clearance certificate', 'INTERNAL', true],
                ['BBBEE', 'B-BBEE certificate', 'INTERNAL', true],
                ['COMPANY_REG', 'Company registration', 'INTERNAL', false],
                ['BID_DOCUMENT', 'Bid document', 'CONFIDENTIAL', false],
                ['TENDER_ADVERT', 'Tender advertisement', 'PUBLIC', false],
                ['SPECIFICATION', 'Specification', 'INTERNAL', false],
                ['QUOTATION', 'Quotation', 'CONFIDENTIAL', false],
                ['MOTIVATION', 'Motivation / justification', 'INTERNAL', false],
                ['APPROVAL_MEMO', 'Approval memorandum', 'INTERNAL', false],
            ] as [$code, $name, $confidentiality, $requiresExpiry]) {
                DB::table('procgov_document_categories')->updateOrInsert(
                    ['company_id' => $companyId, 'code' => $code],
                    [
                        'name' => $name,
                        'default_confidentiality' => $confidentiality,
                        'requires_expiry' => $requiresExpiry,
                        'requires_approval' => false,
                        'requires_acknowledgement' => false,
                        'is_active' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );
            }

            /*
             * The pick lists behind the category and committee-type dropdowns.
             *
             * Guarded on the table existing: this seeder is invoked by the module-registration
             * migration, which runs *before* the migration that creates procgov_option_lists on a
             * fresh install. That migration seeds the lists itself, so skipping here costs nothing
             * and stops a clean `migrate` from failing outright.
             */
            if (Schema::hasTable('procgov_option_lists')) {
                foreach (self::starterOptionLists() as $listKey => $options) {
                    foreach (array_values($options) as $i => [$code, $label]) {
                        DB::table('procgov_option_lists')->updateOrInsert(
                            ['company_id' => $companyId, 'list_key' => $listKey, 'code' => $code],
                            [
                                'label' => $label,
                                'sort_order' => $i,
                                'is_active' => true,
                                'created_at' => now(),
                                'updated_at' => now(),
                            ]
                        );
                    }
                }
            }

            self::seedPreferenceFrameworksFor($companyId);

            // §5.1 starter deviation types.
            foreach ([
                ['EMERGENCY', 'Emergency procurement', 'HIGH'],
                ['SOLE_SUPPLIER', 'Sole supplier', 'HIGH'],
                ['CONTRACT_EXPANSION', 'Contract expansion or extension', 'MEDIUM'],
                ['POLICY_DEVIATION', 'Deviation from procurement policy', 'MEDIUM'],
            ] as [$code, $name, $risk]) {
                DB::table('procgov_deviation_types')->updateOrInsert(
                    ['company_id' => $companyId, 'code' => $code],
                    [
                        'name' => $name,
                        'default_risk_level' => $risk,
                        'requires_supplier' => $code === 'SOLE_SUPPLIER',
                        'requires_risk_assessment' => true,
                        'is_active' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );
            }
        }
    }

    /**
     * Starter preference frameworks for the jurisdictions this product is sold into (§1.6).
     *
     * Every one of these is seeded with is_verified = false, and that flag is the point of this
     * method. Preference is the most litigated number in a Southern African tender, and the module
     * must not imply it knows any country's current allocation. What it can safely provide is the
     * shape — a named framework, the statutory price/preference split where that split is itself
     * stable, and a table of bands ready to be confirmed or replaced by whoever carries the
     * accounting-officer responsibility.
     *
     * South Africa is seeded with bands only because the PPPFA *price* formula and the 80/20 and
     * 90/10 splits are stable and arithmetic. The points attached to each B-BBEE level are not:
     * since the 2022 Preferential Procurement Regulations each organ of state sets its own specific
     * goals, so the levels below are a conventional starting point and nothing more.
     *
     * Eswatini and Botswana are seeded as MARGIN frameworks with no numbers at all. Both operate
     * margin-of-preference style schemes rather than a points table, and inventing percentages for
     * either would be worse than shipping an empty framework that visibly asks to be configured.
     */
    public static function seedPreferenceFrameworksFor(int $companyId): void
    {
        if (! Schema::hasTable('procgov_preference_frameworks')) {
            return;
        }

        $frameworks = [
            [
                'code' => 'ZA_PPPFA_8020',
                'name' => 'PPPFA 80/20 (South Africa)',
                'jurisdiction' => 'ZA',
                'basis' => 'POINTS',
                'max_points' => 20,
                'price_weight' => 80,
                'preference_weight' => 20,
                'notes' => 'Price formula is statutory. The points per level are a starting point: '
                    .'since the 2022 regulations each organ of state sets its own specific goals. '
                    .'Confirm these against your approved procurement policy before use.',
                'bands' => [
                    ['B-BBEE level 1', 'bbbee_level', '1', 20],
                    ['B-BBEE level 2', 'bbbee_level', '2', 18],
                    ['B-BBEE level 3', 'bbbee_level', '3', 14],
                    ['B-BBEE level 4', 'bbbee_level', '4', 12],
                    ['B-BBEE level 5', 'bbbee_level', '5', 8],
                    ['B-BBEE level 6', 'bbbee_level', '6', 6],
                    ['B-BBEE level 7', 'bbbee_level', '7', 4],
                    ['B-BBEE level 8', 'bbbee_level', '8', 2],
                ],
            ],
            [
                'code' => 'ZA_PPPFA_9010',
                'name' => 'PPPFA 90/10 (South Africa)',
                'jurisdiction' => 'ZA',
                'basis' => 'POINTS',
                'max_points' => 10,
                'price_weight' => 90,
                'preference_weight' => 10,
                'notes' => 'Applies above the prescribed value threshold. Same caveat as 80/20: '
                    .'confirm the points per level against your approved policy.',
                'bands' => [
                    ['B-BBEE level 1', 'bbbee_level', '1', 10],
                    ['B-BBEE level 2', 'bbbee_level', '2', 9],
                    ['B-BBEE level 3', 'bbbee_level', '3', 7],
                    ['B-BBEE level 4', 'bbbee_level', '4', 6],
                    ['B-BBEE level 5', 'bbbee_level', '5', 4],
                    ['B-BBEE level 6', 'bbbee_level', '6', 3],
                    ['B-BBEE level 7', 'bbbee_level', '7', 2],
                    ['B-BBEE level 8', 'bbbee_level', '8', 1],
                ],
            ],
            [
                'code' => 'SZ_LOCAL_PREFERENCE',
                'name' => 'Local preference (Eswatini)',
                'jurisdiction' => 'SZ',
                'basis' => 'MARGIN',
                'max_points' => 0,
                'price_weight' => null,
                'preference_weight' => null,
                'notes' => 'Deliberately empty. Set the margin of preference and who qualifies from '
                    .'the current ESPPRA instrument — this module ships no Eswatini figures.',
                'bands' => [],
            ],
            [
                'code' => 'BW_CITIZEN_PREFERENCE',
                'name' => 'Citizen and local preference (Botswana)',
                'jurisdiction' => 'BW',
                'basis' => 'MARGIN',
                'max_points' => 0,
                'price_weight' => null,
                'preference_weight' => null,
                'notes' => 'Deliberately empty. Set the reservation and preference margins from the '
                    .'current PPRA scheme — this module ships no Botswana figures.',
                'bands' => [],
            ],
        ];

        foreach ($frameworks as $framework) {
            $bands = $framework['bands'];
            unset($framework['bands']);

            DB::table('procgov_preference_frameworks')->updateOrInsert(
                ['company_id' => $companyId, 'code' => $framework['code']],
                $framework + [
                    'uuid' => (string) Str::uuid(),
                    'is_verified' => false,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );

            $frameworkId = DB::table('procgov_preference_frameworks')
                ->where('company_id', $companyId)
                ->where('code', $framework['code'])
                ->value('id');

            foreach (array_values($bands) as $i => [$label, $field, $value, $points]) {
                DB::table('procgov_preference_bands')->updateOrInsert(
                    ['company_id' => $companyId, 'framework_id' => $frameworkId, 'match_field' => $field, 'match_value' => $value],
                    [
                        'label' => $label,
                        'points' => $points,
                        'sort_order' => $i,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );
            }
        }
    }
}
