<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import axios from 'axios'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import AppSelect from '@/@core/components/app-form-elements/AppSelect.vue'
import AppTextField from '@/@core/components/app-form-elements/AppTextField.vue'
import AppTextarea from '@/@core/components/app-form-elements/AppTextarea.vue'

const route = ziggyRoute

type Clause = {
  id: number
  clause_reference: string | null
  type: string
  content: string
  approved: boolean
}

type Rule = {
  id: number
  name: string
  description: string | null
  severity: string
  action_on_breach: string
  clause_id: number | null
  active: boolean
  created_by?: number | null
  clause?: Clause | null
  conditions?: Array<any>
  actions?: Array<any>
}

type Draft = {
  id: number
  natural_language_input: string
  ai_parsed_rule: any
  user_edits: any
  status: string
  clause_id: number | null
  clause?: Clause | null
  createdBy?: { name?: string } | null
}

const props = defineProps<{
  policy: any
  rules: Rule[]
  clauses: Clause[]
  drafts: Draft[]
  severities: string[]
  operators: string[]
  conditionTypes: string[]
  actionTypes: string[]
}>()

const selectedClauseId = ref<number | null>(null)
const openManual = ref(true)
const openAi = ref(true)
const aiBusy = ref(false)
const aiDraftId = ref<number | null>(null)
const aiError = ref('')

const conditionTypeOptions = computed(() => props.conditionTypes.map(value => ({ title: value, value })))
const severityOptions = computed(() => props.severities.map(value => ({ title: value, value })))
const operatorOptions = computed(() => props.operators.map(value => ({ title: value, value })))
const actionTypeOptions = computed(() => props.actionTypes.map(value => ({ title: value, value })))
const clauseOptions = computed(() => (props.clauses ?? []).map(clause => ({
  title: clause.clause_reference ? `${clause.clause_reference} — ${clause.content.slice(0, 60)}` : clause.content.slice(0, 80),
  value: clause.id,
})))

const manualForm = useForm({
  name: '',
  description: '',
  clause_id: null as number | null,
  natural_language_input: '',
  severity: 'medium',
  action_on_breach: 'warn',
  requires_exception_approval: false,
  active: true,
  conditions: [createCondition()],
  actions: [createAction('log_violation')],
  event_maps: [] as Array<any>,
})

const aiForm = useForm({
  name: '',
  description: '',
  clause_id: null as number | null,
  natural_language_input: '',
  severity: 'medium',
  action_on_breach: 'warn',
  requires_exception_approval: false,
  active: true,
  conditions: [createCondition()],
  actions: [createAction('log_violation')],
  event_maps: [] as Array<any>,
})

function breachTone(severity: string, action: string) {
  if ((severity === 'critical' || severity === 'high') && (action === 'warn' || action === 'log_only')) {
    return 'soft'
  }
  if ((severity === 'low' || severity === 'medium') && action === 'block') {
    return 'harsh'
  }
  return 'balanced'
}

function enforcementWarnings(target: typeof manualForm | typeof aiForm): string[] {
  const warnings: string[] = []
  if (!target.name?.trim()) {
    warnings.push('Rule name is missing. This can create unclear violation messages in enforcement logs.')
  }
  if (!target.conditions?.length) {
    warnings.push('No conditions set. The rule may trigger too broadly and cause noisy violations.')
  }
  if (!target.actions?.length) {
    warnings.push('No actions configured. Violations may be detected but not enforced.')
  }
  if (breachTone(target.severity, target.action_on_breach) === 'soft') {
    warnings.push('Severity is high/critical but breach action is soft (warn/log_only). Consider escalate or block.')
  }
  if (breachTone(target.severity, target.action_on_breach) === 'harsh') {
    warnings.push('Severity is low/medium with block action. Consider warn/escalate to reduce false positives.')
  }
  if (target.requires_exception_approval && target.action_on_breach === 'log_only') {
    warnings.push('Exception approval is enabled while breach action is log_only. These controls may conflict.')
  }
  return warnings
}

