<script setup lang="ts">
import { computed, ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import FormErrors from '../Partials/FormErrors.vue'
import NoCompanyNotice from '../Partials/NoCompanyNotice.vue'
import StatusChip from '../Partials/StatusChip.vue'
import { dateTime, humanise } from '../Partials/format'

const { t } = useI18n()

const props = defineProps<{
  noCompany?: boolean
  settings?: any
  capabilities?: string[]
  ruleSets?: any[]
  rules?: any[]
  chains?: any[]
  chainSteps?: any[]
  preferenceFrameworks?: any[]
  jurisdictions?: { code: string; name: string; currency: string }[]
  fxRateBases?: string[]
  notificationRules?: any[]
  documentCategories?: any[]
  ruleTypes?: string[]
  appliesTo?: string[]
  chainAppliesTo?: string[]
  approverTypes?: string[]
  events?: string[]
  methods?: string[]
  departments?: { id: number; name: string }[]
  officers?: { id: number; name: string }[]
  roles?: string[]
  can?: { configure: boolean }
}>()

const tab = ref('capabilities')
const addingRuleSet = ref(false)
const addingRule = ref(false)
const addingChain = ref(false)

const settingsForm = useForm({
  cap_planning: !!props.settings?.cap_planning,
  cap_tender_evaluation: !!props.settings?.cap_tender_evaluation,
  cap_deviations: !!props.settings?.cap_deviations,
  cap_reporting: !!props.settings?.cap_reporting,
  cap_workload: !!props.settings?.cap_workload,
  cap_governance: !!props.settings?.cap_governance,
  record_prefix: props.settings?.record_prefix ?? 'PROC',
  review_prefix: props.settings?.review_prefix ?? 'TR',
  plan_prefix: props.settings?.plan_prefix ?? 'PP',
  deviation_prefix: props.settings?.deviation_prefix ?? 'DEV',
  committee_prefix: props.settings?.committee_prefix ?? 'EC',
  financial_year_start_month: props.settings?.financial_year_start_month ?? 4,
  budget_warning_threshold_pct: props.settings?.budget_warning_threshold_pct ?? 90,
  block_plan_over_budget: !!props.settings?.block_plan_over_budget,
  moderation_variance_threshold: props.settings?.moderation_variance_threshold ?? 20,
  allow_evaluation_reopen: !!props.settings?.allow_evaluation_reopen,
  require_coi_declaration: !!props.settings?.require_coi_declaration,
  workload_high_threshold: props.settings?.workload_high_threshold ?? 10,
  workload_overload_threshold: props.settings?.workload_overload_threshold ?? 15,
  escalation_days: props.settings?.escalation_days ?? 5,
  evaluation_sla_days: props.settings?.evaluation_sla_days ?? 14,
  currency: props.settings?.currency ?? 'ZAR',
  jurisdiction: props.settings?.jurisdiction ?? 'ZA',
  fx_rate_basis: props.settings?.fx_rate_basis ?? 'CLOSING',
  fx_rate_type: props.settings?.fx_rate_type ?? 'spot',
})

/**
 * Frameworks still carrying a seeded starting point rather than a confirmed one.
 *
 * Surfaced as a badge on the tab because an unverified preference table is not a cosmetic warning:
 * it is the number a losing bidder will challenge, and nobody goes looking for it on a settings
 * screen they have no reason to open.
 */
const unverifiedFrameworks = computed(
  () => (props.preferenceFrameworks ?? []).filter((f: any) => !f.is_verified).length,
)

const editingFrameworkOpen = ref(false)
const creatingFramework = ref(false)
const retiringFramework = ref<any | null>(null)

const frameworkForm = useForm({
  code: '',
  name: '',
  jurisdiction: 'ZA',
  basis: 'POINTS',
  max_points: 20 as number | null,
  price_weight: 80 as number | null,
  preference_weight: 20 as number | null,
  notes: '',
  is_active: true,
  confirm_verified: false,
  bands: [] as any[],
})

/**
 * Bidder attributes a band can key on.
 *
 * A short list rather than free text: a band naming a field that does not exist on a bidder matches
 * nothing, silently, and the first anyone knows of it is a tender where nobody scored preference.
 */
const bandFields = computed(() => [
  { title: t('procurement_governance.settings.field_bbbee_level'), value: 'bbbee_level' },
  { title: t('procurement_governance.settings.field_local_content'), value: 'is_local_content_compliant' },
])

function newFramework() {
  creatingFramework.value = true
  frameworkForm.reset()
  frameworkForm.clearErrors()
  frameworkForm.bands = []
  editingFrameworkOpen.value = true
}

function editFramework(framework: any) {
  creatingFramework.value = false
  frameworkForm.clearErrors()
  frameworkForm.code = framework.code
  frameworkForm.name = framework.name
  frameworkForm.jurisdiction = framework.jurisdiction
  frameworkForm.basis = framework.basis
  frameworkForm.max_points = framework.max_points !== null ? Number(framework.max_points) : null
  frameworkForm.price_weight = framework.price_weight !== null ? Number(framework.price_weight) : null
  frameworkForm.preference_weight = framework.preference_weight !== null ? Number(framework.preference_weight) : null
  frameworkForm.notes = framework.notes ?? ''
  frameworkForm.is_active = !!framework.is_active

  // Never carried over. Re-confirming has to be a fresh decision about the numbers now on screen.
  frameworkForm.confirm_verified = false

  frameworkForm.bands = (framework.bands ?? []).map((b: any) => ({
    label: b.label,
    match_field: b.match_field,
    match_value: b.match_value,
    points: b.points !== null ? Number(b.points) : 0,
    margin_pct: b.margin_pct !== null ? Number(b.margin_pct) : null,
  }))

  editingFrameworkOpen.value = true
}

function addBand() {
  frameworkForm.bands.push({
    label: '',
    match_field: 'bbbee_level',
    match_value: '',
    points: 0,
    margin_pct: null,
  })
}

function saveFramework() {
  frameworkForm.post('/procurement-governance/settings/preference-frameworks', {
    preserveScroll: true,
    onSuccess: () => { editingFrameworkOpen.value = false },
  })
}

function retireFramework() {
  const framework = retiringFramework.value
  if (!framework) return

  router.delete(`/procurement-governance/settings/preference-frameworks/${framework.id}`, {
    preserveScroll: true,
    onFinish: () => { retiringFramework.value = null },
  })
}

const ruleSetForm = useForm({
  code: '', name: '', description: '', framework_label: '',
  applies_to: 'any', effective_from: '', effective_to: '',
})

const ruleForm = useForm({
  rule_set_id: null as number | null,
  code: '', name: '',
  rule_type: 'REQUIRED_FIELD',
  severity: 'FAIL',
  conditions: { min_value: null as number | null, max_value: null as number | null, categories: [] as string[] },
  parameters: {} as Record<string, any>,
  message: '',
  remedy: '',
  sort_order: 0,
})

/** The chain currently open in the dialog for editing; null means the dialog is creating a new one. */
const editingChain = ref<any | null>(null)

/** The chain awaiting confirmation to retire. Retiring changes how future decisions route. */
const retiringChain = ref<any | null>(null)

const chainForm = useForm({
  code: '', name: '', description: '',
  applies_to: 'deviation',
  conditions: { min_value: null as number | null, max_value: null as number | null },
  is_default: false,
  steps: [{ name: '', approver_type: 'role', approver_ref: '', approver_id: null, quorum: 1, sla_hours: 120 }] as any[],
})

const monthNames = computed(() => Array.from({ length: 12 }, (_, i) => ({
  title: new Date(2000, i, 1).toLocaleString(undefined, { month: 'long' }),
  value: i + 1,
})))

const stepsFor = (chainId: number) => (props.chainSteps ?? []).filter(s => s.chain_id === chainId)
const rulesFor = (setId: number) => (props.rules ?? []).filter(r => r.rule_set_id === setId)

/** Each rule type needs different parameters; showing all of them would be unusable. */
const parameterFields = computed(() => ({
  REQUIRED_DOCUMENT: ['category_codes', 'min_count', 'must_be_current'],
  REQUIRED_FIELD: ['fields'],
  REQUIRED_DECLARATION: ['declaration'],
  REQUIRED_APPROVAL: ['chain_code', 'min_steps'],
  FINANCIAL_THRESHOLD: ['required_methods', 'min_quotes'],
  TIME_LIMIT: ['from_field', 'to_field', 'max_days', 'min_days'],
  BUDGET_AVAILABILITY: ['require_budget', 'tolerance_pct'],
  SUPPLIER_COMPLIANCE: ['require_active', 'max_bbbee_level'],
  MANDATORY_JUSTIFICATION: ['fields', 'min_length'],
  CUSTOM: ['expression'],
}[ruleForm.rule_type] ?? []))

function saveSettings() {
  settingsForm.post('/procurement-governance/settings', { preserveScroll: true })
}

function saveRuleSet() {
  ruleSetForm.post('/procurement-governance/settings/rule-sets', {
    preserveScroll: true,
    onSuccess: () => { addingRuleSet.value = false; ruleSetForm.reset() },
  })
}

function saveRule() {
  ruleForm.post('/procurement-governance/settings/rules', {
    preserveScroll: true,
    onSuccess: () => { addingRule.value = false; ruleForm.reset() },
  })
}

function saveChain() {
  chainForm.post('/procurement-governance/settings/chains', {
    preserveScroll: true,
    onSuccess: () => { addingChain.value = false; chainForm.reset(); editingChain.value = null },
  })
}

function blankStep() {
  return { name: '', approver_type: 'role', approver_ref: '', approver_id: null, quorum: 1, sla_hours: 120 }
}

function addStep() {
  chainForm.steps.push(blankStep())
}

function removeStep(i: number) {
  // A chain with no steps would approve nothing, so the last one stays. The button is disabled at
  // that point too — this is the guard, not the message.
  if (chainForm.steps.length > 1) chainForm.steps.splice(i, 1)
}

/** Reorder in place: step order is the order of approval, so this is a business change, not a view. */
function moveStep(i: number, delta: number) {
  const target = i + delta
  if (target < 0 || target >= chainForm.steps.length) return
  const [step] = chainForm.steps.splice(i, 1)
  chainForm.steps.splice(target, 0, step)
}

function newChain() {
  editingChain.value = null
  chainForm.reset()
  chainForm.clearErrors()
  chainForm.steps = [blankStep()]
  addingChain.value = true
}

/**
 * Load an existing chain into the same form.
 *
 * Saving posts the same code back, and the server updates the chain of that code rather than
 * creating a second one — so create and edit are genuinely one flow rather than two that can drift.
 */
function editChain(chain: any) {
  editingChain.value = chain
  chainForm.clearErrors()
  chainForm.code = chain.code
  chainForm.name = chain.name
  chainForm.description = chain.description ?? ''
  chainForm.applies_to = chain.applies_to
  chainForm.conditions = {
    min_value: chain.conditions?.min_value ?? null,
    max_value: chain.conditions?.max_value ?? null,
  }
  chainForm.is_default = !!chain.is_default
  chainForm.steps = stepsFor(chain.id).map(s => ({
    name: s.name,
    approver_type: s.approver_type,
    approver_ref: s.approver_ref ?? '',
    approver_id: s.approver_id ?? null,
    quorum: s.quorum ?? 1,
    sla_hours: s.sla_hours ?? 120,
  }))

  if (!chainForm.steps.length) chainForm.steps = [blankStep()]

  addingChain.value = true
}

function retireChain() {
  const chain = retiringChain.value
  if (!chain) return

  router.delete(`/procurement-governance/settings/chains/${chain.id}`, {
    preserveScroll: true,
    onFinish: () => { retiringChain.value = null },
  })
}

/**
 * A named person cannot produce more than one signature, so asking for several is a step no request
 * can ever clear. The server refuses it; this stops it being typed in the first place.
 */
function quorumCeiling(step: any) {
  return step.approver_type === 'user' ? 1 : 50
}

/**
 * Changing who approves clears who was chosen — a role name left behind on a step that now approves
 * by named person is a stale value the server would reject, pointing at a field the user can no
 * longer see.
 *
 * Takes the new type as an argument rather than reading it back off the step: this runs alongside
 * v-model's own handler, and depending on which of the two fires first is how a one-render-late bug
 * gets written.
 */
function onApproverTypeChange(step: any, type: string) {
  step.approver_ref = ''
  step.approver_id = null
  if (type === 'user') step.quorum = 1
}

function freezeSet(id: number) {
  router.post(`/procurement-governance/settings/rule-sets/${id}/freeze`, {}, { preserveScroll: true })
}

function deactivateRule(id: number) {
  router.delete(`/procurement-governance/settings/rules/${id}`, { preserveScroll: true })
}

/** Parameters are captured as text and parsed as JSON where the field expects a list or object. */
function setParameter(field: string, value: string) {
  const listFields = ['category_codes', 'fields', 'required_methods']

  if (listFields.includes(field)) {
    ruleForm.parameters[field] = value.split(',').map(v => v.trim()).filter(Boolean)
    return
  }

  if (field === 'expression') {
    try {
      ruleForm.parameters[field] = JSON.parse(value)
    } catch {
      ruleForm.parameters[field] = value
    }
    return
  }

  ruleForm.parameters[field] = value === '' ? null : (Number.isNaN(Number(value)) ? value : Number(value))
}
</script>

<template>
  <div class="pa-4">
    <div class="mb-5">
      <h1 class="text-h5 font-weight-bold">{{ t('procurement_governance.settings.title') }}</h1>
      <p class="text-body-2 text-medium-emphasis mb-0">{{ t('procurement_governance.settings.subtitle') }}</p>
    </div>

    <NoCompanyNotice :show="!!noCompany" />

    <template v-if="!noCompany">
      <VTabs v-model="tab" class="mb-4" density="comfortable" show-arrows>
        <VTab value="capabilities">{{ t('procurement_governance.settings.tabs.capabilities') }}</VTab>
        <VTab value="thresholds">{{ t('procurement_governance.settings.tabs.thresholds') }}</VTab>
        <VTab value="preference">
          {{ t('procurement_governance.settings.tabs.preference') }}
          <VBadge v-if="unverifiedFrameworks" color="warning" :content="unverifiedFrameworks" inline />
        </VTab>
        <VTab value="rules">{{ t('procurement_governance.settings.tabs.rules') }}</VTab>
        <VTab value="chains">{{ t('procurement_governance.settings.tabs.chains') }}</VTab>
        <VTab value="notifications">{{ t('procurement_governance.settings.tabs.notifications') }}</VTab>
        <VTab value="documents">{{ t('procurement_governance.settings.tabs.documents') }}</VTab>
      </VTabs>

      <VWindow v-model="tab">
        <!-- §12 module activation -->
        <VWindowItem value="capabilities">
          <VCard elevation="0" border rounded="lg" class="mb-4">
            <VCardTitle class="text-body-1 font-weight-bold py-3">
              {{ t('procurement_governance.settings.capabilities_title') }}
            </VCardTitle>
            <VCardSubtitle class="text-caption pb-2">
              {{ t('procurement_governance.settings.capabilities_hint') }}
            </VCardSubtitle>
            <VCardText class="pt-0">
              <VRow dense>
                <VCol v-for="capability in capabilities" :key="capability" cols="12" md="6">
                  <VSwitch
                    v-model="(settingsForm as any)[`cap_${capability}`]"
                    :label="t(`procurement_governance.settings.capability.${capability}`)"
                    :hint="t(`procurement_governance.settings.capability.${capability}_hint`)"
                    persistent-hint density="comfortable" color="primary"
                  />
                </VCol>
              </VRow>
            </VCardText>
          </VCard>

          <VCard elevation="0" border rounded="lg" class="mb-4">
            <VCardTitle class="text-body-1 font-weight-bold py-3">
              {{ t('procurement_governance.settings.references') }}
            </VCardTitle>
            <VCardText class="pt-0">
              <VRow dense>
                <VCol v-for="field in ['record_prefix', 'review_prefix', 'plan_prefix', 'deviation_prefix', 'committee_prefix']"
                      :key="field" cols="6" md="2">
                  <VTextField
                    v-model="(settingsForm as any)[field]"
                    :label="t(`procurement_governance.settings.${field}`)"
                    maxlength="12" variant="outlined" density="comfortable"
                  />
                </VCol>
                <VCol cols="6" md="2">
                  <VTextField
                    v-model="settingsForm.currency" :label="t('procurement_governance.common.currency')"
                    maxlength="3" variant="outlined" density="comfortable"
                  />
                </VCol>
              </VRow>
            </VCardText>
          </VCard>

          <VBtn color="primary" :loading="settingsForm.processing" @click="saveSettings">
            {{ t('procurement_governance.common.save') }}
          </VBtn>
        </VWindowItem>

        <!-- Thresholds — every number a regulation would fix -->
        <VWindowItem value="thresholds">
          <VCard elevation="0" border rounded="lg" class="mb-4">
            <VCardTitle class="text-body-1 font-weight-bold py-3">
              {{ t('procurement_governance.settings.thresholds_title') }}
            </VCardTitle>
            <VCardText class="pt-0">
              <VRow dense>
                <VCol cols="12" md="4">
                  <VSelect
                    v-model="settingsForm.financial_year_start_month" :items="monthNames"
                    :label="t('procurement_governance.settings.fy_start')"
                    :hint="t('procurement_governance.settings.fy_start_hint')" persistent-hint
                    variant="outlined" density="comfortable"
                  />
                </VCol>
                <VCol cols="12" md="4">
                  <VTextField
                    v-model.number="settingsForm.budget_warning_threshold_pct" type="number" min="1" max="100"
                    :label="t('procurement_governance.settings.budget_warning')"
                    :hint="t('procurement_governance.settings.budget_warning_hint')" persistent-hint
                    variant="outlined" density="comfortable"
                  />
                </VCol>
                <VCol cols="12" md="4">
                  <VTextField
                    v-model.number="settingsForm.moderation_variance_threshold" type="number" min="1" max="100"
                    :label="t('procurement_governance.settings.variance')"
                    :hint="t('procurement_governance.settings.variance_hint')" persistent-hint
                    variant="outlined" density="comfortable"
                  />
                </VCol>
                <VCol cols="12" md="4">
                  <VTextField
                    v-model.number="settingsForm.workload_high_threshold" type="number" min="1"
                    :label="t('procurement_governance.settings.workload_high')"
                    variant="outlined" density="comfortable"
                  />
                </VCol>
                <VCol cols="12" md="4">
                  <VTextField
                    v-model.number="settingsForm.workload_overload_threshold" type="number" min="1"
                    :label="t('procurement_governance.settings.workload_overloaded')"
                    :error-messages="settingsForm.workload_overload_threshold <= settingsForm.workload_high_threshold
                      ? [t('procurement_governance.settings.workload_order')] : []"
                    variant="outlined" density="comfortable"
                  />
                </VCol>
                <VCol cols="12" md="4">
                  <VTextField
                    v-model.number="settingsForm.escalation_days" type="number" min="1" max="365"
                    :label="t('procurement_governance.settings.escalation_days')"
                    :hint="t('procurement_governance.settings.escalation_days_hint')" persistent-hint
                    variant="outlined" density="comfortable"
                  />
                </VCol>
                <VCol cols="12" md="4">
                  <VTextField
                    v-model.number="settingsForm.evaluation_sla_days" type="number" min="1" max="365"
                    :label="t('procurement_governance.settings.evaluation_sla')"
                    variant="outlined" density="comfortable"
                  />
                </VCol>
              </VRow>

              <VDivider class="my-4" />

              <VSwitch
                v-model="settingsForm.require_coi_declaration"
                :label="t('procurement_governance.settings.require_coi')"
                :hint="t('procurement_governance.settings.require_coi_hint')" persistent-hint
                density="comfortable" color="primary"
              />
              <VSwitch
                v-model="settingsForm.allow_evaluation_reopen"
                :label="t('procurement_governance.settings.allow_reopen')"
                :hint="t('procurement_governance.settings.allow_reopen_hint')" persistent-hint
                density="comfortable" color="primary"
              />
              <VSwitch
                v-model="settingsForm.block_plan_over_budget"
                :label="t('procurement_governance.settings.block_over_budget')"
                :hint="t('procurement_governance.settings.block_over_budget_hint')" persistent-hint
                density="comfortable" color="primary"
              />
            </VCardText>
          </VCard>

          <VBtn color="primary" :loading="settingsForm.processing" @click="saveSettings">
            {{ t('procurement_governance.common.save') }}
          </VBtn>
        </VWindowItem>

        <!-- §1.6 jurisdiction, currency conversion and preference frameworks -->
        <VWindowItem value="preference">
          <VAlert type="info" variant="tonal" class="mb-4 text-body-2" icon="mdi-scale-balance">
            {{ t('procurement_governance.settings.preference_explainer') }}
          </VAlert>

          <VCard elevation="0" border rounded="lg" class="mb-4">
            <VCardTitle class="text-body-1 font-weight-bold py-3">
              {{ t('procurement_governance.settings.jurisdiction_title') }}
            </VCardTitle>
            <VCardText class="pt-0">
              <VRow dense>
                <VCol cols="12" md="4">
                  <VSelect
                    v-model="settingsForm.jurisdiction"
                    :items="(jurisdictions ?? []).map(j => ({ title: `${j.name} (${j.currency})`, value: j.code }))"
                    :label="t('procurement_governance.settings.jurisdiction')"
                    :hint="t('procurement_governance.settings.jurisdiction_hint')" persistent-hint
                    variant="outlined" density="comfortable"
                  />
                </VCol>
                <VCol cols="12" md="4">
                  <VSelect
                    v-model="settingsForm.fx_rate_basis"
                    :items="(fxRateBases ?? []).map(b => ({ title: t(`procurement_governance.settings.fx_basis_${b.toLowerCase()}`), value: b }))"
                    :label="t('procurement_governance.settings.fx_basis')"
                    :hint="t('procurement_governance.settings.fx_basis_hint')" persistent-hint
                    variant="outlined" density="comfortable"
                  />
                </VCol>
                <VCol cols="12" md="4">
                  <VTextField
                    v-model="settingsForm.fx_rate_type"
                    :label="t('procurement_governance.settings.fx_rate_type')"
                    :hint="t('procurement_governance.settings.fx_rate_type_hint')" persistent-hint
                    variant="outlined" density="comfortable"
                  />
                </VCol>
              </VRow>

              <VAlert
                v-if="settingsForm.fx_rate_basis === 'SUBMISSION'"
                type="warning" variant="tonal" density="compact" class="mt-4 text-body-2"
              >
                {{ t('procurement_governance.settings.fx_basis_submission_warning') }}
              </VAlert>
            </VCardText>
          </VCard>

          <VBtn color="primary" class="mb-6" :loading="settingsForm.processing" @click="saveSettings">
            {{ t('procurement_governance.common.save') }}
          </VBtn>

          <div class="d-flex align-center justify-space-between flex-wrap ga-2 mb-3">
            <span class="text-caption text-medium-emphasis text-uppercase font-weight-medium">
              {{ t('procurement_governance.settings.frameworks') }}
            </span>
            <VBtn
              v-if="can?.configure" color="primary" variant="tonal" size="small" prepend-icon="mdi-plus"
              @click="newFramework"
            >
              {{ t('procurement_governance.settings.new_framework') }}
            </VBtn>
          </div>

          <div v-if="!preferenceFrameworks?.length" class="text-center py-10 text-medium-emphasis text-body-2">
            {{ t('procurement_governance.settings.no_frameworks') }}
          </div>

          <VCard
            v-for="framework in preferenceFrameworks" :key="framework.code"
            elevation="0" border rounded="lg" class="mb-4"
          >
            <VCardTitle class="d-flex align-center justify-space-between flex-wrap ga-2 py-3">
              <div class="min-width-0">
                <div class="text-body-1 font-weight-bold">{{ framework.name }}</div>
                <div class="text-caption text-medium-emphasis">
                  {{ framework.jurisdiction }} · {{ framework.code }} · {{ humanise(framework.basis) }}
                  <template v-if="framework.price_weight">
                    · {{ Number(framework.price_weight) }}/{{ Number(framework.preference_weight) }}
                  </template>
                </div>
              </div>
              <div class="d-flex align-center ga-1 flex-wrap">
                <VChip
                  :color="framework.is_verified ? 'success' : 'warning'"
                  size="small" variant="tonal" label
                  :prepend-icon="framework.is_verified ? 'mdi-check-decagram' : 'mdi-alert-outline'"
                >
                  {{ framework.is_verified
                    ? t('procurement_governance.settings.framework_verified')
                    : t('procurement_governance.settings.framework_unverified') }}
                </VChip>
                <template v-if="can?.configure">
                  <VBtn
                    class="procgov-row-action" size="small" variant="text"
                    :aria-label="t('procurement_governance.settings.edit_framework_named', { name: framework.name })"
                    @click="editFramework(framework)"
                  >
                    <VIcon icon="mdi-pencil-outline" />
                    <VTooltip activator="parent" location="top">
                      {{ t('procurement_governance.settings.edit_framework') }}
                    </VTooltip>
                  </VBtn>
                  <VBtn
                    class="procgov-row-action" size="small" variant="text" color="error"
                    :aria-label="t('procurement_governance.settings.retire_framework_named', { name: framework.name })"
                    @click="retiringFramework = framework"
                  >
                    <VIcon icon="mdi-archive-arrow-down-outline" />
                    <VTooltip activator="parent" location="top">
                      {{ t('procurement_governance.settings.retire_framework') }}
                    </VTooltip>
                  </VBtn>
                </template>
              </div>
            </VCardTitle>
            <VCardText class="pt-0">
              <!-- The notes carry the caveat the seeder wrote: what still needs confirming, and by
                   whom. Hiding it behind an edit screen would defeat the purpose of shipping the
                   framework unverified in the first place. -->
              <VAlert
                v-if="framework.notes && !framework.is_verified"
                type="warning" variant="tonal" density="compact" class="mb-3 text-body-2"
              >
                {{ framework.notes }}
              </VAlert>

              <div v-if="!framework.bands?.length" class="text-body-2 text-medium-emphasis">
                {{ t('procurement_governance.settings.framework_no_bands') }}
              </div>

              <VTable v-else density="compact" class="text-body-2">
                <thead>
                  <tr>
                    <th>{{ t('procurement_governance.settings.band') }}</th>
                    <th>{{ t('procurement_governance.settings.band_match') }}</th>
                    <th class="text-end">{{ t('procurement_governance.settings.band_points') }}</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="band in framework.bands" :key="band.id">
                    <td>{{ band.label }}</td>
                    <td class="text-medium-emphasis">{{ band.match_field }} = {{ band.match_value }}</td>
                    <td class="text-end font-weight-medium">{{ Number(band.points) }}</td>
                  </tr>
                </tbody>
              </VTable>
            </VCardText>
          </VCard>
        </VWindowItem>

        <!-- §5.3 the rules engine -->
        <VWindowItem value="rules">
          <VAlert type="info" variant="tonal" class="mb-4 text-body-2" icon="mdi-gavel">
            {{ t('procurement_governance.settings.rules_explainer') }}
          </VAlert>

          <div class="d-flex ga-2 mb-4">
            <VBtn color="primary" variant="tonal" prepend-icon="mdi-plus" @click="addingRuleSet = true">
              {{ t('procurement_governance.settings.new_rule_set') }}
            </VBtn>
            <VBtn
              variant="tonal" prepend-icon="mdi-playlist-plus"
              :disabled="!ruleSets?.length" @click="addingRule = true"
            >
              {{ t('procurement_governance.settings.new_rule') }}
            </VBtn>
          </div>

          <div v-if="!ruleSets?.length" class="text-center py-10 text-medium-emphasis">
            <VIcon icon="mdi-scale-balance" size="32" class="mb-2" />
            <div class="text-body-2">{{ t('procurement_governance.settings.no_rule_sets') }}</div>
          </div>

          <VCard
            v-for="set in ruleSets" :key="set.id"
            elevation="0" border rounded="lg" class="mb-4"
          >
            <VCardTitle class="d-flex align-center justify-space-between flex-wrap ga-2 py-3">
              <div class="min-width-0">
                <div class="text-body-1 font-weight-bold">
                  {{ set.name }}
                  <span class="text-caption text-medium-emphasis">{{ set.code }} v{{ set.version }}</span>
                </div>
                <div class="text-caption text-medium-emphasis">
                  {{ t('procurement_governance.settings.applies_to') }}: {{ humanise(set.applies_to) }}
                  <template v-if="set.framework_label"> · {{ set.framework_label }}</template>
                </div>
              </div>
              <div class="d-flex align-center ga-2">
                <VChip v-if="set.is_frozen" size="small" color="blue-grey" variant="tonal" label>
                  <VIcon icon="mdi-lock-outline" start size="14" />
                  {{ t('procurement_governance.settings.frozen') }}
                </VChip>
                <VBtn
                  v-else size="small" variant="text" prepend-icon="mdi-lock-outline"
                  @click="freezeSet(set.id)"
                >
                  {{ t('procurement_governance.settings.freeze') }}
                </VBtn>
              </div>
            </VCardTitle>

            <div v-if="!rulesFor(set.id).length" class="px-4 pb-4 text-body-2 text-medium-emphasis">
              {{ t('procurement_governance.settings.no_rules_in_set') }}
            </div>

            <div v-else class="procgov-scroll">
              <VTable density="compact">
                <thead>
                  <tr>
                    <th scope="col">{{ t('procurement_governance.settings.rule') }}</th>
                    <th scope="col">{{ t('procurement_governance.settings.rule_type') }}</th>
                    <th scope="col">{{ t('procurement_governance.settings.severity') }}</th>
                    <th scope="col" class="d-none d-md-table-cell">{{ t('procurement_governance.settings.applies_when') }}</th>
                    <th scope="col" class="text-end">{{ t('procurement_governance.common.actions') }}</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="rule in rulesFor(set.id)" :key="rule.id" :class="{ 'text-medium-emphasis': !rule.is_active }">
                    <td>
                      <div class="text-body-2">{{ rule.name }}</div>
                      <div v-if="rule.message" class="text-caption text-medium-emphasis">{{ rule.message }}</div>
                    </td>
                    <td class="text-body-2">{{ humanise(rule.rule_type) }}</td>
                    <td><StatusChip :status="rule.severity" size="x-small" /></td>
                    <td class="d-none d-md-table-cell text-caption">
                      <template v-if="rule.conditions?.min_value || rule.conditions?.max_value">
                        {{ rule.conditions.min_value ?? 0 }} – {{ rule.conditions.max_value ?? '∞' }}
                      </template>
                      <template v-else-if="rule.conditions?.categories?.length">
                        {{ rule.conditions.categories.join(', ') }}
                      </template>
                      <template v-else>{{ t('procurement_governance.settings.always') }}</template>
                    </td>
                    <td class="text-end">
                      <VBtn
                        v-if="rule.is_active && !set.is_frozen"
                        size="x-small" variant="text" color="error" @click="deactivateRule(rule.id)"
                      >
                        {{ t('procurement_governance.settings.deactivate') }}
                      </VBtn>
                    </td>
                  </tr>
                </tbody>
              </VTable>
            </div>
          </VCard>
        </VWindowItem>

        <!-- §5.2 approval chains -->
        <VWindowItem value="chains">
          <VAlert type="info" variant="tonal" class="mb-4 text-body-2" icon="mdi-account-supervisor-outline">
            {{ t('procurement_governance.settings.chains_explainer') }}
          </VAlert>

          <VBtn color="primary" variant="tonal" prepend-icon="mdi-plus" class="mb-4" @click="newChain">
            {{ t('procurement_governance.settings.new_chain') }}
          </VBtn>

          <VCard v-for="chain in chains" :key="chain.id" elevation="0" border rounded="lg" class="mb-4">
            <VCardTitle class="d-flex align-center justify-space-between flex-wrap ga-2 py-3">
              <div class="min-width-0">
                <div class="text-body-1 font-weight-bold">{{ chain.name }}</div>
                <div class="text-caption text-medium-emphasis">
                  {{ humanise(chain.applies_to) }} · {{ chain.code }}
                  <template v-if="chain.conditions?.min_value || chain.conditions?.max_value">
                    · {{ chain.conditions.min_value ?? 0 }} – {{ chain.conditions.max_value ?? '∞' }}
                  </template>
                </div>
              </div>
              <div class="d-flex align-center ga-1 flex-wrap">
                <!-- Editing a chain that has decisions travelling through it changes the route those
                     decisions are on, so the count is stated before the edit, not after. -->
                <VChip
                  v-if="chain.pending_requests" size="small" variant="tonal" color="warning" label
                  prepend-icon="mdi-timer-sand"
                >
                  {{ t('procurement_governance.settings.chain_in_flight', { n: chain.pending_requests }) }}
                </VChip>
                <VChip v-if="chain.is_default" size="small" variant="tonal" label>
                  {{ t('procurement_governance.settings.default') }}
                </VChip>
                <template v-if="can?.configure">
                  <VBtn
                    class="procgov-row-action" size="small" variant="text" icon="mdi-pencil-outline"
                    :aria-label="t('procurement_governance.settings.edit_chain_named', { name: chain.name })"
                    @click="editChain(chain)"
                  >
                    <VIcon icon="mdi-pencil-outline" />
                    <VTooltip activator="parent" location="top">
                      {{ t('procurement_governance.settings.edit_chain') }}
                    </VTooltip>
                  </VBtn>
                  <VBtn
                    class="procgov-row-action" size="small" variant="text" color="error"
                    :aria-label="t('procurement_governance.settings.retire_chain_named', { name: chain.name })"
                    @click="retiringChain = chain"
                  >
                    <VIcon icon="mdi-archive-arrow-down-outline" />
                    <VTooltip activator="parent" location="top">
                      {{ t('procurement_governance.settings.retire_chain') }}
                    </VTooltip>
                  </VBtn>
                </template>
              </div>
            </VCardTitle>
            <VCardText class="pt-0">
              <VTimeline density="compact" side="end" truncate-line="both">
                <VTimelineItem
                  v-for="step in stepsFor(chain.id)" :key="step.id"
                  dot-color="primary" size="x-small"
                >
                  <div class="text-body-2 font-weight-medium">{{ step.step_no }}. {{ step.name }}</div>
                  <div class="text-caption text-medium-emphasis">
                    {{ humanise(step.approver_type) }}
                    <template v-if="step.approver_ref"> · {{ step.approver_ref }}</template>
                    <template v-if="step.quorum > 1">
                      · {{ t('procurement_governance.settings.quorum_n', { n: step.quorum }) }}
                    </template>
                    <template v-if="step.sla_hours">
                      · {{ t('procurement_governance.settings.sla_hours', { n: step.sla_hours }) }}
                    </template>
                  </div>
                </VTimelineItem>
              </VTimeline>
            </VCardText>
          </VCard>
        </VWindowItem>

        <!-- §9 notification rules -->
        <VWindowItem value="notifications">
          <VCard elevation="0" border rounded="lg">
            <VCardTitle class="text-body-1 font-weight-bold py-3">
              {{ t('procurement_governance.settings.notification_rules') }}
            </VCardTitle>
            <div v-if="!notificationRules?.length" class="text-center py-10 text-medium-emphasis text-body-2">
              {{ t('procurement_governance.settings.no_notification_rules') }}
            </div>
            <div v-else class="procgov-scroll">
              <VTable density="compact">
                <thead>
                  <tr>
                    <th scope="col">{{ t('procurement_governance.settings.event') }}</th>
                    <th scope="col">{{ t('procurement_governance.common.name') }}</th>
                    <th scope="col">{{ t('procurement_governance.settings.channels') }}</th>
                    <th scope="col" class="text-end">{{ t('procurement_governance.settings.lead_days') }}</th>
                    <th scope="col" class="text-end">{{ t('procurement_governance.settings.repeat') }}</th>
                    <th scope="col">{{ t('procurement_governance.common.status') }}</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="rule in notificationRules" :key="rule.id">
                    <td class="text-body-2">{{ humanise(rule.event) }}</td>
                    <td class="text-body-2">{{ rule.name }}</td>
                    <td class="text-caption">{{ (rule.channels ?? []).join(', ') || '—' }}</td>
                    <td class="text-end text-body-2">{{ rule.lead_days ?? 0 }}</td>
                    <td class="text-end text-body-2">{{ rule.repeat_every_days ?? '—' }}</td>
                    <td>
                      <StatusChip
                        :status="rule.is_active ? 'ACTIVE' : 'SUSPENDED'"
                        :label="rule.is_active
                          ? t('procurement_governance.settings.on')
                          : t('procurement_governance.settings.off')"
                        size="x-small"
                      />
                    </td>
                  </tr>
                </tbody>
              </VTable>
            </div>
          </VCard>
        </VWindowItem>

        <!-- §8 document categories -->
        <VWindowItem value="documents">
          <VCard elevation="0" border rounded="lg">
            <VCardTitle class="text-body-1 font-weight-bold py-3">
              {{ t('procurement_governance.settings.document_categories') }}
            </VCardTitle>
            <div v-if="!documentCategories?.length" class="text-center py-10 text-medium-emphasis text-body-2">
              {{ t('procurement_governance.settings.no_categories') }}
            </div>
            <div v-else class="procgov-scroll">
              <VTable density="compact">
                <thead>
                  <tr>
                    <th scope="col">{{ t('procurement_governance.settings.code') }}</th>
                    <th scope="col">{{ t('procurement_governance.common.name') }}</th>
                    <th scope="col">{{ t('procurement_governance.documents.confidentiality') }}</th>
                    <th scope="col">{{ t('procurement_governance.settings.requires_expiry') }}</th>
                    <th scope="col">{{ t('procurement_governance.settings.requires_ack') }}</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="category in documentCategories" :key="category.id">
                    <td class="text-caption font-weight-medium">{{ category.code }}</td>
                    <td class="text-body-2">{{ category.name }}</td>
                    <td><StatusChip :status="category.default_confidentiality" size="x-small" /></td>
                    <td>
                      <VIcon
                        :icon="category.requires_expiry ? 'mdi-check' : 'mdi-minus'"
                        :color="category.requires_expiry ? 'success' : 'grey'" size="18"
                      />
                    </td>
                    <td>
                      <VIcon
                        :icon="category.requires_acknowledgement ? 'mdi-check' : 'mdi-minus'"
                        :color="category.requires_acknowledgement ? 'success' : 'grey'" size="18"
                      />
                    </td>
                  </tr>
                </tbody>
              </VTable>
            </div>
          </VCard>
        </VWindowItem>
      </VWindow>
    </template>

    <!-- ── Dialogs ─────────────────────────────────────────────────────────────────────────── -->
    <VDialog v-model="addingRuleSet" max-width="560">
      <VCard>
        <VCardTitle class="text-body-1 font-weight-bold">{{ t('procurement_governance.settings.new_rule_set') }}</VCardTitle>
        <VCardText>
          <VRow dense>
            <VCol cols="12" md="4">
              <VTextField
                v-model="ruleSetForm.code" :label="t('procurement_governance.settings.code')"
                :error-messages="ruleSetForm.errors.code" variant="outlined" density="comfortable"
              />
            </VCol>
            <VCol cols="12" md="8">
              <VTextField
                v-model="ruleSetForm.name" :label="t('procurement_governance.common.name')"
                :error-messages="ruleSetForm.errors.name" variant="outlined" density="comfortable"
              />
            </VCol>
            <VCol cols="12" md="6">
              <VSelect
                v-model="ruleSetForm.applies_to"
                :items="(appliesTo ?? []).map(a => ({ title: humanise(a), value: a }))"
                :label="t('procurement_governance.settings.applies_to')"
                variant="outlined" density="comfortable"
              />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField
                v-model="ruleSetForm.framework_label"
                :label="t('procurement_governance.settings.framework')"
                :hint="t('procurement_governance.settings.framework_hint')" persistent-hint
                variant="outlined" density="comfortable"
              />
            </VCol>
            <VCol cols="12">
              <VTextarea
                v-model="ruleSetForm.description" :label="t('procurement_governance.common.description')"
                variant="outlined" rows="2" auto-grow
              />
            </VCol>
          </VRow>
        </VCardText>
        <VCardActions class="px-4 pb-4">
          <VSpacer />
          <VBtn variant="text" @click="addingRuleSet = false">{{ t('procurement_governance.common.cancel') }}</VBtn>
          <VBtn color="primary" :loading="ruleSetForm.processing" @click="saveRuleSet">
            {{ t('procurement_governance.common.save') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <VDialog v-model="addingRule" max-width="640" scrollable>
      <VCard>
        <VCardTitle class="text-body-1 font-weight-bold">{{ t('procurement_governance.settings.new_rule') }}</VCardTitle>
        <VCardText>
          <VRow dense>
            <VCol cols="12">
              <VSelect
                v-model="ruleForm.rule_set_id"
                :items="(ruleSets ?? []).filter(s => !s.is_frozen).map(s => ({
                  title: `${s.name} (${s.code} v${s.version})`, value: s.id,
                }))"
                :label="t('procurement_governance.settings.rule_set')"
                variant="outlined" density="comfortable"
              />
            </VCol>
            <VCol cols="12" md="4">
              <VTextField
                v-model="ruleForm.code" :label="t('procurement_governance.settings.code')"
                variant="outlined" density="comfortable"
              />
            </VCol>
            <VCol cols="12" md="8">
              <VTextField
                v-model="ruleForm.name" :label="t('procurement_governance.common.name')"
                variant="outlined" density="comfortable"
              />
            </VCol>
            <VCol cols="12" md="8">
              <VSelect
                v-model="ruleForm.rule_type"
                :items="(ruleTypes ?? []).map(r => ({
                  title: humanise(r), subtitle: t(`procurement_governance.settings.rule_types.${r}`), value: r,
                }))"
                :label="t('procurement_governance.settings.rule_type')"
                variant="outlined" density="comfortable"
              >
                <template #item="{ props: itemProps, item }">
                  <VListItem v-bind="itemProps" :subtitle="item.raw.subtitle" />
                </template>
              </VSelect>
            </VCol>
            <VCol cols="12" md="4">
              <VSelect
                v-model="ruleForm.severity"
                :items="['FAIL', 'WARNING', 'INFO'].map(s => ({ title: humanise(s), value: s }))"
                :label="t('procurement_governance.settings.severity')"
                :hint="t('procurement_governance.settings.severity_hint')" persistent-hint
                variant="outlined" density="comfortable"
              />
            </VCol>

            <VCol cols="12"><VDivider class="my-2" /></VCol>
            <VCol cols="12">
              <div class="text-caption text-medium-emphasis text-uppercase font-weight-medium mb-2">
                {{ t('procurement_governance.settings.applies_when') }}
              </div>
            </VCol>
            <VCol cols="6" md="4">
              <VTextField
                v-model.number="ruleForm.conditions.min_value" type="number"
                :label="t('procurement_governance.settings.min_value')"
                variant="outlined" density="comfortable"
              />
            </VCol>
            <VCol cols="6" md="4">
              <VTextField
                v-model.number="ruleForm.conditions.max_value" type="number"
                :label="t('procurement_governance.settings.max_value')"
                :hint="t('procurement_governance.settings.max_value_hint')" persistent-hint
                variant="outlined" density="comfortable"
              />
            </VCol>

            <VCol cols="12"><VDivider class="my-2" /></VCol>
            <VCol cols="12">
              <div class="text-caption text-medium-emphasis text-uppercase font-weight-medium mb-2">
                {{ t('procurement_governance.settings.parameters') }}
              </div>
            </VCol>
            <VCol v-for="field in parameterFields" :key="field" cols="12" md="6">
              <VTextField
                :label="t(`procurement_governance.settings.params.${field}`)"
                :hint="t(`procurement_governance.settings.params.${field}_hint`)" persistent-hint
                variant="outlined" density="comfortable"
                @update:model-value="value => setParameter(field, value)"
              />
            </VCol>

            <VCol cols="12">
              <VTextField
                v-model="ruleForm.message" :label="t('procurement_governance.settings.message')"
                :hint="t('procurement_governance.settings.message_hint')" persistent-hint
                variant="outlined" density="comfortable"
              />
            </VCol>
          </VRow>
        </VCardText>
        <VCardActions class="px-4 pb-4">
          <VSpacer />
          <VBtn variant="text" @click="addingRule = false">{{ t('procurement_governance.common.cancel') }}</VBtn>
          <VBtn color="primary" :loading="ruleForm.processing" @click="saveRule">
            {{ t('procurement_governance.common.save') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- A step is six decisions wide, so this dialog is sized for the row it has to hold. At 720 the
         quorum and SLA labels truncated to "Q.." and "S..", which is how a step ends up demanding
         eleven signatures from one person. Full-screen below md, where that row has to stack. -->
    <VDialog v-model="addingChain" max-width="1180" scrollable :fullscreen="$vuetify.display.smAndDown">
      <VCard>
        <VCardTitle class="d-flex align-center justify-space-between flex-wrap ga-2 py-3">
          <span class="text-body-1 font-weight-bold">
            {{ editingChain
              ? t('procurement_governance.settings.edit_chain_named', { name: editingChain.name })
              : t('procurement_governance.settings.new_chain') }}
          </span>
          <VChip v-if="editingChain?.pending_requests" size="small" color="warning" variant="tonal" label>
            {{ t('procurement_governance.settings.chain_edit_warning', { n: editingChain.pending_requests }) }}
          </VChip>
        </VCardTitle>
        <VCardText>
          <FormErrors :errors="chainForm.errors" />

          <VRow dense>
            <VCol cols="12" md="3">
              <VTextField
                v-model="chainForm.code" :label="t('procurement_governance.settings.code')"
                :error-messages="chainForm.errors.code"
                :hint="editingChain
                  ? t('procurement_governance.settings.code_locked_hint')
                  : t('procurement_governance.settings.code_hint')"
                persistent-hint
                :readonly="!!editingChain"
                variant="outlined" density="comfortable"
              />
            </VCol>
            <VCol cols="12" md="5">
              <VTextField
                v-model="chainForm.name" :label="t('procurement_governance.common.name')"
                :error-messages="chainForm.errors.name"
                variant="outlined" density="comfortable"
              />
            </VCol>
            <VCol cols="12" md="4">
              <VSelect
                v-model="chainForm.applies_to"
                :items="(chainAppliesTo ?? []).map(a => ({ title: humanise(a), value: a }))"
                :label="t('procurement_governance.settings.applies_to')"
                :error-messages="chainForm.errors.applies_to"
                variant="outlined" density="comfortable"
              />
            </VCol>
            <VCol cols="12" sm="6">
              <VTextField
                v-model.number="chainForm.conditions.min_value" type="number"
                :label="t('procurement_governance.settings.min_value')"
                variant="outlined" density="comfortable"
              />
            </VCol>
            <VCol cols="12" sm="6">
              <VTextField
                v-model.number="chainForm.conditions.max_value" type="number"
                :label="t('procurement_governance.settings.max_value')"
                :hint="t('procurement_governance.settings.max_value_hint')" persistent-hint
                variant="outlined" density="comfortable"
              />
            </VCol>

            <VCol cols="12"><VDivider class="my-3" /></VCol>
            <VCol cols="12" class="d-flex align-center justify-space-between flex-wrap ga-2 mb-1">
              <div>
                <div class="text-caption text-medium-emphasis text-uppercase font-weight-medium">
                  {{ t('procurement_governance.settings.steps') }}
                </div>
                <div class="text-caption text-medium-emphasis">
                  {{ t('procurement_governance.settings.steps_hint') }}
                </div>
              </div>
              <VBtn size="small" variant="tonal" prepend-icon="mdi-plus" @click="addStep">
                {{ t('procurement_governance.settings.add_step') }}
              </VBtn>
            </VCol>

            <VCol v-for="(step, i) in chainForm.steps" :key="i" cols="12">
              <VCard variant="tonal" class="pa-3 mb-2" rounded="lg">
                <div class="d-flex align-center justify-space-between mb-2">
                  <span class="text-body-2 font-weight-bold">
                    {{ t('procurement_governance.settings.step_n', { n: i + 1 }) }}
                  </span>
                  <div class="d-flex align-center ga-1">
                    <VBtn
                      class="procgov-row-action" size="small" variant="text" :disabled="i === 0"
                      :aria-label="t('procurement_governance.settings.move_step_up', { n: i + 1 })"
                      @click="moveStep(i, -1)"
                    >
                      <VIcon icon="mdi-arrow-up" />
                    </VBtn>
                    <VBtn
                      class="procgov-row-action" size="small" variant="text"
                      :disabled="i === chainForm.steps.length - 1"
                      :aria-label="t('procurement_governance.settings.move_step_down', { n: i + 1 })"
                      @click="moveStep(i, 1)"
                    >
                      <VIcon icon="mdi-arrow-down" />
                    </VBtn>
                    <VBtn
                      class="procgov-row-action" size="small" variant="text" color="error"
                      :disabled="chainForm.steps.length === 1"
                      :aria-label="t('procurement_governance.settings.remove_step', { n: i + 1 })"
                      @click="removeStep(i)"
                    >
                      <VIcon icon="mdi-trash-can-outline" />
                    </VBtn>
                  </div>
                </div>

                <VRow dense>
                  <VCol cols="12" md="3">
                    <VTextField
                      v-model="step.name" :label="t('procurement_governance.settings.step_name')"
                      :error-messages="chainForm.errors[`steps.${i}.name`]"
                      variant="outlined" density="comfortable" hide-details="auto"
                    />
                  </VCol>
                  <VCol cols="12" sm="6" md="3">
                    <VSelect
                      v-model="step.approver_type"
                      :items="(approverTypes ?? []).map(a => ({ title: humanise(a), value: a }))"
                      :label="t('procurement_governance.settings.approver_type')"
                      variant="outlined" density="comfortable" hide-details="auto"
                      @update:model-value="onApproverTypeChange(step, $event)"
                    />
                  </VCol>
                  <VCol cols="12" sm="6" md="3">
                    <VSelect
                      v-if="step.approver_type === 'role'"
                      v-model="step.approver_ref" :items="roles ?? []"
                      :label="t('procurement_governance.settings.role')"
                      :error-messages="chainForm.errors[`steps.${i}.approver_ref`]"
                      variant="outlined" density="comfortable" hide-details="auto"
                    />
                    <VSelect
                      v-else-if="step.approver_type === 'user'"
                      v-model="step.approver_id"
                      :items="(officers ?? []).map(o => ({ title: o.name, value: o.id }))"
                      :label="t('procurement_governance.common.officer')"
                      :error-messages="chainForm.errors[`steps.${i}.approver_id`]"
                      variant="outlined" density="comfortable" hide-details="auto"
                    />
                    <VTextField
                      v-else-if="step.approver_type === 'permission'"
                      v-model="step.approver_ref"
                      :label="t('procurement_governance.settings.permission')"
                      :error-messages="chainForm.errors[`steps.${i}.approver_ref`]"
                      variant="outlined" density="comfortable" hide-details="auto"
                    />
                    <VTextField
                      v-else
                      :model-value="humanise(step.approver_type)"
                      :label="t('procurement_governance.settings.approver_type')"
                      :hint="t('procurement_governance.settings.approver_derived_hint')" persistent-hint
                      variant="outlined" density="comfortable" readonly
                    />
                  </VCol>
                  <VCol cols="6" md="1">
                    <VTextField
                      v-model.number="step.quorum" type="number" min="1" :max="quorumCeiling(step)"
                      :label="t('procurement_governance.settings.quorum')"
                      :error-messages="chainForm.errors[`steps.${i}.quorum`]"
                      :disabled="step.approver_type === 'user'"
                      variant="outlined" density="comfortable" hide-details="auto"
                    />
                  </VCol>
                  <VCol cols="6" md="2">
                    <VTextField
                      v-model.number="step.sla_hours" type="number" min="1"
                      :label="t('procurement_governance.settings.sla')"
                      :error-messages="chainForm.errors[`steps.${i}.sla_hours`]"
                      variant="outlined" density="comfortable" hide-details="auto"
                    />
                  </VCol>
                </VRow>

                <div
                  v-if="step.approver_type === 'user'"
                  class="text-caption text-medium-emphasis mt-2"
                >
                  {{ t('procurement_governance.settings.single_approver_hint') }}
                </div>
              </VCard>
            </VCol>

            <VCol cols="12">
              <VCheckbox
                v-model="chainForm.is_default"
                :label="t('procurement_governance.settings.is_default')"
                :hint="t('procurement_governance.settings.is_default_hint')" persistent-hint
                density="compact"
              />
            </VCol>
          </VRow>
        </VCardText>
        <VCardActions class="px-4 pb-4">
          <VSpacer />
          <VBtn variant="text" @click="addingChain = false">{{ t('procurement_governance.common.cancel') }}</VBtn>
          <VBtn color="primary" :loading="chainForm.processing" @click="saveChain">
            {{ t('procurement_governance.common.save') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- §1.6. The module ships no country's preference statute, so this is where the person who
         holds the current instrument enters it. Sized like the chain editor: a band is four
         decisions wide and cramping them is how a wrong points table gets saved. -->
    <VDialog v-model="editingFrameworkOpen" max-width="1100" scrollable :fullscreen="$vuetify.display.smAndDown">
      <VCard>
        <VCardTitle class="text-body-1 font-weight-bold py-3">
          {{ frameworkForm.code && !creatingFramework
            ? t('procurement_governance.settings.edit_framework_named', { name: frameworkForm.name })
            : t('procurement_governance.settings.new_framework') }}
        </VCardTitle>
        <VCardText>
          <FormErrors :errors="frameworkForm.errors" />

          <VRow dense>
            <VCol cols="12" md="3">
              <VTextField
                v-model="frameworkForm.code" :label="t('procurement_governance.settings.code')"
                :error-messages="frameworkForm.errors.code"
                :readonly="!creatingFramework"
                :hint="creatingFramework
                  ? t('procurement_governance.settings.code_hint')
                  : t('procurement_governance.settings.code_locked_hint')"
                persistent-hint variant="outlined" density="comfortable"
              />
            </VCol>
            <VCol cols="12" md="5">
              <VTextField
                v-model="frameworkForm.name" :label="t('procurement_governance.common.name')"
                :error-messages="frameworkForm.errors.name"
                variant="outlined" density="comfortable"
              />
            </VCol>
            <VCol cols="12" md="4">
              <VSelect
                v-model="frameworkForm.jurisdiction"
                :items="(jurisdictions ?? []).map(j => ({ title: `${j.name} (${j.currency})`, value: j.code }))"
                :label="t('procurement_governance.settings.jurisdiction')"
                :error-messages="frameworkForm.errors.jurisdiction"
                variant="outlined" density="comfortable"
              />
            </VCol>

            <VCol cols="12" md="3">
              <VSelect
                v-model="frameworkForm.basis"
                :items="[
                  { title: t('procurement_governance.settings.basis_points'), value: 'POINTS' },
                  { title: t('procurement_governance.settings.basis_margin'), value: 'MARGIN' },
                ]"
                :label="t('procurement_governance.settings.basis')"
                :hint="t('procurement_governance.settings.basis_hint')" persistent-hint
                variant="outlined" density="comfortable"
              />
            </VCol>
            <VCol cols="6" md="3">
              <VTextField
                v-model.number="frameworkForm.max_points" type="number" min="0" max="100"
                :label="t('procurement_governance.settings.max_points')"
                :error-messages="frameworkForm.errors.max_points"
                :disabled="frameworkForm.basis !== 'POINTS'"
                variant="outlined" density="comfortable"
              />
            </VCol>
            <VCol cols="6" md="3">
              <VTextField
                v-model.number="frameworkForm.price_weight" type="number" min="0" max="100"
                :label="t('procurement_governance.settings.price_weight')"
                :error-messages="frameworkForm.errors.price_weight"
                variant="outlined" density="comfortable"
              />
            </VCol>
            <VCol cols="6" md="3">
              <VTextField
                v-model.number="frameworkForm.preference_weight" type="number" min="0" max="100"
                :label="t('procurement_governance.settings.preference_weight')"
                :hint="t('procurement_governance.settings.split_hint')" persistent-hint
                variant="outlined" density="comfortable"
              />
            </VCol>

            <VCol cols="12">
              <VTextarea
                v-model="frameworkForm.notes"
                :label="t('procurement_governance.settings.framework_notes')"
                :hint="t('procurement_governance.settings.framework_notes_hint')" persistent-hint
                variant="outlined" rows="2" auto-grow
              />
            </VCol>

            <VCol cols="12"><VDivider class="my-3" /></VCol>
            <VCol cols="12" class="d-flex align-center justify-space-between flex-wrap ga-2 mb-1">
              <div>
                <div class="text-caption text-medium-emphasis text-uppercase font-weight-medium">
                  {{ t('procurement_governance.settings.bands') }}
                </div>
                <div class="text-caption text-medium-emphasis">
                  {{ t('procurement_governance.settings.bands_hint') }}
                </div>
              </div>
              <VBtn size="small" variant="tonal" prepend-icon="mdi-plus" @click="addBand">
                {{ t('procurement_governance.settings.add_band') }}
              </VBtn>
            </VCol>

            <VCol v-if="!frameworkForm.bands.length" cols="12">
              <div class="text-body-2 text-medium-emphasis py-4 text-center">
                {{ t('procurement_governance.settings.framework_no_bands') }}
              </div>
            </VCol>

            <VCol v-for="(band, i) in frameworkForm.bands" :key="i" cols="12">
              <VCard variant="tonal" class="pa-3 mb-2" rounded="lg">
                <VRow dense align="center">
                  <VCol cols="12" md="4">
                    <VTextField
                      v-model="band.label" :label="t('procurement_governance.settings.band_label')"
                      :error-messages="frameworkForm.errors[`bands.${i}.label`]"
                      variant="outlined" density="comfortable" hide-details="auto"
                    />
                  </VCol>
                  <VCol cols="12" sm="6" md="3">
                    <VSelect
                      v-model="band.match_field"
                      :items="bandFields"
                      :label="t('procurement_governance.settings.band_field')"
                      variant="outlined" density="comfortable" hide-details="auto"
                    />
                  </VCol>
                  <VCol cols="6" sm="3" md="2">
                    <VTextField
                      v-model="band.match_value"
                      :label="t('procurement_governance.settings.band_value')"
                      :hint="t('procurement_governance.settings.band_value_hint')"
                      :error-messages="frameworkForm.errors[`bands.${i}.match_value`]"
                      variant="outlined" density="comfortable" hide-details="auto"
                    />
                  </VCol>
                  <VCol cols="6" sm="3" md="2">
                    <VTextField
                      v-if="frameworkForm.basis === 'POINTS'"
                      v-model.number="band.points" type="number" min="0" :max="frameworkForm.max_points || 100"
                      :label="t('procurement_governance.settings.band_points')"
                      :error-messages="frameworkForm.errors[`bands.${i}.points`]"
                      variant="outlined" density="comfortable" hide-details="auto"
                    />
                    <VTextField
                      v-else
                      v-model.number="band.margin_pct" type="number" min="0" max="100"
                      :label="t('procurement_governance.settings.band_margin')"
                      variant="outlined" density="comfortable" hide-details="auto"
                    />
                  </VCol>
                  <VCol cols="12" md="1" class="d-flex justify-end">
                    <VBtn
                      class="procgov-row-action" size="small" variant="text" color="error"
                      :aria-label="t('procurement_governance.settings.remove_band', { n: i + 1 })"
                      @click="frameworkForm.bands.splice(i, 1)"
                    >
                      <VIcon icon="mdi-trash-can-outline" />
                    </VBtn>
                  </VCol>
                </VRow>
              </VCard>
            </VCol>

            <VCol cols="12">
              <VDivider class="mb-3" />
              <!-- Confirmation is its own deliberate act, and it is cleared whenever the numbers
                   change. A sign-off attaches to the table that was signed off, not to its name. -->
              <VAlert
                v-if="frameworkForm.confirm_verified" type="success" variant="tonal" density="compact"
                class="mb-3 text-body-2"
              >
                {{ t('procurement_governance.settings.confirm_verified_note') }}
              </VAlert>
              <VCheckbox
                v-model="frameworkForm.confirm_verified"
                :label="t('procurement_governance.settings.confirm_verified')"
                :hint="t('procurement_governance.settings.confirm_verified_hint')" persistent-hint
                density="compact"
              />
            </VCol>
          </VRow>
        </VCardText>
        <VCardActions class="px-4 pb-4">
          <VSpacer />
          <VBtn variant="text" @click="editingFrameworkOpen = false">
            {{ t('procurement_governance.common.cancel') }}
          </VBtn>
          <VBtn color="primary" :loading="frameworkForm.processing" @click="saveFramework">
            {{ t('procurement_governance.common.save') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <VDialog
      :model-value="!!retiringFramework" max-width="520"
      @update:model-value="retiringFramework = null"
    >
      <VCard v-if="retiringFramework">
        <VCardTitle class="text-body-1 font-weight-bold">
          {{ t('procurement_governance.settings.retire_framework_named', { name: retiringFramework.name }) }}
        </VCardTitle>
        <VCardText class="text-body-2">
          {{ t('procurement_governance.settings.retire_framework_body') }}
        </VCardText>
        <VCardActions class="px-4 pb-4">
          <VSpacer />
          <VBtn variant="text" @click="retiringFramework = null">
            {{ t('procurement_governance.common.cancel') }}
          </VBtn>
          <VBtn color="error" variant="tonal" @click="retireFramework">
            {{ t('procurement_governance.settings.retire_framework') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <VDialog :model-value="!!retiringChain" max-width="520" @update:model-value="retiringChain = null">
      <VCard v-if="retiringChain">
        <VCardTitle class="text-body-1 font-weight-bold">
          {{ t('procurement_governance.settings.retire_chain_named', { name: retiringChain.name }) }}
        </VCardTitle>
        <VCardText class="text-body-2">
          {{ t('procurement_governance.settings.retire_chain_body') }}
        </VCardText>
        <VCardActions class="px-4 pb-4">
          <VSpacer />
          <VBtn variant="text" @click="retiringChain = null">
            {{ t('procurement_governance.common.cancel') }}
          </VBtn>
          <VBtn color="error" variant="tonal" @click="retireChain">
            {{ t('procurement_governance.settings.retire_chain') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>

<style scoped>
.procgov-scroll { overflow-x: auto; }
.min-width-0 { min-inline-size: 0; }

/* WCAG 2.2 target size. A 20px row action is comfortable with a mouse and unusable with a thumb,
   so coarse pointers get a real 44px target while desktop keeps the density a register needs. */
@media (pointer: coarse) {
  :deep(.v-btn--size-x-small) { min-block-size: 44px; min-inline-size: 44px; }
  .procgov-row-action { min-block-size: 44px; min-inline-size: 44px; }
}

/* Reorder and remove sit next to each other, so they need to be unmistakably separate targets even
   at desktop density — a mis-click here rewrites who approves what. */
.procgov-row-action { min-block-size: 36px; min-inline-size: 36px; }
</style>
