<script setup lang="ts">
import { computed, ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import ConfirmDialog from '@/Components/ConfirmDialog.vue'

const { t } = useI18n()
const route = ziggyRoute

type Tracked = {
  id: number; code: string; name: string
  category: string | null; type: string | null; status: string; currency: string | null
  allocated: number; reserved: number; committed: number; actual: number; available: number
  used_pct: number; spent_pct: number
  severity: string | null; breaches: string[]
  blocked: string | null
}

type Alert = {
  id: string
  budget: { id: number; code: string; name: string } | null
  rule: string | null; severity: string; metric: string
  threshold: number | null; observed: number | null
  message: string | null; status: string
  raised_at: string | null; seen_at: string | null
}

type Rule = {
  id: string; name: string; scope_type: string; scope_value: string | null; scope_id: number | null
  metric: string; threshold: number; severity: string
  notify_owners: boolean; notify_roles: string[]; is_active: boolean; description: string | null
}

const props = defineProps<{
  budgets: Tracked[]
  unexplained: Array<{
    budget_uuid: string; code: string; name: string; period: string
    planned: number; actual: number; variance: number; variance_pct: number
  }>
  alerts: Alert[]
  rules: Rule[]
  options: {
    scopeTypes: string[]; metrics: string[]; severities: string[]
    categories: Array<{ id: number; label: string }>
    budgets: Array<{ id: number; label: string }>
    roles: string[]
  }
  canManage: boolean
}>()

const tab = ref('position')
const confirmer = ref()

const severityColor: Record<string, string> = {
  info: 'info', warning: 'warning', critical: 'error', block: 'error',
}

function money(v: number | null, currency?: string | null) {
  if (v === null || v === undefined) return '—'
  try {
    return new Intl.NumberFormat(undefined, { style: 'currency', currency: currency || 'ZAR', maximumFractionDigits: 0 }).format(v)
  } catch {
    return v.toFixed(2)
  }
}

function barColor(row: Tracked) {
  if (row.available < 0) return 'error'
  if (row.used_pct >= 95) return 'error'
  if (row.used_pct >= 80) return 'warning'
  return 'primary'
}

// ── The three numbers at the top ─────────────────────────────────────────────

const headline = computed(() => ({
  blocked: props.budgets.filter(b => b.blocked).length,
  overrun: props.budgets.filter(b => b.available < 0).length,
  critical: props.alerts.filter(a => a.severity === 'critical' || a.severity === 'block').length,
  open: props.alerts.filter(a => a.status === 'open').length,
}))

/** Only what needs somebody: a full register belongs on the budget list, not on a triage page. */
const attention = computed(() => props.budgets.filter(b => b.blocked || b.severity || b.used_pct >= 80))

const showAll = ref(false)
const shown = computed(() => (showAll.value ? props.budgets : attention.value))

// ── Alerts ───────────────────────────────────────────────────────────────────

const ackDialog = ref(false)
const acking = ref<Alert | null>(null)
const ackForm = useForm({ note: '' })

function openAck(alert: Alert) {
  acking.value = alert
  ackForm.reset()
  ackDialog.value = true
}

function saveAck() {
  ackForm.post(route('budgets.tracking.alerts.acknowledge', acking.value!.id), {
    preserveScroll: true,
    onSuccess: () => { ackDialog.value = false },
  })
}

function sweep() {
  router.post(route('budgets.tracking.sweep'), {}, { preserveScroll: true })
}

// ── Rules ────────────────────────────────────────────────────────────────────

const ruleDialog = ref(false)
const editingRule = ref<Rule | null>(null)

const ruleForm = useForm<{
  name: string; scope_type: string; scope_value: string | null; scope_id: number | null
  metric: string; threshold: number | string; severity: string
  notify_owners: boolean; notify_roles: string[]; is_active: boolean; description: string
}>({
  name: '', scope_type: 'all', scope_value: null, scope_id: null,
  metric: 'committed_pct', threshold: 90, severity: 'warning',
  notify_owners: true, notify_roles: [], is_active: true, description: '',
})

function openNewRule() {
  editingRule.value = null
  ruleForm.reset()
  ruleDialog.value = true
}

function openEditRule(rule: Rule) {
  editingRule.value = rule
  ruleForm.reset()
  Object.assign(ruleForm, {
    name: rule.name, scope_type: rule.scope_type, scope_value: rule.scope_value, scope_id: rule.scope_id,
    metric: rule.metric, threshold: rule.threshold, severity: rule.severity,
    notify_owners: rule.notify_owners, notify_roles: rule.notify_roles ?? [],
    is_active: rule.is_active, description: rule.description ?? '',
  })
  ruleDialog.value = true
}

function saveRule() {
  const options = { preserveScroll: true, onSuccess: () => { ruleDialog.value = false } }

  editingRule.value
    ? ruleForm.put(route('budgets.tracking.rules.update', editingRule.value.id), options)
    : ruleForm.post(route('budgets.tracking.rules.store'), options)
}

function destroyRule(rule: Rule) {
  confirmer.value.open({
    title: t('budgets_tracking.confirm_delete_rule_title'),
    message: t('budgets_tracking.confirm_delete_rule_body', { name: rule.name }),
    confirmText: t('budgets_tracking.delete'),
    icon: 'tabler-trash',
  }, () => router.delete(route('budgets.tracking.rules.destroy', rule.id), { preserveScroll: true }))
}

/** What a rule watches, in one readable line. */
function describeRule(rule: Rule) {
  const scope = rule.scope_type === 'all'
    ? t('budgets_tracking.scope_all')
    : rule.scope_type === 'expenditure_type'
      ? t(`budgets_tracking.type_${rule.scope_value}`)
      : rule.scope_type === 'category'
        ? props.options.categories.find(c => c.id === rule.scope_id)?.label ?? '—'
        : props.options.budgets.find(b => b.id === rule.scope_id)?.label ?? '—'

  const measure = rule.metric === 'available_amt'
    ? t('budgets_tracking.rule_available', { amount: rule.threshold })
    : t(`budgets_tracking.rule_${rule.metric}`, { percent: rule.threshold })

  return `${scope} — ${measure}`
}

const needsScopeValue = computed(() => ruleForm.scope_type === 'expenditure_type')
const needsScopeId = computed(() => ['category', 'budget'].includes(ruleForm.scope_type))
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-2">
      <div>
        <h4 class="text-h4">{{ t('budgets_tracking.title') }}</h4>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('budgets_tracking.subtitle') }}</p>
      </div>
      <div class="d-flex gap-2">
        <VBtn v-if="canManage" variant="tonal" prepend-icon="tabler-refresh" @click="sweep">
          {{ t('budgets_tracking.run_now') }}
        </VBtn>
        <VBtn variant="tonal" prepend-icon="tabler-chart-pie" @click="router.get(route('budgets.insights.analytics'))">
          {{ t('budgets_tracking.analytics') }}
        </VBtn>
      </div>
    </div>

    <!-- Headline: the four numbers somebody opens this page to see -->
    <VRow class="mb-2">
      <VCol cols="6" md="3">
        <VCard border>
          <VCardText class="py-3">
            <div class="text-caption text-medium-emphasis">{{ t('budgets_tracking.kpi_blocked') }}</div>
            <div class="text-h5" :class="headline.blocked ? 'text-error' : ''">{{ headline.blocked }}</div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="6" md="3">
        <VCard border>
          <VCardText class="py-3">
            <div class="text-caption text-medium-emphasis">{{ t('budgets_tracking.kpi_overrun') }}</div>
            <div class="text-h5" :class="headline.overrun ? 'text-error' : ''">{{ headline.overrun }}</div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="6" md="3">
        <VCard border>
          <VCardText class="py-3">
            <div class="text-caption text-medium-emphasis">{{ t('budgets_tracking.kpi_critical') }}</div>
            <div class="text-h5" :class="headline.critical ? 'text-warning' : ''">{{ headline.critical }}</div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="6" md="3">
        <VCard border>
          <VCardText class="py-3">
            <div class="text-caption text-medium-emphasis">{{ t('budgets_tracking.kpi_open') }}</div>
            <div class="text-h5">{{ headline.open }}</div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <VTabs v-model="tab" class="mb-4">
      <VTab value="position">{{ t('budgets_tracking.tab_position') }}</VTab>
      <VTab value="alerts">
        {{ t('budgets_tracking.tab_alerts') }}
        <VChip v-if="alerts.length" size="x-small" class="ms-2" label>{{ alerts.length }}</VChip>
      </VTab>
      <VTab value="variances">
        Variances to explain
        <VChip v-if="unexplained.length" size="x-small" color="warning" class="ms-2" label>{{ unexplained.length }}</VChip>
      </VTab>
      <VTab value="rules">{{ t('budgets_tracking.tab_rules') }}</VTab>
    </VTabs>

    <!-- Position ------------------------------------------------------------ -->
    <VCard v-if="tab === 'position'" border>
      <VCardItem>
        <VCardTitle class="text-body-1">
          {{ showAll ? t('budgets_tracking.all_budgets') : t('budgets_tracking.needs_attention') }}
        </VCardTitle>
        <VCardSubtitle>{{ t('budgets_tracking.position_hint') }}</VCardSubtitle>
        <template #append>
          <VBtn size="small" variant="text" @click="showAll = !showAll">
            {{ showAll ? t('budgets_tracking.show_attention') : t('budgets_tracking.show_all') }}
          </VBtn>
        </template>
      </VCardItem>
      <VDivider />

      <div class="table-scroll">
        <VTable v-if="shown.length" density="comfortable">
          <thead>
            <tr>
              <th>{{ t('budgets_tracking.col_budget') }}</th>
              <th style="min-inline-size: 180px">{{ t('budgets_tracking.col_used') }}</th>
              <th class="text-right">{{ t('budgets_tracking.col_allocated') }}</th>
              <th class="text-right">{{ t('budgets_tracking.col_committed') }}</th>
              <th class="text-right">{{ t('budgets_tracking.col_actual') }}</th>
              <th class="text-right">{{ t('budgets_tracking.col_available') }}</th>
              <th>{{ t('budgets_tracking.col_state') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in shown" :key="row.id" style="cursor: pointer"
                @click="router.get(route('budgets.show', row.uuid))">
              <td>
                <div class="font-weight-medium">{{ row.code }}</div>
                <div class="text-caption text-medium-emphasis">
                  {{ row.name }}<template v-if="row.category"> · {{ row.category }}</template>
                </div>
              </td>
              <td>
                <VProgressLinear
                  :model-value="Math.min(100, row.used_pct)" :color="barColor(row)"
                  height="8" rounded
                />
                <span class="text-caption">{{ row.used_pct }}%</span>
              </td>
              <td class="text-right">{{ money(row.allocated, row.currency) }}</td>
              <td class="text-right">{{ money(row.reserved + row.committed, row.currency) }}</td>
              <td class="text-right">{{ money(row.actual, row.currency) }}</td>
              <td class="text-right" :class="row.available < 0 ? 'text-error font-weight-medium' : ''">
                {{ money(row.available, row.currency) }}
              </td>
              <td>
                <VChip v-if="row.blocked" size="x-small" color="error" label class="mb-1">
                  {{ t('budgets_tracking.blocked') }}
                </VChip>
                <VChip v-else-if="row.severity" size="x-small" :color="severityColor[row.severity]" label>
                  {{ t(`budgets_tracking.severity_${row.severity}`) }}
                </VChip>
                <span v-else class="text-caption text-medium-emphasis">{{ t('budgets_tracking.healthy') }}</span>
                <div v-if="row.blocked" class="text-caption text-medium-emphasis">{{ row.blocked }}</div>
              </td>
            </tr>
          </tbody>
        </VTable>

        <VCardText v-else class="text-center text-medium-emphasis py-10">
          <VIcon icon="tabler-mood-check" size="36" class="mb-2 d-block mx-auto" />
          {{ t('budgets_tracking.nothing_to_watch') }}
        </VCardText>
      </div>
    </VCard>

    <!-- Alerts -------------------------------------------------------------- -->
    <VCard v-else-if="tab === 'variances'" border>
      <VCardText class="text-body-2 text-medium-emphasis">
        Closed periods that missed their plan by more than the financial year's threshold, with no reason recorded yet.
        Open the budget, go to Phasing, and click Explain on the period.
      </VCardText>
      <VTable v-if="unexplained.length" density="compact">
        <thead>
          <tr>
            <th>Budget</th><th>Period</th>
            <th class="text-right">Planned</th><th class="text-right">Actual</th>
            <th class="text-right">Variance</th><th class="text-right">%</th><th />
          </tr>
        </thead>
        <tbody>
          <tr v-for="(v, i) in unexplained" :key="i">
            <td><span class="font-weight-medium">{{ v.code }}</span> · {{ v.name }}</td>
            <td>{{ v.period }}</td>
            <td class="text-right">{{ money(v.planned) }}</td>
            <td class="text-right">{{ money(v.actual) }}</td>
            <td class="text-right" :class="v.variance < 0 ? 'text-error' : 'text-warning'">{{ money(v.variance) }}</td>
            <td class="text-right">{{ v.variance_pct }}%</td>
            <td class="text-right">
              <VBtn size="x-small" variant="tonal" @click="router.get(route('budgets.show', v.budget_uuid), { tab: 'phasing' })">Explain</VBtn>
            </td>
          </tr>
        </tbody>
      </VTable>
      <VCardText v-else class="text-center text-medium-emphasis py-6">Every material variance has an explanation.</VCardText>
    </VCard>

    <VCard v-else-if="tab === 'alerts'" border>
      <VList v-if="alerts.length" lines="two">
        <template v-for="(alert, i) in alerts" :key="alert.id">
          <VDivider v-if="i" />
          <VListItem>
            <template #prepend>
              <VAvatar :color="severityColor[alert.severity] ?? 'grey'" variant="tonal" rounded size="40" class="me-3">
                <VIcon :icon="alert.severity === 'block' ? 'tabler-hand-stop' : 'tabler-alert-triangle'" />
              </VAvatar>
            </template>

            <VListItemTitle class="d-flex align-center flex-wrap gap-2">
              <span>{{ alert.message }}</span>
              <VChip size="x-small" :color="severityColor[alert.severity]" label>
                {{ t(`budgets_tracking.severity_${alert.severity}`) }}
              </VChip>
              <VChip v-if="alert.status === 'acknowledged'" size="x-small" label>
                {{ t('budgets_tracking.acknowledged') }}
              </VChip>
            </VListItemTitle>

            <VListItemSubtitle class="text-caption">
              <template v-if="alert.rule">{{ alert.rule }} · </template>
              {{ t('budgets_tracking.raised_at', { at: alert.raised_at }) }}
            </VListItemSubtitle>

            <template #append>
              <div class="d-flex gap-2">
                <VBtn
                  v-if="alert.budget" size="small" variant="text" prepend-icon="tabler-external-link"
                  @click="router.get(route('budgets.show', alert.budget.uuid))"
                >
                  {{ t('budgets_tracking.open_budget') }}
                </VBtn>
                <VBtn
                  v-if="alert.status === 'open'" size="small" variant="tonal" prepend-icon="tabler-check"
                  @click="openAck(alert)"
                >
                  {{ t('budgets_tracking.acknowledge') }}
                </VBtn>
              </div>
            </template>
          </VListItem>
        </template>
      </VList>

      <VCardText v-else class="text-center text-medium-emphasis py-10">
        <VIcon icon="tabler-bell-off" size="36" class="mb-2 d-block mx-auto" />
        {{ t('budgets_tracking.no_alerts') }}
      </VCardText>
    </VCard>

    <!-- Rules --------------------------------------------------------------- -->
    <VCard v-else border>
      <VCardItem>
        <VCardTitle class="text-body-1">{{ t('budgets_tracking.rules_title') }}</VCardTitle>
        <VCardSubtitle>{{ t('budgets_tracking.rules_subtitle') }}</VCardSubtitle>
        <template #append>
          <VBtn v-if="canManage" size="small" variant="tonal" prepend-icon="tabler-plus" @click="openNewRule">
            {{ t('budgets_tracking.add_rule') }}
          </VBtn>
        </template>
      </VCardItem>
      <VDivider />

      <VAlert type="info" variant="tonal" density="compact" class="ma-3">
        {{ t('budgets_tracking.block_notice') }}
      </VAlert>

      <VList v-if="rules.length" density="compact">
        <VListItem v-for="rule in rules" :key="rule.id">
          <VListItemTitle class="d-flex align-center gap-2">
            {{ rule.name }}
            <VChip size="x-small" :color="severityColor[rule.severity]" label>
              {{ t(`budgets_tracking.severity_${rule.severity}`) }}
            </VChip>
            <VChip v-if="!rule.is_active" size="x-small" label>{{ t('budgets_tracking.inactive') }}</VChip>
          </VListItemTitle>
          <VListItemSubtitle class="text-caption">{{ describeRule(rule) }}</VListItemSubtitle>
          <template #append>
            <VBtn v-if="canManage" size="small" variant="text" icon="tabler-edit"
                  :title="t('budgets_tracking.edit')" @click="openEditRule(rule)" />
            <VBtn v-if="canManage" size="small" variant="text" icon="tabler-trash" color="error"
                  :title="t('budgets_tracking.delete')" @click="destroyRule(rule)" />
          </template>
        </VListItem>
      </VList>

      <VCardText v-else class="text-center text-medium-emphasis py-8">
        {{ t('budgets_tracking.no_rules') }}
      </VCardText>
    </VCard>

    <!-- Acknowledge --------------------------------------------------------- -->
    <VDialog v-model="ackDialog" max-width="520">
      <VCard>
        <VCardItem><VCardTitle>{{ t('budgets_tracking.acknowledge') }}</VCardTitle></VCardItem>
        <VDivider />
        <VCardText>
          <p class="text-body-2 text-medium-emphasis">{{ acking?.message }}</p>
          <VTextarea
            v-model="ackForm.note" rows="3"
            :label="t('budgets_tracking.ack_note')"
            :hint="t('budgets_tracking.ack_hint')" persistent-hint
            variant="outlined" density="compact"
          />
        </VCardText>
        <VDivider />
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="ackDialog = false">{{ t('budgets_tracking.cancel') }}</VBtn>
          <VBtn color="primary" :loading="ackForm.processing" @click="saveAck">
            {{ t('budgets_tracking.acknowledge') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Rule editor --------------------------------------------------------- -->
    <VDialog v-model="ruleDialog" max-width="720" scrollable>
      <VCard>
        <VCardItem>
          <VCardTitle>{{ editingRule ? t('budgets_tracking.edit_rule') : t('budgets_tracking.add_rule') }}</VCardTitle>
        </VCardItem>
        <VDivider />
        <VCardText>
          <VRow>
            <VCol cols="12" md="7">
              <VTextField v-model="ruleForm.name" :label="t('budgets_tracking.field_name')"
                          :error-messages="ruleForm.errors.name" variant="outlined" density="compact" />
            </VCol>
            <VCol cols="12" md="5">
              <VSelect
                v-model="ruleForm.severity"
                :items="options.severities.map(s => ({ title: t(`budgets_tracking.severity_${s}`), value: s }))"
                :label="t('budgets_tracking.field_severity')"
                :hint="t('budgets_tracking.hint_severity')" persistent-hint
                variant="outlined" density="compact"
              />
            </VCol>

            <VCol cols="12" md="4">
              <VSelect
                v-model="ruleForm.scope_type"
                :items="options.scopeTypes.map(s => ({ title: t(`budgets_tracking.scope_${s}`), value: s }))"
                :label="t('budgets_tracking.field_scope')"
                variant="outlined" density="compact"
              />
            </VCol>
            <VCol v-if="needsScopeValue" cols="12" md="4">
              <VSelect
                v-model="ruleForm.scope_value"
                :items="[
                  { title: t('budgets_tracking.type_capex'), value: 'capex' },
                  { title: t('budgets_tracking.type_opex'), value: 'opex' },
                ]"
                :label="t('budgets_tracking.field_scope_value')"
                :error-messages="ruleForm.errors.scope_value"
                variant="outlined" density="compact"
              />
            </VCol>
            <VCol v-if="needsScopeId" cols="12" md="4">
              <VAutocomplete
                v-model="ruleForm.scope_id"
                :items="(ruleForm.scope_type === 'category' ? options.categories : options.budgets)
                  .map(o => ({ title: o.label, value: o.id }))"
                :label="t('budgets_tracking.field_scope_target')"
                :error-messages="ruleForm.errors.scope_id"
                variant="outlined" density="compact"
              />
            </VCol>

            <VCol cols="12" md="4">
              <VSelect
                v-model="ruleForm.metric"
                :items="options.metrics.map(m => ({ title: t(`budgets_tracking.metric_${m}`), value: m }))"
                :label="t('budgets_tracking.field_metric')"
                variant="outlined" density="compact"
              />
            </VCol>
            <VCol cols="12" md="4">
              <VTextField
                v-model="ruleForm.threshold" inputmode="decimal"
                :label="ruleForm.metric === 'available_amt'
                  ? t('budgets_tracking.field_threshold_amount')
                  : t('budgets_tracking.field_threshold_percent')"
                :error-messages="ruleForm.errors.threshold"
                variant="outlined" density="compact"
              />
            </VCol>

            <VCol cols="12" md="6">
              <VSelect
                v-model="ruleForm.notify_roles" :items="options.roles" multiple chips closable-chips
                :label="t('budgets_tracking.field_notify_roles')"
                :hint="t('budgets_tracking.hint_notify_roles')" persistent-hint
                variant="outlined" density="compact"
              />
            </VCol>
            <VCol cols="12" md="6" class="d-flex align-center">
              <VSwitch v-model="ruleForm.notify_owners" color="primary" density="compact" hide-details
                       :label="t('budgets_tracking.field_notify_owners')" />
            </VCol>

            <VCol cols="12">
              <VTextarea v-model="ruleForm.description" rows="2"
                         :label="t('budgets_tracking.field_description')"
                         variant="outlined" density="compact" />
            </VCol>
            <VCol cols="12">
              <VSwitch v-model="ruleForm.is_active" color="primary" density="compact" hide-details
                       :label="t('budgets_tracking.field_active')" />
            </VCol>
          </VRow>
        </VCardText>
        <VDivider />
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="ruleDialog = false">{{ t('budgets_tracking.cancel') }}</VBtn>
          <VBtn color="primary" :loading="ruleForm.processing" @click="saveRule">
            {{ t('budgets_tracking.save') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <ConfirmDialog ref="confirmer" />
  </div>
</template>

<style scoped>
/* Wide tables scroll inside the card rather than pushing the page sideways. */
.table-scroll {
  overflow-x: auto;
}
</style>