function enforcementExplanation(target: typeof manualForm | typeof aiForm): string {
  const tone = breachTone(target.severity, target.action_on_breach)
  if (tone === 'soft') {
    return 'Current setup prioritizes user continuity over strict control. This is safer for UX but may under-enforce severe violations.'
  }
  if (tone === 'harsh') {
    return 'Current setup prioritizes strict prevention. This reduces risk but can create friction and increase exception requests.'
  }
  return 'Current setup has balanced enforcement: severity and action are aligned for controlled response.'
}

function violationMessagePreview(target: typeof manualForm | typeof aiForm): string {
  const actionLabel = target.action_on_breach.replace('_', ' ')
  const ruleName = target.name?.trim() || 'Unnamed rule'
  const cond = target.conditions?.[0]
  const condLabel = cond ? `${cond.field || 'field'} ${cond.operator || 'eq'} ${Array.isArray(cond.value) ? cond.value.join(', ') : cond.value || 'value'}` : 'defined conditions'
  return `Policy enforcement warning: ${ruleName} was triggered (${condLabel}). Action: ${actionLabel}. Severity: ${target.severity}.`
}

const manualWarnings = computed(() => enforcementWarnings(manualForm))
const aiWarnings = computed(() => enforcementWarnings(aiForm))
const manualExplanation = computed(() => enforcementExplanation(manualForm))
const aiExplanation = computed(() => enforcementExplanation(aiForm))
const manualViolationPreview = computed(() => violationMessagePreview(manualForm))
const aiViolationPreview = computed(() => violationMessagePreview(aiForm))

function createCondition() {
  return {
    type: 'numeric',
    field: 'amount',
    operator: 'gt',
    value: '',
    value_unit: 'ZAR',
    logical_operator: 'AND',
  }
}

function createAction(type = 'log_violation') {
  return {
    type,
    message_template: '',
    config: {},
  }
}

function resetManualForm() {
  manualForm.reset()
  manualForm.clearErrors()
  manualForm.severity = 'medium'
  manualForm.action_on_breach = 'warn'
  manualForm.active = true
  manualForm.requires_exception_approval = false
  manualForm.clause_id = selectedClauseId.value
  manualForm.conditions = [createCondition()]
  manualForm.actions = [createAction('log_violation')]
}

function resetAiForm() {
  aiForm.reset()
  aiForm.clearErrors()
  aiForm.severity = 'medium'
  aiForm.action_on_breach = 'warn'
  aiForm.active = true
  aiForm.requires_exception_approval = false
  aiForm.clause_id = selectedClauseId.value
  aiForm.conditions = [createCondition()]
  aiForm.actions = [createAction('log_violation')]
  aiDraftId.value = null
  aiError.value = ''
}

function goBack() {
  router.visit(route('policies.show', props.policy.uuid))
}

function addCondition(target: typeof manualForm | typeof aiForm) {
  target.conditions.push(createCondition())
}

function removeCondition(target: typeof manualForm | typeof aiForm, index: number) {
  target.conditions.splice(index, 1)
  if (!target.conditions.length) target.conditions.push(createCondition())
}

function addAction(target: typeof manualForm | typeof aiForm) {
  target.actions.push(createAction())
}

function removeAction(target: typeof manualForm | typeof aiForm, index: number) {
  target.actions.splice(index, 1)
  if (!target.actions.length) target.actions.push(createAction('log_violation'))
}

function normalizeClauseSelection(target: typeof manualForm | typeof aiForm, clauseId: number | null) {
  target.clause_id = clauseId
}

function submitManualRule() {
  manualForm.post(route('policies.rules.store', props.policy.uuid), {
    preserveScroll: true,
    onSuccess: resetManualForm,
  })
}

function fillAiForm(parsed: any, draftId: number) {
  aiDraftId.value = draftId
  aiError.value = ''
  aiForm.name = parsed.name ?? ''
  aiForm.description = parsed.description ?? ''
  aiForm.clause_id = parsed.clause_id ?? selectedClauseId.value
  aiForm.natural_language_input = parsed.natural_language_input ?? ''
  aiForm.severity = parsed.severity ?? 'medium'
  aiForm.action_on_breach = parsed.action_on_breach ?? 'warn'
  aiForm.requires_exception_approval = !!parsed.requires_exception_approval
  aiForm.active = parsed.active ?? true
  aiForm.conditions = Array.isArray(parsed.conditions) && parsed.conditions.length ? parsed.conditions.map((item: any) => ({
    type: item.type ?? 'custom',
    field: item.field ?? '',
    operator: item.operator ?? 'eq',
    value: item.value ?? '',
    value_unit: item.value_unit ?? null,
    logical_operator: item.logical_operator ?? 'AND',
  })) : [createCondition()]
  aiForm.actions = Array.isArray(parsed.actions) && parsed.actions.length ? parsed.actions.map((item: any) => ({
    type: item.type ?? 'log_violation',
    message_template: item.message_template ?? '',
    config: item.config ?? {},
  })) : [createAction('log_violation')]
}

