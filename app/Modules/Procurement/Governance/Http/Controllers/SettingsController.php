<?php

namespace App\Modules\Procurement\Governance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Procurement\Governance\Exceptions\DomainRuleException;
use App\Modules\Procurement\Governance\Services\AuditLedger;
use App\Modules\Procurement\Governance\Services\Capabilities;
use App\Modules\Procurement\Governance\Services\NotificationService;
use App\Modules\Procurement\Governance\Services\SettingsService;
use App\Modules\Procurement\Governance\Support\Presenters\OptionLists;
use App\Modules\Procurement\Governance\Support\ResolvesCompany;
use App\Modules\Procurement\Governance\Support\Rules\ConditionMatcher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Configuration: the §12 capability switches, the §5.3 rules engine, the §5.2 approval chains, the
 * §9 notification rules, and the §8 document categories.
 *
 * This is where the claim that the module is framework-agnostic is either true or empty. Everything
 * a regulation would fix — required documents, approval levels, financial thresholds, mandatory
 * fields, declarations, time limits, escalations — is edited on this screen, by an administrator,
 * without a deployment.
 */
class SettingsController extends Controller
{
    use ResolvesCompany;

    public function __construct(
        private readonly SettingsService $settings,
        private readonly Capabilities $capabilities,
        private readonly AuditLedger $audit,
        private readonly OptionLists $options,
    ) {}

    public function index(Request $request): Response
    {
        $companyId = $this->companyId($request);

        if ($companyId === null) {
            return Inertia::render('Modules/ProcurementGovernance/Settings/Index', [
                'noCompany' => true,
                'context' => $this->capabilities->forResponse(),
            ]);
        }

        return Inertia::render('Modules/ProcurementGovernance/Settings/Index', [
            'noCompany' => false,
            'settings' => $this->settings->get($companyId),
            'capabilities' => Capabilities::ALL,

            'ruleSets' => DB::table('procgov_rule_sets')
                ->where('company_id', $companyId)
                ->whereNull('deleted_at')
                ->orderBy('code')
                ->orderByDesc('version')
                ->get(),
            'rules' => DB::table('procgov_rules')
                ->where('company_id', $companyId)
                ->orderBy('rule_set_id')
                ->orderBy('sort_order')
                ->get()
                ->map(function ($r) {
                    $r->conditions = json_decode((string) $r->conditions, true);
                    $r->parameters = json_decode((string) $r->parameters, true);

                    return $r;
                }),

            'chains' => DB::table('procgov_approval_chains')
                ->where('company_id', $companyId)
                ->whereNull('deleted_at')
                ->orderBy('applies_to')
                ->orderByDesc('specificity')
                ->get()
                ->map(function ($c) use ($companyId) {
                    $c->conditions = json_decode((string) $c->conditions, true);

                    // How many decisions are travelling through this chain right now. Shown on the
                    // card so an administrator knows an edit lands on live traffic before making it,
                    // rather than finding out from the refusal when they try to retire it.
                    $c->pending_requests = DB::table('procgov_approval_requests')
                        ->where('company_id', $companyId)
                        ->where('chain_id', $c->id)
                        ->where('status', 'PENDING')
                        ->count();

                    return $c;
                }),
            'chainSteps' => DB::table('procgov_approval_chain_steps')
                ->where('company_id', $companyId)
                ->orderBy('chain_id')
                ->orderBy('step_no')
                ->get(),

            'notificationRules' => DB::table('procgov_notification_rules')
                ->where('company_id', $companyId)
                ->orderBy('event')
                ->get()
                ->map(function ($r) {
                    $r->channels = json_decode((string) $r->channels, true);
                    $r->recipients = json_decode((string) $r->recipients, true);
                    $r->escalate_to = json_decode((string) $r->escalate_to, true);

                    return $r;
                }),

            'documentCategories' => DB::table('procgov_document_categories')
                ->where('company_id', $companyId)
                ->orderBy('name')
                ->get(),

            'ruleTypes' => [
                'REQUIRED_DOCUMENT', 'REQUIRED_FIELD', 'REQUIRED_DECLARATION', 'REQUIRED_APPROVAL',
                'FINANCIAL_THRESHOLD', 'TIME_LIMIT', 'BUDGET_AVAILABILITY', 'SUPPLIER_COMPLIANCE',
                'MANDATORY_JUSTIFICATION', 'CUSTOM',
            ],
            'appliesTo' => ['any', 'record', 'plan', 'plan_item', 'tender_review', 'evaluation', 'deviation'],
            /*
             * §1.6 preference frameworks, with their bands and the count of tenders relying on
             * each. An unverified framework is shown as such: these ship as a starting point, and
             * the screen has to say so rather than let a seeded points table pass for the law.
             */
            'preferenceFrameworks' => Schema::hasTable('procgov_preference_frameworks')
                ? DB::table('procgov_preference_frameworks')
                    ->where('company_id', $companyId)
                    ->whereNull('deleted_at')
                    ->orderBy('jurisdiction')
                    ->orderBy('code')
                    ->get()
                    ->map(function ($f) use ($companyId) {
                        $f->bands = DB::table('procgov_preference_bands')
                            ->where('company_id', $companyId)
                            ->where('framework_id', $f->id)
                            ->orderBy('sort_order')
                            ->get();

                        return $f;
                    })
                : collect(),

            'jurisdictions' => OptionLists::JURISDICTIONS,
            'fxRateBases' => ['CLOSING', 'EVALUATION', 'SUBMISSION'],

            'chainAppliesTo' => ['deviation', 'plan', 'tender_recommendation', 'record', 'evaluation_reopen', 'criteria_change'],
            'approverTypes' => ['role', 'user', 'permission', 'unit_manager', 'budget_owner', 'committee_chair'],
            'events' => NotificationService::EVENTS,
            'methods' => OptionLists::PROCUREMENT_METHODS,
            'departments' => $this->options->departments($companyId),
            'officers' => $this->options->officers($companyId),
            'roles' => DB::table('roles')->where('guard_name', 'web')->orderBy('name')->pluck('name'),

            'can' => ['configure' => (bool) $request->user()?->can('procgov.configure')],
            'context' => $this->capabilities->forResponse(),
        ]);
    }