async function parseNaturalLanguage() {
  aiBusy.value = true
  aiError.value = ''

  try {
    const response = await axios.post(route('policies.rules.parse-nl'), {
      policy_uuid: props.policy.uuid,
      clause_id: selectedClauseId.value,
      input: aiForm.natural_language_input,
    })

    const draft = response.data?.draft
    if (!draft) {
      throw new Error('No draft returned from AI parser.')
    }

    fillAiForm(draft.ai_parsed_rule ?? {}, draft.id)
    openAi.value = true
  } catch (error: any) {
    aiError.value = error?.response?.data?.message || error?.message || 'Failed to parse natural language rule.'
  } finally {
    aiBusy.value = false
  }
}

function submitAiDraft() {
  if (!aiDraftId.value) return

  aiForm.post(route('policies.rules.confirm-draft', [props.policy.uuid, aiDraftId.value]), {
    preserveScroll: true,
    onSuccess: () => {
      resetAiForm()
      router.reload({ only: ['rules', 'drafts'] })
    },
  })
}

function duplicateRule(rule: Rule) {
  manualForm.name = `${rule.name} (copy)`
  manualForm.description = rule.description ?? ''
  manualForm.clause_id = rule.clause_id
  manualForm.severity = rule.severity
  manualForm.action_on_breach = rule.action_on_breach
  manualForm.conditions = (rule.conditions ?? []).map(condition => ({ ...condition, value: Array.isArray(condition.value) ? [...condition.value] : condition.value }))
  manualForm.actions = (rule.actions ?? []).map(action => ({ ...action, config: action.config ?? {} }))
  openManual.value = true
}

function previewObject(target: typeof manualForm | typeof aiForm) {
  return JSON.stringify({
    name: target.name,
    description: target.description,
    clause_id: target.clause_id,
    natural_language_input: target.natural_language_input,
    severity: target.severity,
    action_on_breach: target.action_on_breach,
    requires_exception_approval: target.requires_exception_approval,
    active: target.active,
    conditions: target.conditions,
    actions: target.actions,
  }, null, 2)
}

function conditionLabel(condition: any) {
  return `${condition.type} • ${condition.field} ${condition.operator} ${Array.isArray(condition.value) ? condition.value.join(', ') : condition.value}`
}

function actionLabel(action: any) {
  return `${action.type}${action.message_template ? ` • ${action.message_template}` : ''}`
}

function clauseLabel(clause?: Clause | null) {
  if (!clause) return '—'
  return clause.clause_reference ? `${clause.clause_reference}` : clause.content.slice(0, 50)
}

function conditionValueType(condition: any) {
  if (condition.type === 'date') return 'date'
  if (condition.type === 'numeric') return 'number'
  return 'text'
}

function statusColor(value: string) {
  if (value === 'high' || value === 'critical') return 'error'
  if (value === 'medium') return 'warning'
  return 'success'
}

function nlStatusColor(value: string) {
  if (value === 'converted') return 'success'
  if (value === 'pending_review') return 'warning'
  if (value === 'rejected') return 'error'
  return 'info'
}

const parsedJson = computed(() => (obj: any) => JSON.stringify(obj ?? {}, null, 2))

if (selectedClauseId.value === null && clauseOptions.value.length) {
  selectedClauseId.value = clauseOptions.value[0].value
  manualForm.clause_id = selectedClauseId.value
  aiForm.clause_id = selectedClauseId.value
}
</script>