    /** §12 capability switches and the module's thresholds. */
    public function update(Request $request): RedirectResponse
    {
        $companyId = $this->requireCompany($request);

        $data = $request->validate([
            'cap_planning' => ['boolean'],
            'cap_tender_evaluation' => ['boolean'],
            'cap_deviations' => ['boolean'],
            'cap_reporting' => ['boolean'],
            'cap_workload' => ['boolean'],
            'cap_governance' => ['boolean'],
            'record_prefix' => ['nullable', 'string', 'max:12'],
            'review_prefix' => ['nullable', 'string', 'max:12'],
            'plan_prefix' => ['nullable', 'string', 'max:12'],
            'deviation_prefix' => ['nullable', 'string', 'max:12'],
            'committee_prefix' => ['nullable', 'string', 'max:12'],
            'financial_year_start_month' => ['nullable', 'integer', 'min:1', 'max:12'],
            'budget_warning_threshold_pct' => ['nullable', 'integer', 'min:1', 'max:100'],
            'block_plan_over_budget' => ['boolean'],
            'moderation_variance_threshold' => ['nullable', 'integer', 'min:1', 'max:100'],
            'allow_evaluation_reopen' => ['boolean'],
            'require_coi_declaration' => ['boolean'],
            'workload_high_threshold' => ['nullable', 'integer', 'min:1', 'max:500'],
            'workload_overload_threshold' => ['nullable', 'integer', 'min:1', 'max:500'],
            'escalation_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'evaluation_sla_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'currency' => ['nullable', 'string', 'size:3'],

            // §1.6 jurisdiction and currency. The rate basis decides which day's rate converts a
            // bid; CLOSING is the only one under which every bid in a tender is measured at the
            // same instant, so it stays the default.
            'jurisdiction' => ['nullable', 'string', 'size:2'],
            'fx_rate_basis' => ['nullable', Rule::in(['CLOSING', 'EVALUATION', 'SUBMISSION'])],
            'fx_rate_type' => ['nullable', 'string', 'max:20'],
        ]);

        // A "high" band above the "overloaded" band would put every officer in one bucket and make
        // the capacity feature silently useless.
        if (
            isset($data['workload_high_threshold'], $data['workload_overload_threshold'])
            && $data['workload_high_threshold'] >= $data['workload_overload_threshold']
        ) {
            return back()->with('error', __('procurement_governance.flash.settings.threshold_order'));
        }

        $this->settings->update($companyId, $data, $this->audit);

        return back()->with('success', __('procurement_governance.flash.settings.saved'));
    }

    // ── §5.3 rule sets and rules ─────────────────────────────────────────────────────────────────

    public function storeRuleSet(Request $request): RedirectResponse
    {
        $companyId = $this->requireCompany($request);

        $data = $request->validate([
            'code' => ['required', 'string', 'max:40'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'framework_label' => ['nullable', 'string', 'max:120'],
            'applies_to' => ['required', 'string', 'max:30'],
            'effective_from' => ['nullable', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
        ]);

        // A frozen set is never edited; a new version is created beside it, so any historical check
        // can still be replayed against the rules that actually ran.
        $version = (int) DB::table('procgov_rule_sets')
            ->where('company_id', $companyId)
            ->where('code', $data['code'])
            ->max('version') + 1;

        $id = DB::table('procgov_rule_sets')->insertGetId(array_merge($data, [
            'uuid' => (string) Str::uuid(),
            'company_id' => $companyId,
            'version' => $version,
            'is_active' => true,
            'created_by' => $request->user()->id,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]));

        $this->audit->record($companyId, [
            'action' => 'rule_set_created',
            'capability' => 'governance',
            'entity_type' => 'procgov_rule_sets',
            'entity_id' => $id,
            'summary' => "Created rule set {$data['code']} v{$version} — {$data['name']}",
        ]);

        return back()->with('success', __('procurement_governance.flash.settings.rule_set_created', ['code' => $data['code'], 'version' => $version]));
    }

    public function freezeRuleSet(Request $request, int $ruleSetId): RedirectResponse
    {
        $companyId = $this->requireCompany($request);

        DB::table('procgov_rule_sets')
            ->where('company_id', $companyId)
            ->where('id', $ruleSetId)
            ->update([
                'is_frozen' => true,
                'frozen_at' => Carbon::now(),
                'frozen_by' => $request->user()->id,
                'updated_at' => Carbon::now(),
            ]);

        $this->audit->record($companyId, [
            'action' => 'rule_set_frozen',
            'capability' => 'governance',
            'entity_type' => 'procgov_rule_sets',
            'entity_id' => $ruleSetId,
        ]);

        return back()->with('success', __('procurement_governance.flash.settings.rule_set_frozen'));
    }

    public function storeRule(Request $request): RedirectResponse
    {
        $companyId = $this->requireCompany($request);

        $data = $request->validate([
            'rule_set_id' => ['required', 'integer'],
            'code' => ['required', 'string', 'max:60'],
            'name' => ['required', 'string', 'max:255'],
            'rule_type' => ['required', 'string', 'max:40'],
            'severity' => ['required', Rule::in(['FAIL', 'WARNING', 'INFO'])],
            'conditions' => ['nullable', 'array'],
            'parameters' => ['nullable', 'array'],
            'message' => ['nullable', 'string', 'max:500'],
            'remedy' => ['nullable', 'string', 'max:500'],
            'escalate_after_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'escalate_to_type' => ['nullable', 'string', 'max:20'],
            'escalate_to_id' => ['nullable', 'integer'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ]);

        $frozen = DB::table('procgov_rule_sets')
            ->where('company_id', $companyId)
            ->where('id', $data['rule_set_id'])
            ->value('is_frozen');

        if ($frozen) {
            return back()->with('error', __('procurement_governance.flash.settings.rule_set_is_frozen'));
        }

        DB::table('procgov_rules')->updateOrInsert(
            ['rule_set_id' => $data['rule_set_id'], 'code' => $data['code']],
            array_merge($data, [
                'uuid' => (string) Str::uuid(),
                'company_id' => $companyId,
                'conditions' => isset($data['conditions']) ? json_encode($data['conditions']) : null,
                'parameters' => isset($data['parameters']) ? json_encode($data['parameters']) : null,
                'is_active' => true,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ])
        );

        $this->audit->record($companyId, [
            'action' => 'rule_saved',
            'capability' => 'governance',
            'entity_type' => 'procgov_rules',
            'entity_id' => (int) $data['rule_set_id'],
            'after' => ['code' => $data['code'], 'type' => $data['rule_type'], 'severity' => $data['severity']],
            'summary' => "Saved compliance rule {$data['code']} ({$data['rule_type']}, {$data['severity']})",
        ]);

        return back()->with('success', __('procurement_governance.flash.settings.rule_saved', ['code' => $data['code']]));
    }

    public function destroyRule(Request $request, int $ruleId): RedirectResponse
    {
        $companyId = $this->requireCompany($request);

        $rule = DB::table('procgov_rules')->where('company_id', $companyId)->find($ruleId);

        if (! $rule) {
            return back()->with('error', __('procurement_governance.flash.settings.rule_not_found'));
        }

        // Deactivated rather than deleted: existing compliance results reference it, and a result
        // whose rule has vanished cannot be explained to an auditor.
        DB::table('procgov_rules')->where('id', $ruleId)->update([
            'is_active' => false,
            'updated_at' => Carbon::now(),
        ]);

        $this->audit->record($companyId, [
            'action' => 'rule_deactivated',
            'capability' => 'governance',
            'entity_type' => 'procgov_rules',
            'entity_id' => $ruleId,
            'before' => ['code' => $rule->code, 'is_active' => true],
        ]);

        return back()->with('success', __('procurement_governance.flash.settings.rule_deactivated'));
    }

    // ── §5.2 approval chains ─────────────────────────────────────────────────────────────────────

    public function storeChain(Request $request): RedirectResponse
    {
        $companyId = $this->requireCompany($request);

        $data = $request->validate([
            'code' => ['required', 'string', 'max:40'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'applies_to' => ['required', 'string', 'max:40'],
            'conditions' => ['nullable', 'array'],
            'is_default' => ['boolean'],
            'steps' => ['required', 'array', 'min:1'],
            'steps.*.name' => ['required', 'string', 'max:120'],
            'steps.*.approver_type' => ['required', 'string', 'max:24'],
            'steps.*.approver_id' => ['nullable', 'integer'],
            'steps.*.approver_ref' => ['nullable', 'string', 'max:190'],
            'steps.*.quorum' => ['nullable', 'integer', 'min:1', 'max:50'],
            'steps.*.sla_hours' => ['nullable', 'integer', 'min:1', 'max:8760'],
            'steps.*.conditions' => ['nullable', 'array'],
        ]);

        $this->guardSteps($data['steps']);

        $matcher = app(ConditionMatcher::class);
        $chainId = null;

        DB::transaction(function () use ($companyId, $data, $matcher, $request, &$chainId) {
            $existing = DB::table('procgov_approval_chains')
                ->where('company_id', $companyId)
                ->where('code', $data['code'])
                ->first();

            $attributes = [
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'applies_to' => $data['applies_to'],
                'conditions' => isset($data['conditions']) ? json_encode($data['conditions']) : null,
                // Precomputed so chain selection is a sort rather than a per-request evaluation.
                'specificity' => $matcher->specificity($data['conditions'] ?? null),
                'is_active' => true,
                'is_default' => $data['is_default'] ?? false,
                'updated_at' => Carbon::now(),
            ];

            if ($existing) {
                // Deliberately not touching uuid, created_by or created_at. Editing a chain is not
                // creating one: rewriting the uuid would break every link that already points at it,
                // and rewriting the creator would quietly reassign authorship of an approval rule to
                // whoever last adjusted an SLA — the opposite of what an audit trail is for.
                DB::table('procgov_approval_chains')->where('id', $existing->id)->update($attributes);
                $chainId = (int) $existing->id;
            } else {
                $chainId = (int) DB::table('procgov_approval_chains')->insertGetId($attributes + [
                    'uuid' => (string) Str::uuid(),
                    'company_id' => $companyId,
                    'code' => $data['code'],
                    'created_by' => $request->user()->id,
                    'created_at' => Carbon::now(),
                ]);
            }

            $chain = (object) ['id' => $chainId];

            // Steps are replaced wholesale: a chain is a sequence, and merging edits into an
            // existing sequence produces orderings nobody asked for.
            DB::table('procgov_approval_chain_steps')->where('chain_id', $chain->id)->delete();

            foreach (array_values($data['steps']) as $index => $step) {
                DB::table('procgov_approval_chain_steps')->insert([
                    'company_id' => $companyId,
                    'chain_id' => $chain->id,
                    'step_no' => $index + 1,
                    'name' => $step['name'],
                    'approver_type' => $step['approver_type'],
                    'approver_id' => $step['approver_id'] ?? null,
                    'approver_ref' => $step['approver_ref'] ?? null,
                    'quorum' => $step['quorum'] ?? 1,
                    'sla_hours' => $step['sla_hours'] ?? null,
                    'conditions' => isset($step['conditions']) ? json_encode($step['conditions']) : null,
                    'is_mandatory' => true,
                    'can_delegate' => true,
                    'created_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                ]);
            }
        });

        $this->audit->record($companyId, [
            'action' => 'approval_chain_saved',
            'capability' => 'governance',
            'entity_type' => 'procgov_approval_chains',
            'entity_id' => (int) $chainId,
            'summary' => sprintf(
                'Saved approval chain %s for %s with %d step(s)',
                $data['code'],
                $data['applies_to'],
                count($data['steps']),
            ),
        ]);

        return back()->with('success', __('procurement_governance.flash.settings.chain_saved', ['code' => $data['code']]));
    }

    /**
     * Rules a chain's steps must satisfy to be capable of ever completing.
     *
     * These are not cosmetic. A step naming one person but demanding several signatures can never
     * be satisfied, so every request routed through it stops there forever — and it fails silently,
     * because nothing errors: the request simply sits in a queue nobody can clear. The same is true
     * of a step whose approver was never chosen. Both are trivially easy to save from a form, so
     * they are refused here rather than discovered weeks later by whoever is waiting.
     *
     * @param  array<int, array<string, mixed>>  $steps
     */
    private function guardSteps(array $steps): void
    {
        foreach (array_values($steps) as $index => $step) {
            $position = $index + 1;
            $type = $step['approver_type'] ?? '';
            $quorum = (int) ($step['quorum'] ?? 1);

            if ($type === 'user') {
                if (empty($step['approver_id'])) {
                    throw DomainRuleException::forField(
                        "steps.{$index}.approver_id",
                        __('procurement_governance.rules.chain.step_needs_person', ['step' => $position]),
                    );
                }

                if ($quorum > 1) {
                    throw DomainRuleException::forField(
                        "steps.{$index}.quorum",
                        __('procurement_governance.rules.chain.step_quorum_impossible', ['step' => $position, 'quorum' => $quorum]),
                    );
                }
            }

            if (in_array($type, ['role', 'permission'], true) && blank($step['approver_ref'] ?? null)) {
                $what = $type === 'role' ? 'role' : 'permission';

                throw DomainRuleException::forField(
                    "steps.{$index}.approver_ref",
                    __('procurement_governance.rules.chain.step_needs_approver', ['step' => $position, 'what' => $what]),
                );
            }
        }
    }

    /**
     * Retire an approval chain.
     *
     * Soft-deleted rather than removed, and refused while requests are still travelling through it:
     * a chain is the record of how a decision was routed, so deleting one out from under a live
     * request would leave an approval nobody can explain. Retiring the last default for a subject
     * is refused for the opposite reason — the next request would have nowhere to go.
     */
    public function destroyChain(Request $request, int $chainId): RedirectResponse
    {
        $companyId = $this->requireCompany($request);

        $chain = DB::table('procgov_approval_chains')
            ->where('company_id', $companyId)
            ->where('id', $chainId)
            ->whereNull('deleted_at')
            ->first();

        if (! $chain) {
            throw DomainRuleException::forField('chain', __('procurement_governance.rules.chain.not_found'));
        }

        $live = DB::table('procgov_approval_requests')
            ->where('company_id', $companyId)
            ->where('chain_id', $chainId)
            ->where('status', 'PENDING')
            ->count();

        if ($live > 0) {
            throw DomainRuleException::forField(
                'chain',
                __('procurement_governance.rules.chain.in_flight', ['name' => $chain->name, 'count' => $live]),
            );
        }

        if ($chain->is_default) {
            $replacement = DB::table('procgov_approval_chains')
                ->where('company_id', $companyId)
                ->where('applies_to', $chain->applies_to)
                ->where('id', '!=', $chainId)
                ->where('is_default', true)
                ->whereNull('deleted_at')
                ->exists();

            if (! $replacement) {
                throw DomainRuleException::forField(
                    'chain',
                    __('procurement_governance.rules.chain.only_default', ['name' => $chain->name, 'subject' => $chain->applies_to]),
                );
            }
        }

        DB::transaction(function () use ($companyId, $chainId) {
            DB::table('procgov_approval_chains')
                ->where('company_id', $companyId)
                ->where('id', $chainId)
                ->update(['is_active' => false, 'deleted_at' => Carbon::now(), 'updated_at' => Carbon::now()]);
        });

        $this->audit->record($companyId, [
            'action' => 'approval_chain_retired',
            'capability' => 'governance',
            'entity_type' => 'procgov_approval_chains',
            'entity_id' => $chainId,
            'summary' => "Retired approval chain {$chain->code} ({$chain->name})",
        ]);

        return back()->with('success', __('procurement_governance.flash.settings.chain_retired', ['name' => $chain->name]));
    }

    // ── §1.6 preference frameworks ───────────────────────────────────────────────────────────────

    /**
     * Create or edit a preference framework and its bands.
     *
     * This screen exists because the module ships no country's statute. The seeded frameworks are a
     * shape; the numbers belong to whoever holds the current instrument for that jurisdiction, and
     * until they can enter them the table is worth nothing. Bands are replaced wholesale for the
     * same reason approval-chain steps are: a points table is read as a whole, and merging edits
     * into one produces orderings nobody chose.
     */
    public function storeFramework(Request $request): RedirectResponse
    {
        $companyId = $this->requireCompany($request);

        $data = $request->validate([
            'code' => ['required', 'string', 'max:40'],
            'name' => ['required', 'string', 'max:190'],
            'jurisdiction' => ['required', 'string', 'size:2'],
            'basis' => ['required', Rule::in(['POINTS', 'MARGIN'])],
            'max_points' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'price_weight' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'preference_weight' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['boolean'],

            // Confirmation is a separate, deliberate act — see confirmVerification().
            'confirm_verified' => ['boolean'],

            'bands' => ['array'],
            'bands.*.label' => ['required', 'string', 'max:190'],
            'bands.*.match_field' => ['required', 'string', 'max:60'],
            'bands.*.match_value' => ['required', 'string', 'max:60'],
            'bands.*.points' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'bands.*.margin_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        $this->guardFramework($data);

        $frameworkId = null;

        DB::transaction(function () use ($companyId, $data, $request, &$frameworkId) {
            $existing = DB::table('procgov_preference_frameworks')
                ->where('company_id', $companyId)
                ->where('code', $data['code'])
                ->first();

            $attributes = [
                'name' => $data['name'],
                'jurisdiction' => strtoupper($data['jurisdiction']),
                'basis' => $data['basis'],
                'max_points' => $data['max_points'] ?? 0,
                'price_weight' => $data['price_weight'] ?? null,
                'preference_weight' => $data['preference_weight'] ?? null,
                'notes' => $data['notes'] ?? null,
                'is_active' => $data['is_active'] ?? true,
                'updated_at' => Carbon::now(),
            ];

            /*
             * Editing clears the confirmation unless it is re-given in the same act.
             *
             * A confirmation attaches to the numbers that were confirmed. Leaving it standing while
             * the points underneath change would let an unreviewed table inherit someone else's
             * sign-off, which is worse than never having asked for one.
             */
            $confirmed = (bool) ($data['confirm_verified'] ?? false);

            $attributes['is_verified'] = $confirmed;
            $attributes['verified_by'] = $confirmed ? $request->user()->id : null;
            $attributes['verified_at'] = $confirmed ? Carbon::now() : null;

            if ($existing) {
                DB::table('procgov_preference_frameworks')->where('id', $existing->id)->update($attributes);
                $frameworkId = (int) $existing->id;
            } else {
                $frameworkId = (int) DB::table('procgov_preference_frameworks')->insertGetId($attributes + [
                    'uuid' => (string) Str::uuid(),
                    'company_id' => $companyId,
                    'code' => $data['code'],
                    'created_at' => Carbon::now(),
                ]);
            }

            DB::table('procgov_preference_bands')
                ->where('company_id', $companyId)
                ->where('framework_id', $frameworkId)
                ->delete();

            foreach (array_values($data['bands'] ?? []) as $i => $band) {
                DB::table('procgov_preference_bands')->insert([
                    'company_id' => $companyId,
                    'framework_id' => $frameworkId,
                    'label' => $band['label'],
                    'match_field' => $band['match_field'],
                    'match_value' => trim((string) $band['match_value']),
                    'points' => $band['points'] ?? 0,
                    'margin_pct' => $band['margin_pct'] ?? null,
                    'sort_order' => $i,
                    'created_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                ]);
            }
        });

        $this->audit->record($companyId, [
            'action' => ($data['confirm_verified'] ?? false) ? 'preference_framework_confirmed' : 'preference_framework_saved',
            'capability' => 'governance',
            'entity_type' => 'procgov_preference_frameworks',
            'entity_id' => (int) $frameworkId,
            'summary' => sprintf(
                '%s preference framework %s (%s) with %d band(s)',
                ($data['confirm_verified'] ?? false) ? 'Confirmed' : 'Saved',
                $data['code'],
                strtoupper($data['jurisdiction']),
                count($data['bands'] ?? []),
            ),
        ]);

        return back()->with('success', __('procurement_governance.flash.settings.framework_saved', ['name' => $data['name']]));
    }

    /**
     * Rules a preference framework must satisfy to award anything meaningful.
     *
     * @param  array<string, mixed>  $data
     */
    private function guardFramework(array $data): void
    {
        $bands = array_values($data['bands'] ?? []);

        // The split is what the price formula divides; anything else silently loses or invents
        // points on every bid scored under it.
        if (isset($data['price_weight'], $data['preference_weight'])) {
            $total = (float) $data['price_weight'] + (float) $data['preference_weight'];

            if ($total > 0.0 && abs($total - 100.0) > 0.001) {
                throw DomainRuleException::forField(
                    'price_weight',
                    __('procurement_governance.rules.framework.split_total', ['total' => $total]),
                );
            }
        }

        $seen = [];

        foreach ($bands as $index => $band) {
            $position = $index + 1;

            // Bands are evaluated in order and the first match wins, so two bands claiming the same
            // value means one of them silently never applies.
            $key = strtolower($band['match_field'].'='.trim((string) $band['match_value']));

            if (isset($seen[$key])) {
                throw DomainRuleException::forField(
                    "bands.{$index}.match_value",
                    __('procurement_governance.rules.framework.band_duplicate', [
                        'band' => $position,
                        'value' => $band['match_value'],
                        'other' => $seen[$key],
                    ]),
                );
            }

            $seen[$key] = $position;

            if (($data['basis'] ?? 'POINTS') !== 'POINTS') {
                continue;
            }

            $points = (float) ($band['points'] ?? 0);
            $max = (float) ($data['max_points'] ?? 0);

            if ($max > 0 && $points > $max) {
                throw DomainRuleException::forField(
                    "bands.{$index}.points",
                    __('procurement_governance.rules.framework.band_above_max', ['band' => $position, 'points' => $points, 'max' => $max]),
                );
            }
        }
    }

    /**
     * Retire a preference framework.
     *
     * Refused while a tender names it: the framework is the record of the rules that tender is being
     * scored under, and removing it mid-evaluation would leave an award nobody can account for.
     */
    public function destroyFramework(Request $request, int $frameworkId): RedirectResponse
    {
        $companyId = $this->requireCompany($request);

        $framework = DB::table('procgov_preference_frameworks')
            ->where('company_id', $companyId)
            ->where('id', $frameworkId)
            ->whereNull('deleted_at')
            ->first();

        if (! $framework) {
            throw DomainRuleException::forField('framework', __('procurement_governance.rules.framework.not_found'));
        }

        $inUse = DB::table('procgov_tender_reviews')
            ->where('company_id', $companyId)
            ->where('preference_framework_code', $framework->code)
            ->whereNull('deleted_at')
            ->count();

        if ($inUse > 0) {
            throw DomainRuleException::forField(
                'framework',
                __('procurement_governance.rules.framework.in_use', ['name' => $framework->name, 'count' => $inUse]),
            );
        }

        DB::table('procgov_preference_frameworks')
            ->where('company_id', $companyId)
            ->where('id', $frameworkId)
            ->update(['is_active' => false, 'deleted_at' => Carbon::now(), 'updated_at' => Carbon::now()]);

        $this->audit->record($companyId, [
            'action' => 'preference_framework_retired',
            'capability' => 'governance',
            'entity_type' => 'procgov_preference_frameworks',
            'entity_id' => $frameworkId,
            'summary' => "Retired preference framework {$framework->code} ({$framework->name})",
        ]);

        return back()->with('success', __('procurement_governance.flash.settings.framework_retired', ['name' => $framework->name]));
    }

    // ── §9 notification rules ────────────────────────────────────────────────────────────────────

    public function storeNotificationRule(Request $request): RedirectResponse
    {
        $companyId = $this->requireCompany($request);

        $data = $request->validate([
            'code' => ['required', 'string', 'max:60'],
            'name' => ['required', 'string', 'max:255'],
            'event' => ['required', Rule::in(NotificationService::EVENTS)],
            'channels' => ['nullable', 'array'],
            'recipients' => ['nullable', 'array'],
            'lead_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'repeat_every_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'escalate_after_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'escalate_to' => ['nullable', 'array'],
            'conditions' => ['nullable', 'array'],
            'is_active' => ['boolean'],
        ]);

        DB::table('procgov_notification_rules')->updateOrInsert(
            ['company_id' => $companyId, 'code' => $data['code']],
            [
                'name' => $data['name'],
                'event' => $data['event'],
                'channels' => json_encode($data['channels'] ?? ['database', 'mail']),
                'recipients' => json_encode($data['recipients'] ?? []),
                'lead_days' => $data['lead_days'] ?? 0,
                'repeat_every_days' => $data['repeat_every_days'] ?? null,
                'escalate_after_days' => $data['escalate_after_days'] ?? null,
                'escalate_to' => isset($data['escalate_to']) ? json_encode($data['escalate_to']) : null,
                'conditions' => isset($data['conditions']) ? json_encode($data['conditions']) : null,
                'is_active' => $data['is_active'] ?? true,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]
        );

        return back()->with('success', __('procurement_governance.flash.settings.notification_saved', ['code' => $data['code']]));
    }

    // ── §8 document categories ───────────────────────────────────────────────────────────────────

    public function storeDocumentCategory(Request $request): RedirectResponse
    {
        $companyId = $this->requireCompany($request);

        $data = $request->validate([
            'code' => ['required', 'string', 'max:40'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'default_confidentiality' => ['required', Rule::in(['PUBLIC', 'INTERNAL', 'CONFIDENTIAL', 'RESTRICTED'])],
            'requires_expiry' => ['boolean'],
            'requires_approval' => ['boolean'],
            'requires_acknowledgement' => ['boolean'],
            'retention_months' => ['nullable', 'integer', 'min:1', 'max:1200'],
        ]);

        DB::table('procgov_document_categories')->updateOrInsert(
            ['company_id' => $companyId, 'code' => $data['code']],
            array_merge($data, ['is_active' => true, 'created_at' => Carbon::now(), 'updated_at' => Carbon::now()])
        );

        return back()->with('success', __('procurement_governance.flash.settings.category_saved', ['code' => $data['code']]));
    }
}