<template>
  <section class="bg-[#f8f8ff]">
    <VContainer fluid class="pa-4 pa-sm-6">
      <div class="d-flex align-center justify-space-between flex-wrap gap-3 mb-4">
        <div>
          <h3 class="text-h5 font-weight-semibold mb-1">Rule Builder: {{ policy.name }}</h3>
          <p class="text-medium-emphasis mb-0">Manual rules, linked clauses, and AI-generated natural-language rules.</p>
        </div>

        <div class="d-flex flex-wrap gap-2">
          <VBtn variant="text" @click="goBack">
            <i class="fa-solid fa-arrow-left me-2" /> Back to Policy
          </VBtn>
        </div>
      </div>

      <VRow class="mb-4">
        <VCol cols="12" md="4">
          <VCard elevation="0" border rounded="lg">
            <VCardText>
              <div class="text-caption text-medium-emphasis">Rules</div>
              <div class="text-h5 font-weight-semibold">{{ rules.length }}</div>
            </VCardText>
          </VCard>
        </VCol>
        <VCol cols="12" md="4">
          <VCard elevation="0" border rounded="lg">
            <VCardText>
              <div class="text-caption text-medium-emphasis">Extracted Clauses</div>
              <div class="text-h5 font-weight-semibold">{{ clauses.length }}</div>
            </VCardText>
          </VCard>
        </VCol>
        <VCol cols="12" md="4">
          <VCard elevation="0" border rounded="lg">
            <VCardText>
              <div class="text-caption text-medium-emphasis">AI Drafts</div>
              <div class="text-h5 font-weight-semibold">{{ drafts.length }}</div>
            </VCardText>
          </VCard>
        </VCol>
      </VRow>

      <VRow class="mb-4">
        <VCol cols="12" md="6">
          <VCard elevation="0" border rounded="lg">
            <VCardTitle>Real-Time Enforcement Warnings</VCardTitle>
            <VCardText>
              <VAlert v-if="manualWarnings.length" type="warning" variant="tonal" class="mb-3">
                <div class="font-weight-medium mb-1">Manual Rule Builder</div>
                <ul class="mb-0 ps-4">
                  <li v-for="(warning, idx) in manualWarnings" :key="`manual-warning-${idx}`">{{ warning }}</li>
                </ul>
              </VAlert>
              <VAlert v-else type="success" variant="tonal" class="mb-3">
                Manual rule configuration currently has no high-risk warning.
              </VAlert>

              <VAlert v-if="aiWarnings.length" type="warning" variant="tonal">
                <div class="font-weight-medium mb-1">AI Rule Builder</div>
                <ul class="mb-0 ps-4">
                  <li v-for="(warning, idx) in aiWarnings" :key="`ai-warning-${idx}`">{{ warning }}</li>
                </ul>
              </VAlert>
              <VAlert v-else type="success" variant="tonal">
                AI rule configuration currently has no high-risk warning.
              </VAlert>
            </VCardText>
          </VCard>
        </VCol>

        <VCol cols="12" md="6">
          <VCard elevation="0" border rounded="lg">
            <VCardTitle>Enforcement Explanation Panel</VCardTitle>
            <VCardText>
              <div class="text-caption text-medium-emphasis mb-1">Manual Rule</div>
              <p class="mb-3">{{ manualExplanation }}</p>

              <div class="text-caption text-medium-emphasis mb-1">Manual Violation Message Preview</div>
              <pre class="text-caption px-3 py-2 mb-4 rounded bg-grey-lighten-4" style="white-space: pre-wrap;">{{ manualViolationPreview }}</pre>

              <div class="text-caption text-medium-emphasis mb-1">AI Rule</div>
              <p class="mb-3">{{ aiExplanation }}</p>

              <div class="text-caption text-medium-emphasis mb-1">AI Violation Message Preview</div>
              <pre class="text-caption px-3 py-2 mb-0 rounded bg-grey-lighten-4" style="white-space: pre-wrap;">{{ aiViolationPreview }}</pre>
            </VCardText>
          </VCard>
        </VCol>
      </VRow>

      <VRow>
        <VCol cols="12" lg="7">
          <VCard elevation="0" border rounded="lg" class="mb-4">
            <VCardTitle class="d-flex align-center justify-space-between">
              <span>Rule Library</span>
            </VCardTitle>
            <VCardText>
              <div v-if="!rules.length" class="text-medium-emphasis">No rules yet.</div>
              <VCard v-for="rule in rules" :key="rule.id" elevation="0" border rounded="lg" class="mb-3">
                <VCardText>
                  <div class="d-flex align-start justify-space-between gap-3 flex-wrap">
                    <div>
                      <div class="d-flex align-center gap-2 mb-2">
                        <strong>{{ rule.name }}</strong>
                        <VChip size="small" :color="statusColor(rule.severity)" variant="tonal" label>{{ rule.severity }}</VChip>
                        <VChip size="small" :color="rule.active ? 'success' : 'default'" variant="tonal" label>{{ rule.active ? 'Active' : 'Inactive' }}</VChip>
                      </div>
                      <div class="text-body-2 text-medium-emphasis mb-2">{{ rule.description || 'No description provided.' }}</div>
                      <div class="text-caption text-medium-emphasis mb-2">Clause: {{ clauseLabel(rule.clause) }}</div>
                      <div class="text-caption"><strong>Breach action:</strong> {{ rule.action_on_breach }}</div>
                    </div>

                    <div class="d-flex gap-2">
                      <VBtn size="small" variant="tonal" @click="duplicateRule(rule)">
                        <i class="fa-solid fa-copy me-1" /> Copy
                      </VBtn>
                      <VBtn size="small" variant="text" @click="router.patch(route('policies.rules.toggle', [policy.uuid, rule.id]), {})">
                        <i class="fa-solid fa-toggle-on me-1" /> Toggle
                      </VBtn>
                    </div>
                  </div>

                  <VRow class="mt-3">
                    <VCol cols="12" md="6">
                      <div class="text-caption text-medium-emphasis mb-1">Conditions</div>
                      <VChip v-for="condition in rule.conditions ?? []" :key="condition.id" size="small" class="me-2 mb-2" variant="tonal">
                        {{ conditionLabel(condition) }}
                      </VChip>
                    </VCol>
                    <VCol cols="12" md="6">
                      <div class="text-caption text-medium-emphasis mb-1">Actions</div>
                      <VChip v-for="action in rule.actions ?? []" :key="action.id" size="small" class="me-2 mb-2" variant="tonal">
                        {{ actionLabel(action) }}
                      </VChip>
                    </VCol>
                  </VRow>
                </VCardText>
              </VCard>
            </VCardText>
          </VCard>

          <VCard elevation="0" border rounded="lg" class="mb-4">
            <VCardTitle class="d-flex align-center justify-space-between">
              <span>Manual Rule Creation</span>
              <VBtn size="small" variant="text" @click="openManual = !openManual">
                {{ openManual ? 'Hide' : 'Show' }}
              </VBtn>
            </VCardTitle>

            <VExpandTransition>
              <div v-show="openManual">
                <VCardText>
                  <VRow>
                    <VCol cols="12" md="6">
                      <AppTextField v-model="manualForm.name" label="Rule Name *" :error-messages="manualForm.errors.name" />
                    </VCol>

                    <VCol cols="12" md="6">
                      <AppSelect v-model="manualForm.severity" :items="severityOptions" label="Severity *" :error-messages="manualForm.errors.severity" />
                    </VCol>

                    <VCol cols="12" md="6">
                      <AppSelect v-model="manualForm.clause_id" :items="clauseOptions" label="Linked Clause" clearable :error-messages="manualForm.errors.clause_id" />
                    </VCol>

                    <VCol cols="12" md="6">
                      <AppSelect v-model="manualForm.action_on_breach" :items="[
                        { title: 'block', value: 'block' },
                        { title: 'warn', value: 'warn' },
                        { title: 'escalate', value: 'escalate' },
                        { title: 'log_only', value: 'log_only' },
                      ]" label="Action on Breach *" :error-messages="manualForm.errors.action_on_breach" />
                    </VCol>

                    <VCol cols="12">
                      <AppTextarea v-model="manualForm.description" label="Description" rows="2" :error-messages="manualForm.errors.description" />
                    </VCol>

                    <VCol cols="12" md="6">
                      <VCheckbox v-model="manualForm.requires_exception_approval" label="Requires exception approval" />
                    </VCol>

                    <VCol cols="12" md="6">
                      <VCheckbox v-model="manualForm.active" label="Active" />
                    </VCol>

                    <VCol cols="12">
                      <VAlert v-if="manualWarnings.length" type="warning" variant="tonal" class="mb-0">
                        <div class="font-weight-medium mb-1">Enforcement warnings</div>
                        <ul class="mb-0 ps-4">
                          <li v-for="(warning, idx) in manualWarnings" :key="`manual-inline-warning-${idx}`">{{ warning }}</li>
                        </ul>
                      </VAlert>
                    </VCol>

                    <VCol cols="12">
                      <VAlert type="info" variant="tonal" class="mb-0">
                        <strong>Violation message preview:</strong>
                        <div class="mt-2">{{ manualViolationPreview }}</div>
                      </VAlert>
                    </VCol>
                  </VRow>

                  <VDivider class="my-4" />

                  <div class="d-flex align-center justify-space-between mb-2">
                    <strong>Conditions</strong>
                    <VBtn size="small" variant="tonal" @click="addCondition(manualForm)">
                      <i class="fa-solid fa-plus me-1" /> Add Condition
                    </VBtn>
                  </div>

                  <VCard v-for="(condition, index) in manualForm.conditions" :key="index" elevation="0" border rounded="lg" class="mb-3 pa-3">
                    <VRow>
                      <VCol cols="12" md="2">
                        <AppSelect v-model="condition.type" :items="conditionTypeOptions" label="Type" />
                      </VCol>
                      <VCol cols="12" md="3">
                        <AppTextField v-model="condition.field" label="Field" placeholder="amount, user.role, date" />
                      </VCol>
                      <VCol cols="12" md="2">
                        <AppSelect v-model="condition.operator" :items="operatorOptions" label="Operator" />
                      </VCol>
                      <VCol cols="12" md="2">
                        <AppTextField v-model="condition.value" :type="conditionValueType(condition)" label="Value" />
                      </VCol>
                      <VCol cols="12" md="2">
                        <AppTextField v-model="condition.value_unit" label="Unit" placeholder="ZAR, days, hours" />
                      </VCol>
                      <VCol cols="12" md="1" class="d-flex align-center">
                        <VBtn icon variant="text" color="error" @click="removeCondition(manualForm, index)">
                          <i class="fa-solid fa-trash" />
                        </VBtn>
                      </VCol>

                      <VCol cols="12" md="3">
                        <AppSelect v-model="condition.logical_operator" :items="[{ title: 'AND', value: 'AND' }, { title: 'OR', value: 'OR' }]" label="Join" />
                      </VCol>
                    </VRow>
                  </VCard>

                  <VDivider class="my-4" />

                  <div class="d-flex align-center justify-space-between mb-2">
                    <strong>Actions</strong>
                    <VBtn size="small" variant="tonal" @click="addAction(manualForm)">
                      <i class="fa-solid fa-plus me-1" /> Add Action
                    </VBtn>
                  </div>

                  <VCard v-for="(action, index) in manualForm.actions" :key="index" elevation="0" border rounded="lg" class="mb-3 pa-3">
                    <VRow>
                      <VCol cols="12" md="3">
                        <AppSelect v-model="action.type" :items="actionTypeOptions" label="Type" />
                      </VCol>
                      <VCol cols="12" md="6">
                        <AppTextField v-model="action.message_template" label="Message template" placeholder="User-facing message" />
                      </VCol>
                      <VCol cols="12" md="2">
                        <AppTextField v-model="action.config.target_role" label="Role / Config" placeholder="CFO" />
                      </VCol>
                      <VCol cols="12" md="1" class="d-flex align-center">
                        <VBtn icon variant="text" color="error" @click="removeAction(manualForm, index)">
                          <i class="fa-solid fa-trash" />
                        </VBtn>
                      </VCol>
                    </VRow>
                  </VCard>

                  <div class="d-flex gap-2 mt-4">
                    <VBtn color="primary" :loading="manualForm.processing" @click="submitManualRule">
                      Save Rule
                    </VBtn>
                    <VBtn variant="text" @click="resetManualForm">Reset</VBtn>
                  </div>
                </VCardText>
              </div>
            </VExpandTransition>
          </VCard>
        </VCol>

        <VCol cols="12" lg="5">
          <VCard elevation="0" border rounded="lg" class="mb-4">
            <VCardTitle class="d-flex align-center justify-space-between">
              <span>AI Natural Language Rule Builder</span>
              <VBtn size="small" variant="text" @click="openAi = !openAi">
                {{ openAi ? 'Hide' : 'Show' }}
              </VBtn>
            </VCardTitle>

            <VExpandTransition>
              <div v-show="openAi">
                <VCardText>
                  <AppSelect v-model="selectedClauseId" :items="clauseOptions" label="Optional linked clause" clearable />

                  <AppTextarea
                    v-model="aiForm.natural_language_input"
                    label="Natural language rule"
                    rows="4"
                    hint='Example: "Block claims above R5000 unless CFO approves"'
                    persistent-hint
                    :error-messages="aiError"
                  />

                  <div class="d-flex gap-2 mt-3 mb-4">
                    <VBtn color="primary" :loading="aiBusy" @click="parseNaturalLanguage">
                      Convert with AI
                    </VBtn>
                    <VBtn variant="text" @click="resetAiForm">Reset</VBtn>
                  </div>

                  <VDivider class="my-4" />

                  <div v-if="aiDraftId" class="mb-2">
                    <VChip size="small" color="success" variant="tonal" label>AI draft ready</VChip>
                  </div>

                  <VRow>
                    <VCol cols="12" md="6">
                      <AppTextField v-model="aiForm.name" label="Rule Name *" :error-messages="aiForm.errors.name" />
                    </VCol>
                    <VCol cols="12" md="6">
                      <AppSelect v-model="aiForm.severity" :items="severityOptions" label="Severity *" :error-messages="aiForm.errors.severity" />
                    </VCol>
                    <VCol cols="12" md="6">
                      <AppSelect v-model="aiForm.clause_id" :items="clauseOptions" label="Linked Clause" clearable :error-messages="aiForm.errors.clause_id" />
                    </VCol>
                    <VCol cols="12" md="6">
                      <AppSelect v-model="aiForm.action_on_breach" :items="[
                        { title: 'block', value: 'block' },
                        { title: 'warn', value: 'warn' },
                        { title: 'escalate', value: 'escalate' },
                        { title: 'log_only', value: 'log_only' },
                      ]" label="Action on Breach *" :error-messages="aiForm.errors.action_on_breach" />
                    </VCol>
                    <VCol cols="12">
                      <AppTextarea v-model="aiForm.description" label="Description" rows="2" />
                    </VCol>
                    <VCol cols="12" md="6">
                      <VCheckbox v-model="aiForm.requires_exception_approval" label="Requires exception approval" />
                    </VCol>
                    <VCol cols="12" md="6">
                      <VCheckbox v-model="aiForm.active" label="Active" />
                    </VCol>

                    <VCol cols="12">
                      <VAlert v-if="aiWarnings.length" type="warning" variant="tonal" class="mb-0">
                        <div class="font-weight-medium mb-1">Enforcement warnings</div>
                        <ul class="mb-0 ps-4">
                          <li v-for="(warning, idx) in aiWarnings" :key="`ai-inline-warning-${idx}`">{{ warning }}</li>
                        </ul>
                      </VAlert>
                    </VCol>

                    <VCol cols="12">
                      <VAlert type="info" variant="tonal" class="mb-0">
                        <strong>Violation message preview:</strong>
                        <div class="mt-2">{{ aiViolationPreview }}</div>
                      </VAlert>
                    </VCol>
                  </VRow>

                  <VDivider class="my-4" />

                  <div class="d-flex align-center justify-space-between mb-2">
                    <strong>Conditions</strong>
                    <VBtn size="small" variant="tonal" @click="addCondition(aiForm)">
                      <i class="fa-solid fa-plus me-1" /> Add Condition
                    </VBtn>
                  </div>

                  <VCard v-for="(condition, index) in aiForm.conditions" :key="`ai-cond-${index}`" elevation="0" border rounded="lg" class="mb-3 pa-3">
                    <VRow>
                      <VCol cols="12" md="2">
                        <AppSelect v-model="condition.type" :items="conditionTypeOptions" label="Type" />
                      </VCol>
                      <VCol cols="12" md="3">
                        <AppTextField v-model="condition.field" label="Field" placeholder="amount, role, date" />
                      </VCol>
                      <VCol cols="12" md="2">
                        <AppSelect v-model="condition.operator" :items="operatorOptions" label="Operator" />
                      </VCol>
                      <VCol cols="12" md="2">
                        <AppTextField v-model="condition.value" :type="conditionValueType(condition)" label="Value" />
                      </VCol>
                      <VCol cols="12" md="2">
                        <AppTextField v-model="condition.value_unit" label="Unit" placeholder="ZAR, days, hours" />
                      </VCol>
                      <VCol cols="12" md="1" class="d-flex align-center">
                        <VBtn icon variant="text" color="error" @click="removeCondition(aiForm, index)">
                          <i class="fa-solid fa-trash" />
                        </VBtn>
                      </VCol>
                      <VCol cols="12" md="3">
                        <AppSelect v-model="condition.logical_operator" :items="[{ title: 'AND', value: 'AND' }, { title: 'OR', value: 'OR' }]" label="Join" />
                      </VCol>
                    </VRow>
                  </VCard>

                  <VDivider class="my-4" />

                  <div class="d-flex align-center justify-space-between mb-2">
                    <strong>Actions</strong>
                    <VBtn size="small" variant="tonal" @click="addAction(aiForm)">
                      <i class="fa-solid fa-plus me-1" /> Add Action
                    </VBtn>
                  </div>

                  <VCard v-for="(action, index) in aiForm.actions" :key="`ai-action-${index}`" elevation="0" border rounded="lg" class="mb-3 pa-3">
                    <VRow>
                      <VCol cols="12" md="3">
                        <AppSelect v-model="action.type" :items="actionTypeOptions" label="Type" />
                      </VCol>
                      <VCol cols="12" md="6">
                        <AppTextField v-model="action.message_template" label="Message template" />
                      </VCol>
                      <VCol cols="12" md="2">
                        <AppTextField v-model="action.config.target_role" label="Role / Config" placeholder="CFO" />
                      </VCol>
                      <VCol cols="12" md="1" class="d-flex align-center">
                        <VBtn icon variant="text" color="error" @click="removeAction(aiForm, index)">
                          <i class="fa-solid fa-trash" />
                        </VBtn>
                      </VCol>
                    </VRow>
                  </VCard>

                  <div class="d-flex gap-2 mt-4">
                    <VBtn color="success" :loading="aiForm.processing" :disabled="!aiDraftId" @click="submitAiDraft">
                      Save AI Rule
                    </VBtn>
                    <VBtn variant="text" @click="resetAiForm">Reset</VBtn>
                  </div>
                </VCardText>
              </div>
            </VExpandTransition>
          </VCard>

          <VCard elevation="0" border rounded="lg" class="mb-4">
            <VCardTitle>AI Draft Queue</VCardTitle>
            <VCardText>
              <div v-if="!drafts.length" class="text-medium-emphasis">No AI drafts yet.</div>
              <VCard v-for="draft in drafts" :key="draft.id" elevation="0" border rounded="lg" class="mb-3 pa-3">
                <div class="d-flex align-start justify-space-between gap-3">
                  <div>
                    <div class="d-flex align-center gap-2 mb-2">
                      <strong>{{ draft.ai_parsed_rule?.name || 'Untitled draft' }}</strong>
                      <VChip size="small" :color="nlStatusColor(draft.status)" variant="tonal" label>{{ draft.status }}</VChip>
                    </div>
                    <div class="text-caption text-medium-emphasis mb-1">Clause: {{ clauseLabel(draft.clause) }}</div>
                    <div class="text-caption text-medium-emphasis mb-1">Created by: {{ draft.createdBy?.name || '-' }}</div>
                    <div class="text-caption">{{ draft.natural_language_input }}</div>
                  </div>
                  <VBtn size="small" variant="tonal" color="primary" @click="fillAiForm(draft.ai_parsed_rule ?? {}, draft.id)">
                    Edit
                  </VBtn>
                </div>
              </VCard>
            </VCardText>
          </VCard>

          <VCard elevation="0" border rounded="lg">
            <VCardTitle>AI Draft Preview</VCardTitle>
            <VCardText>
              <pre class="text-caption" style="white-space: pre-wrap;">{{ previewObject(aiForm) }}</pre>
            </VCardText>
          </VCard>
        </VCol>
      </VRow>
    </VContainer>
  </section>
</template>