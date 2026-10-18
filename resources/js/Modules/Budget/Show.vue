<script setup lang="ts">
import { computed, ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

type Adjustment = { id: number; type: string; amount: number; reason: string | null; status: string }
type Override = { id: number; amount: number; reason: string | null; status: string }
type LedgerRow = {
  id: number; amount: number; status: string
  source_type: string | null; source_id: number | null; created_at: string | null
}

const props = defineProps<{
  budget: {
    id: number; code: string; name: string; status: string; currency: string | null
    expenditure_type: string | null; scope_type: string | null; scope_label: string | null
    description: string | null
    financial_year?: { name: string } | null
    financialYear?: { name: string } | null
    allocations: any[]
    adjustments: Adjustment[]
    overrides: Override[]
    owners: Array<{ id: number; user_id: number; role: string }>
    reservations: LedgerRow[]
    commitments: LedgerRow[]
    consumptions: LedgerRow[]
  }
  amounts: {
    allocated: number; reserved: number; committed: number; actual: number
    overrides: number; available: number; committed_pct: number; actual_pct: number
  }
  statuses: string[]
  blocked: string | null
  breaches: Array<{ name: string; severity: string }>
  approvals: {
    pending: Array<{
      id: string; subject: string; subject_id: number; label: string | null
      amount: number | null; step: string | null; position: number; steps: number; waiting: number
    }>
    trail: Array<{ subject: string; label: string | null; actor: string | null; decision: string; comment: string | null; at: string | null }>
    budget_request: string | null
  }
  owners: Array<{ id: number; user_id: number; role: string; name: string }>
  people: Array<{ id: number; label: string }>
  rollup: {
    parent_id: number | null
    children: Array<{ id: number; label: string }>
    consolidated: Record<string, number> | null
    options: Array<{ id: number; label: string }>
  }
  phasing: Array<{
    period_id: number; label: string; start_date: string; end_date: string; closed: boolean
    planned: number; actual: number; variance: number; variance_pct: number
    cumulative_planned: number; cumulative_actual: number
    forecast: number | null; forecast_note: string | null; forecast_by: string | null; forecast_at: string | null
    outlook: number; cumulative_outlook: number
    material: boolean; needs_explanation: boolean
    explanation: string | null; explained_by: string | null; explained_at: string | null
  }>
  forecast: {
    elapsed_pct: number | null; run_rate: number | null; projected: number | null
    projected_committed: number | null; projected_variance: number | null; verdict: string
    reforecast: number | null; reforecast_variance: number | null; reforecast_at: string | null; reforecast_by: string | null
  }
  varianceThreshold: number
  revisions: Array<{ version: number; previous: number | null; amount: number; type: string; reason: string | null; at: string | null }>
  can: { update: boolean; approve: boolean }
}>()

const ccy = computed(() => props.budget.currency ?? 'ZAR')
const fyName = computed(() => props.budget.financial_year?.name ?? props.budget.financialYear?.name ?? '—')

function money(v: number | null) {
  return v == null ? '—' : new Intl.NumberFormat(undefined, { style: 'currency', currency: ccy.value }).format(v)
}

const statusColor: Record<string, string> = {
  draft: 'grey', pending: 'warning', approved: 'success', active: 'success',
  closed: 'grey', rejected: 'error',
}

const severityColor: Record<string, string> = {
  info: 'info', warning: 'warning', critical: 'error', block: 'error',
}

const canSubmit = computed(() => ['draft', 'rejected'].includes(props.budget.status) && props.can.update)

/** The bar is the position, not just the spend: reserved and committed are money already gone. */
const barSegments = computed(() => {
  const ceiling = props.amounts.allocated + props.amounts.overrides

  if (ceiling <= 0) return { actual: 0, committed: 0, reserved: 0 }

  return {
    actual: (props.amounts.actual / ceiling) * 100,
    committed: (props.amounts.committed / ceiling) * 100,
    reserved: (props.amounts.reserved / ceiling) * 100,
  }
})

// ?tab=phasing lands on the tab a link was sent for (Tracking's "Explain" button uses it).
const tab = ref(new URLSearchParams(window.location.search).get('tab') ?? 'ledger')

// ── Submitting for approval ─────────────────────────────────────────────────

const submitDialog = ref(false)
const submitForm = useForm({ note: '' })

function submitForApproval() {
  submitForm.post(route('budgets.submit', props.budget.uuid), {
    preserveScroll: true,
    onSuccess: () => { submitDialog.value = false },
  })
}

// ── Adjustments ─────────────────────────────────────────────────────────────

const adjForm = useForm<{ type: string; amount: number | null; reason: string; transfer_to_budget_id: number | null }>({
  type: 'increase', amount: null, reason: '', transfer_to_budget_id: null,
})

function submitAdjustment() {
  adjForm.post(route('budgets.adjustments.store', props.budget.uuid), {
    preserveScroll: true,
    onSuccess: () => adjForm.reset(),
  })
}

// ── Overrides ───────────────────────────────────────────────────────────────

const ovForm = useForm<{ amount: number | null; reason: string }>({ amount: null, reason: '' })

function submitOverride() {
  ovForm.post(route('budgets.overrides.store', props.budget.uuid), {
    preserveScroll: true,
    onSuccess: () => ovForm.reset(),
  })
}

// ── Manual ledger entry ─────────────────────────────────────────────────────

const ledgerDialog = ref(false)
const ledgerKind = ref<'reserve' | 'commit' | 'consume'>('reserve')

const ledgerForm = useForm<{
  amount: number | null; source_type: string; source_id: number | null
  reservation_id: number | null; commitment_id: number | null; note: string
}>({
  amount: null, source_type: 'manual', source_id: null,
  reservation_id: null, commitment_id: null, note: '',
})

function openLedger(kind: 'reserve' | 'commit' | 'consume') {
  ledgerKind.value = kind
  ledgerForm.reset()
  ledgerForm.clearErrors()
  ledgerDialog.value = true
}

function saveLedger() {
  const target = {
    reserve: 'budgets.ledger.reserve',
    commit: 'budgets.ledger.commit',
    consume: 'budgets.ledger.consume',
  }[ledgerKind.value]

  ledgerForm.post(route(target, props.budget.id), {
    preserveScroll: true,
    onSuccess: () => { ledgerDialog.value = false },
  })
}

function releaseReservation(id: number) {
  router.post(route('budgets.ledger.reservations.release', id), {}, { preserveScroll: true })
}

function releaseCommitment(id: number) {
  router.post(route('budgets.ledger.commitments.release', id), {}, { preserveScroll: true })
}

const activeReservations = computed(() => props.budget.reservations.filter(r => r.status === 'active'))
const activeCommitments = computed(() => props.budget.commitments.filter(c => c.status === 'active'))

// ── Phasing ─────────────────────────────────────────────────────────────────

const phasingEdit = ref(false)
const phasingForm = useForm<{ lines: Array<{ period_id: number; amount: number | null }> }>({ lines: [] })

function openPhasing() {
  phasingForm.lines = props.phasing.map(p => ({ period_id: p.period_id, amount: p.planned }))
  phasingEdit.value = true
}

function savePhasing() {
  phasingForm.post(route('budgets.phasing.save', props.budget.uuid), {
    preserveScroll: true,
    onSuccess: () => { phasingEdit.value = false },
  })
}

function spreadEvenly() {
  router.post(route('budgets.phasing.spread', props.budget.uuid), {}, { preserveScroll: true })
}

const phasingTotal = computed(() => phasingForm.lines.reduce((s, l) => s + (Number(l.amount) || 0), 0))

// ── Reforecast ──────────────────────────────────────────────────────────────
// The owner's revised expectation for open periods. Closed periods are actuals and stay read-only.

const forecastEdit = ref(false)
const forecastForm = useForm<{ lines: Array<{ period_id: number; amount: number | null | '' }> }>({ lines: [] })

function openForecast() {
  phasingEdit.value = false
  forecastForm.lines = props.phasing.map(p => ({ period_id: p.period_id, amount: p.forecast ?? '' }))
  forecastEdit.value = true
}

function saveForecast() {
  forecastForm
    .transform(d => ({ lines: d.lines.filter((_, i) => !props.phasing[i].closed) }))
    .post(route('budgets.forecast.save', props.budget.uuid), {
      preserveScroll: true,
      onSuccess: () => { forecastEdit.value = false },
    })
}

const forecastPreview = computed(() => props.phasing.reduce((sum, p, i) => {
  if (p.closed) return sum + p.actual
  const entered = forecastForm.lines[i]?.amount
  const value = entered === '' || entered == null ? p.planned : Number(entered)
  return sum + Math.max(p.actual, value)
}, 0))

// ── Variance explanations ───────────────────────────────────────────────────

type PhaseRow = (typeof props.phasing)[number]

const explainDialog = ref(false)
const explainTarget = ref<PhaseRow | null>(null)
const explainForm = useForm({ explanation: '' })

function openExplain(row: PhaseRow) {
  explainTarget.value = row
  explainForm.explanation = row.explanation ?? ''
  explainDialog.value = true
}

function saveExplanation() {
  if (!explainTarget.value) return
  explainForm.post(route('budgets.variances.explain', [props.budget.uuid, explainTarget.value.period_id]), {
    preserveScroll: true,
    onSuccess: () => { explainDialog.value = false },
  })
}

const unexplainedCount = computed(() => props.phasing.filter(p => p.needs_explanation).length)

// ── Owners ──────────────────────────────────────────────────────────────────

const ownerForm = useForm<{ user_id: number | null; role: string }>({ user_id: null, role: 'owner' })

function addOwner() {
  ownerForm.post(route('budgets.owners.store', props.budget.uuid), {
    preserveScroll: true,
    onSuccess: () => ownerForm.reset(),
  })
}

function removeOwner(id: number) {
  router.delete(route('budgets.owners.destroy', [props.budget.uuid, id]), { preserveScroll: true })
}

// ── Roll-up ─────────────────────────────────────────────────────────────────

const parentForm = useForm<{ parent_id: number | null }>({ parent_id: props.rollup.parent_id })

function saveParent() {
  parentForm.post(route('budgets.parent.set', props.budget.uuid), { preserveScroll: true })
}

const forecastColor = computed(() => ({
  over: 'error', tight: 'warning', on_track: 'success',
}[props.forecast.verdict] ?? 'grey'))
</script>

<template>
  <div class="pa-4">
    <!-- Header -->
    <div class="d-flex flex-wrap align-center justify-space-between gap-3 mb-4">
      <div class="d-flex align-center gap-3">
        <VBtn icon="tabler-arrow-left" variant="text" @click="router.get(route('budgets.index'))" />
        <div>
          <h4 class="text-h4 mb-0">
            {{ budget.code }} — {{ budget.name }}
            <VChip size="small" :color="statusColor[budget.status]" variant="tonal" class="ms-2">
              {{ t(`budgets_show.status.${budget.status}`) }}
            </VChip>
          </h4>
          <p class="text-caption text-medium-emphasis mb-0">
            FY {{ fyName }} · {{ budget.expenditure_type ?? '—' }} · {{ budget.scope_label ?? budget.scope_type ?? '—' }}
          </p>
        </div>
      </div>
      <div class="d-flex gap-2">
        <VBtn
          v-if="canSubmit" color="primary" prepend-icon="tabler-send"
          @click="submitDialog = true"
        >
          {{ t('budgets_show.submit_for_approval') }}
        </VBtn>
        <VBtn
          v-else-if="approvals.budget_request" color="warning" variant="tonal" prepend-icon="tabler-clock"
          @click="router.get(route('budgets.approvals.index'))"
        >
          {{ t('budgets_show.awaiting_approval') }}
        </VBtn>
      </div>
    </div>

    <!-- Anything stopping this budget, said once and in plain words -->
    <VAlert v-if="blocked" type="error" variant="tonal" density="compact" class="mb-4">
      {{ blocked }}
    </VAlert>
    <VAlert v-else-if="breaches.length" :type="breaches[0].severity === 'critical' ? 'warning' : 'info'"
            variant="tonal" density="compact" class="mb-4">
      {{ t('budgets_show.breaching', { rules: breaches.map(b => b.name).join(', ') }) }}
    </VAlert>

    <VRow>
      <!-- Fund position -->
      <VCol cols="12" md="8">
        <VCard border class="h-100">
          <VCardItem>
            <VCardTitle>{{ t('budgets_show.funds.title') }}</VCardTitle>
            <VCardSubtitle>{{ t('budgets_show.funds.subtitle', { percent: amounts.committed_pct }) }}</VCardSubtitle>
          </VCardItem>
          <VCardText>
            <!-- Three segments, because "spent" and "already spoken for" are different problems. -->
            <div class="d-flex mb-3 fund-bar">
              <div class="fund-seg bg-primary" :style="{ width: `${barSegments.actual}%` }" />
              <div class="fund-seg bg-info" :style="{ width: `${barSegments.committed}%` }" />
              <div class="fund-seg bg-warning" :style="{ width: `${barSegments.reserved}%` }" />
            </div>

            <div class="d-flex flex-wrap gap-2">
              <VChip variant="tonal">{{ t('budgets_show.funds.allocated', { amount: money(amounts.allocated) }) }}</VChip>
              <VChip color="warning" variant="tonal">{{ t('budgets_show.funds.reserved', { amount: money(amounts.reserved) }) }}</VChip>
              <VChip color="info" variant="tonal">{{ t('budgets_show.funds.committed', { amount: money(amounts.committed) }) }}</VChip>
              <VChip color="primary" variant="tonal">{{ t('budgets_show.funds.actual', { amount: money(amounts.actual) }) }}</VChip>
              <VChip v-if="amounts.overrides" color="secondary" variant="tonal">
                {{ t('budgets_show.funds.approved_overrides', { amount: money(amounts.overrides) }) }}
              </VChip>
              <VChip :color="amounts.available < 0 ? 'error' : 'success'" variant="flat">
                {{ t('budgets_show.funds.available', { amount: money(amounts.available) }) }}
              </VChip>
            </div>
            <p v-if="budget.description" class="text-body-2 text-medium-emphasis mt-3 mb-0">{{ budget.description }}</p>
          </VCardText>
          <VDivider />
          <VCardActions v-if="can.update">
            <VBtn size="small" variant="text" prepend-icon="tabler-lock" @click="openLedger('reserve')">
              {{ t('budgets_show.ledger.reserve') }}
            </VBtn>
            <VBtn size="small" variant="text" prepend-icon="tabler-file-invoice" @click="openLedger('commit')">
              {{ t('budgets_show.ledger.commit') }}
            </VBtn>
            <VBtn size="small" variant="text" prepend-icon="tabler-receipt" @click="openLedger('consume')">
              {{ t('budgets_show.ledger.consume') }}
            </VBtn>
          </VCardActions>
        </VCard>
      </VCol>

      <!-- Forecast -->
      <VCol cols="12" md="4">
        <VCard border class="h-100">
          <VCardItem>
            <VCardTitle class="text-body-1">{{ t('budgets_show.forecast.title') }}</VCardTitle>
            <VCardSubtitle>{{ t('budgets_show.forecast.subtitle') }}</VCardSubtitle>
          </VCardItem>
          <VDivider />
          <VCardText>
            <template v-if="forecast.projected !== null">
              <div class="d-flex align-center justify-space-between mb-2">
                <span class="text-caption text-medium-emphasis">{{ t('budgets_show.forecast.elapsed') }}</span>
                <span>{{ forecast.elapsed_pct }}%</span>
              </div>
              <VProgressLinear :model-value="forecast.elapsed_pct ?? 0" color="grey" height="6" rounded class="mb-4" />

              <div class="text-caption text-medium-emphasis">{{ t('budgets_show.forecast.projected') }}</div>
              <div class="text-h6 mb-1" :class="`text-${forecastColor}`">{{ money(forecast.projected) }}</div>

              <div class="text-caption text-medium-emphasis mt-3">{{ t('budgets_show.forecast.projected_committed') }}</div>
              <div class="text-body-1">{{ money(forecast.projected_committed) }}</div>

              <VChip class="mt-3" size="small" :color="forecastColor" variant="tonal">
                {{ t(`budgets_show.forecast.verdict_${forecast.verdict}`) }}
              </VChip>
            </template>
            <div v-else class="text-body-2 text-medium-emphasis">
              {{ t(`budgets_show.forecast.verdict_${forecast.verdict}`) }}
            </div>

            <!-- The owner's judgement beside the arithmetic above. -->
            <VDivider class="my-3" />
            <div class="text-caption text-medium-emphasis">Owner's reforecast (year end)</div>
            <template v-if="forecast.reforecast !== null">
              <div class="text-h6" :class="(forecast.reforecast_variance ?? 0) < 0 ? 'text-error' : 'text-success'">
                {{ money(forecast.reforecast) }}
              </div>
              <div class="text-caption">
                {{ (forecast.reforecast_variance ?? 0) < 0 ? 'Over budget by' : 'Under budget by' }}
                {{ money(Math.abs(forecast.reforecast_variance ?? 0)) }}
              </div>
              <div class="text-caption text-medium-emphasis">
                Updated {{ forecast.reforecast_at }}<template v-if="forecast.reforecast_by"> by {{ forecast.reforecast_by }}</template>
              </div>
            </template>
            <div v-else class="text-body-2 text-medium-emphasis">
              Not reforecast yet.
              <a v-if="can.update && phasing.length" href="#" @click.prevent="tab = 'phasing'; openForecast()">Reforecast now</a>
            </div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- Anything in flight on this budget -->
    <VCard v-if="approvals.pending.length" border class="mt-4">
      <VCardItem>
        <VCardTitle class="text-body-1">{{ t('budgets_show.pending_title') }}</VCardTitle>
        <VCardSubtitle>{{ t('budgets_show.pending_sub') }}</VCardSubtitle>
        <template #append>
          <VBtn size="small" variant="tonal" @click="router.get(route('budgets.approvals.index'))">
            {{ t('budgets_show.open_approvals') }}
          </VBtn>
        </template>
      </VCardItem>
      <VDivider />
      <VList density="compact">
        <VListItem v-for="row in approvals.pending" :key="row.id">
          <VListItemTitle class="d-flex align-center gap-2">
            {{ row.label }}
            <VChip size="x-small" label>{{ t(`budgets_approvals.subject_${row.subject}`) }}</VChip>
          </VListItemTitle>
          <VListItemSubtitle class="text-caption">
            <template v-if="row.step">{{ t('budgets_show.at_step', { step: row.step, n: row.position, total: row.steps }) }} · </template>
            {{ t('budgets_show.waiting_hours', { count: row.waiting }) }}
          </VListItemSubtitle>
        </VListItem>
      </VList>
    </VCard>

    <!-- Requests: adjustments and overrides -->
    <VRow class="mt-1">
      <VCol cols="12" md="6">
        <VCard border class="h-100">
          <VCardItem>
            <VCardTitle class="text-body-1">{{ t('budgets_show.adjustments.title') }}</VCardTitle>
            <VCardSubtitle>{{ t('budgets_show.adjustments.subtitle') }}</VCardSubtitle>
          </VCardItem>
          <VDivider />
          <VTable density="compact">
            <thead>
              <tr>
                <th>{{ t('budgets_show.adjustments.type') }}</th>
                <th class="text-right">{{ t('budgets_show.adjustments.amount') }}</th>
                <th>{{ t('budgets_show.adjustments.status') }}</th>
                <th>{{ t('budgets_show.adjustments.reason_col') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="!budget.adjustments.length">
                <td colspan="4" class="text-center text-medium-emphasis py-4">{{ t('budgets_show.adjustments.no_adjustments') }}</td>
              </tr>
              <tr v-for="adjustment in budget.adjustments" :key="adjustment.id">
                <td><VChip size="x-small" variant="tonal" label>{{ adjustment.type }}</VChip></td>
                <td class="text-right">{{ money(adjustment.amount) }}</td>
                <td><VChip size="x-small" :color="statusColor[adjustment.status]" variant="tonal" label>{{ adjustment.status }}</VChip></td>
                <td class="text-caption">{{ adjustment.reason ?? '—' }}</td>
              </tr>
            </tbody>
          </VTable>
          <VDivider />
          <VCardText v-if="can.update">
            <div class="text-caption text-medium-emphasis mb-2">{{ t('budgets_show.adjustments.request_adjustment') }}</div>
            <VRow dense>
              <VCol cols="12" md="4">
                <VSelect
                  v-model="adjForm.type"
                  :items="['increase', 'decrease', 'transfer'].map(v => ({ title: t(`budgets_show.adjustments.type_${v}`), value: v }))"
                  :label="t('budgets_show.adjustments.type')" density="compact" variant="outlined" hide-details
                />
              </VCol>
              <VCol cols="12" md="4">
                <VTextField v-model.number="adjForm.amount" type="number" :label="t('budgets_show.adjustments.amount')"
                            :error-messages="adjForm.errors.amount" density="compact" variant="outlined" hide-details="auto" />
              </VCol>
              <VCol v-if="adjForm.type === 'transfer'" cols="12" md="4">
                <VTextField v-model.number="adjForm.transfer_to_budget_id" type="number"
                            :label="t('budgets_show.adjustments.transfer_to_budget_id')"
                            :error-messages="adjForm.errors.transfer_to_budget_id"
                            density="compact" variant="outlined" hide-details="auto" />
              </VCol>
              <VCol cols="12">
                <VTextField v-model="adjForm.reason" :label="t('budgets_show.adjustments.reason')"
                            density="compact" variant="outlined" hide-details />
              </VCol>
            </VRow>
            <div class="d-flex justify-end mt-3">
              <VBtn color="primary" size="small" :loading="adjForm.processing"
                    :disabled="adjForm.amount == null" @click="submitAdjustment">
                {{ t('budgets_show.adjustments.submit') }}
              </VBtn>
            </div>
          </VCardText>
        </VCard>
      </VCol>

      <VCol cols="12" md="6">
        <VCard border class="h-100">
          <VCardItem>
            <VCardTitle class="text-body-1">{{ t('budgets_show.overrides.title') }}</VCardTitle>
            <VCardSubtitle>{{ t('budgets_show.overrides.subtitle') }}</VCardSubtitle>
          </VCardItem>
          <VDivider />
          <VTable density="compact">
            <thead>
              <tr>
                <th class="text-right">{{ t('budgets_show.overrides.amount') }}</th>
                <th>{{ t('budgets_show.overrides.status') }}</th>
                <th>{{ t('budgets_show.overrides.reason_col') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="!budget.overrides.length">
                <td colspan="3" class="text-center text-medium-emphasis py-4">{{ t('budgets_show.overrides.no_overrides') }}</td>
              </tr>
              <tr v-for="override in budget.overrides" :key="override.id">
                <td class="text-right">{{ money(override.amount) }}</td>
                <td><VChip size="x-small" :color="statusColor[override.status]" variant="tonal" label>{{ override.status }}</VChip></td>
                <td class="text-caption">{{ override.reason ?? '—' }}</td>
              </tr>
            </tbody>
          </VTable>
          <VDivider />
          <VCardText v-if="can.update">
            <div class="text-caption text-medium-emphasis mb-2">{{ t('budgets_show.overrides.request_override') }}</div>
            <VRow dense>
              <VCol cols="12" md="4">
                <VTextField v-model.number="ovForm.amount" type="number" :label="t('budgets_show.overrides.amount')"
                            :error-messages="ovForm.errors.amount" density="compact" variant="outlined" hide-details="auto" />
              </VCol>
              <VCol cols="12" md="8">
                <VTextField v-model="ovForm.reason" :label="t('budgets_show.overrides.reason')"
                            :error-messages="ovForm.errors.reason" density="compact" variant="outlined" hide-details="auto" />
              </VCol>
            </VRow>
            <div class="d-flex justify-end mt-3">
              <VBtn color="primary" size="small" :loading="ovForm.processing"
                    :disabled="ovForm.amount == null" @click="submitOverride">
                {{ t('budgets_show.overrides.submit') }}
              </VBtn>
            </div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- Roll-up: where this sits, and what it consolidates to -->
    <VCard border class="mt-4">
      <VCardItem>
        <VCardTitle class="text-body-1">{{ t('budgets_show.rollup.title') }}</VCardTitle>
        <VCardSubtitle>{{ t('budgets_show.rollup.subtitle') }}</VCardSubtitle>
      </VCardItem>
      <VDivider />
      <VCardText>
        <VRow dense>
          <VCol cols="12" md="6">
            <VAutocomplete
              v-model="parentForm.parent_id"
              :items="rollup.options.map(o => ({ title: o.label, value: o.id }))"
              :label="t('budgets_show.rollup.parent')"
              :hint="t('budgets_show.rollup.parent_hint')" persistent-hint
              :error-messages="parentForm.errors.parent_id"
              :readonly="!can.update"
              variant="outlined" density="compact" clearable
            />
          </VCol>
          <VCol v-if="can.update" cols="12" md="3" class="d-flex align-center">
            <VBtn color="primary" size="small" :loading="parentForm.processing" @click="saveParent">
              {{ t('budgets_show.save') }}
            </VBtn>
          </VCol>
        </VRow>

        <template v-if="rollup.children.length">
          <VDivider class="my-4" />
          <div class="text-caption text-medium-emphasis mb-2">
            {{ t('budgets_show.rollup.children', { count: rollup.children.length }) }}
          </div>
          <div class="d-flex flex-wrap gap-1 mb-4">
            <VChip
              v-for="child in rollup.children" :key="child.id" size="small" label link
              @click="router.get(route('budgets.show', child.uuid))"
            >
              {{ child.label }}
            </VChip>
          </div>

          <!-- The number a division head is actually accountable for. A child in overrun pulls
               this down on purpose — flooring each child at zero would hide it. -->
          <div v-if="rollup.consolidated" class="d-flex flex-wrap gap-2">
            <VChip variant="tonal">
              {{ t('budgets_show.rollup.total_allocated', { amount: money(rollup.consolidated.allocated) }) }}
            </VChip>
            <VChip color="primary" variant="tonal">
              {{ t('budgets_show.rollup.total_actual', { amount: money(rollup.consolidated.actual) }) }}
            </VChip>
            <VChip :color="rollup.consolidated.available < 0 ? 'error' : 'success'" variant="flat">
              {{ t('budgets_show.rollup.total_available', { amount: money(rollup.consolidated.available) }) }}
            </VChip>
            <VChip variant="tonal">{{ rollup.consolidated.committed_pct }}%</VChip>
          </div>
        </template>
      </VCardText>
    </VCard>

    <!-- Owners: who the alerts reach, and who a "budget owner" approval step resolves to -->
    <VCard border class="mt-4">
      <VCardItem>
        <VCardTitle class="text-body-1">{{ t('budgets_show.owners.title') }}</VCardTitle>
        <VCardSubtitle>{{ t('budgets_show.owners.subtitle') }}</VCardSubtitle>
      </VCardItem>
      <VDivider />
      <VCardText>
        <div v-if="owners.length" class="d-flex flex-wrap gap-2 mb-3">
          <VChip
            v-for="owner in owners" :key="owner.id"
            :closable="can.update" variant="tonal"
            :color="owner.role === 'delegate' ? 'secondary' : 'primary'"
            @click:close="removeOwner(owner.id)"
          >
            {{ owner.name }} · {{ t(`budgets_show.owners.role_${owner.role}`) }}
          </VChip>
        </div>
        <!-- Said plainly: an unowned budget's warnings fall back to whoever holds the grant. -->
        <VAlert v-else type="warning" variant="tonal" density="compact" class="mb-3">
          {{ t('budgets_show.owners.none') }}
        </VAlert>

        <VRow v-if="can.update" dense>
          <VCol cols="12" md="6">
            <VAutocomplete
              v-model="ownerForm.user_id" :items="people.map(p => ({ title: p.label, value: p.id }))"
              :label="t('budgets_show.owners.add')" :error-messages="ownerForm.errors.user_id"
              variant="outlined" density="compact" hide-details="auto"
            />
          </VCol>
          <VCol cols="12" md="3">
            <VSelect
              v-model="ownerForm.role"
              :items="[
                { title: t('budgets_show.owners.role_owner'), value: 'owner' },
                { title: t('budgets_show.owners.role_delegate'), value: 'delegate' },
              ]"
              :label="t('budgets_show.owners.role')" variant="outlined" density="compact" hide-details
            />
          </VCol>
          <VCol cols="12" md="3" class="d-flex align-center">
            <VBtn color="primary" size="small" :loading="ownerForm.processing"
                  :disabled="!ownerForm.user_id" @click="addOwner">
              {{ t('budgets_show.owners.add_action') }}
            </VBtn>
          </VCol>
        </VRow>
      </VCardText>
    </VCard>

    <!-- Detail tabs -->
    <VCard border class="mt-4">
      <VTabs v-model="tab">
        <VTab value="ledger">{{ t('budgets_show.ledger.title') }}</VTab>
        <VTab value="phasing">
          {{ t('budgets_show.phasing.title') }}
          <VChip v-if="unexplainedCount" size="x-small" color="warning" class="ms-2" label>{{ unexplainedCount }}</VChip>
        </VTab>
        <VTab value="trail">{{ t('budgets_show.trail.title') }}</VTab>
        <VTab value="revisions">{{ t('budgets_show.revisions.title') }}</VTab>
      </VTabs>
      <VDivider />

      <!-- Ledger -->
      <div v-if="tab === 'ledger'">
        <VTable density="compact">
          <thead>
            <tr>
              <th>{{ t('budgets_show.ledger.kind') }}</th>
              <th class="text-right">{{ t('budgets_show.ledger.amount') }}</th>
              <th>{{ t('budgets_show.ledger.status') }}</th>
              <th>{{ t('budgets_show.ledger.source_type') }}</th>
              <th>{{ t('budgets_show.ledger.date') }}</th>
              <th />
            </tr>
          </thead>
          <tbody>
            <tr v-if="!budget.reservations.length && !budget.commitments.length && !budget.consumptions.length">
              <td colspan="6" class="text-center text-medium-emphasis py-6">{{ t('budgets_show.ledger.empty') }}</td>
            </tr>
            <tr v-for="row in budget.reservations" :key="`r${row.id}`">
              <td><VChip size="x-small" color="warning" variant="tonal" label>{{ t('budgets_show.ledger.reservation') }}</VChip></td>
              <td class="text-right">{{ money(row.amount) }}</td>
              <td>{{ row.status }}</td>
              <td class="text-caption">{{ row.source_type ?? '—' }}<template v-if="row.source_id"> #{{ row.source_id }}</template></td>
              <td class="text-caption">{{ row.created_at ?? '—' }}</td>
              <td class="text-right">
                <VBtn v-if="can.update && row.status === 'active'" size="x-small" variant="text"
                      @click="releaseReservation(row.id)">
                  {{ t('budgets_show.ledger.release') }}
                </VBtn>
              </td>
            </tr>
            <tr v-for="row in budget.commitments" :key="`c${row.id}`">
              <td><VChip size="x-small" color="info" variant="tonal" label>{{ t('budgets_show.ledger.commitment') }}</VChip></td>
              <td class="text-right">{{ money(row.amount) }}</td>
              <td>{{ row.status }}</td>
              <td class="text-caption">{{ row.source_type ?? '—' }}<template v-if="row.source_id"> #{{ row.source_id }}</template></td>
              <td class="text-caption">{{ row.created_at ?? '—' }}</td>
              <td class="text-right">
                <VBtn v-if="can.update && row.status === 'active'" size="x-small" variant="text"
                      @click="releaseCommitment(row.id)">
                  {{ t('budgets_show.ledger.release') }}
                </VBtn>
              </td>
            </tr>
            <tr v-for="row in budget.consumptions" :key="`x${row.id}`">
              <td><VChip size="x-small" color="primary" variant="tonal" label>{{ t('budgets_show.ledger.consumption') }}</VChip></td>
              <td class="text-right">{{ money(row.amount) }}</td>
              <td class="text-caption text-medium-emphasis">{{ t('budgets_show.ledger.posted') }}</td>
              <td class="text-caption">{{ row.source_type ?? '—' }}<template v-if="row.source_id"> #{{ row.source_id }}</template></td>
              <td class="text-caption">{{ row.created_at ?? '—' }}</td>
              <td />
            </tr>
          </tbody>
        </VTable>
      </div>

      <!-- Phasing -->
      <div v-else-if="tab === 'phasing'">
        <VCardText v-if="!phasing.length" class="text-center text-medium-emphasis py-8">
          <p class="mb-2">{{ t('budgets_show.phasing.none') }}</p>
          <VBtn size="small" variant="tonal" @click="router.get(route('budgets.settings.index'))">
            {{ t('budgets_show.phasing.define_periods') }}
          </VBtn>
        </VCardText>

        <template v-else>
          <div class="d-flex justify-end gap-2 pa-3">
            <VAlert v-if="unexplainedCount" type="warning" variant="tonal" density="compact" class="me-auto">
              {{ unexplainedCount }} closed period(s) missed plan by {{ varianceThreshold }}% or more and need an explanation.
            </VAlert>
            <VBtn v-if="can.update && !phasingEdit && !forecastEdit" size="small" variant="tonal" color="secondary"
                  prepend-icon="tabler-trending-up" @click="openForecast">
              Reforecast
            </VBtn>
            <template v-if="forecastEdit">
              <span class="text-caption text-medium-emphasis align-self-center me-2">
                Year-end outlook: {{ money(forecastPreview) }}
              </span>
              <VBtn size="small" variant="text" @click="forecastEdit = false">{{ t('budgets_show.cancel') }}</VBtn>
              <VBtn size="small" color="primary" :loading="forecastForm.processing" @click="saveForecast">
                {{ t('budgets_show.save') }}
              </VBtn>
            </template>
            <VBtn v-if="can.update && !phasingEdit && !forecastEdit" size="small" variant="text" prepend-icon="tabler-columns"
                  @click="spreadEvenly">
              {{ t('budgets_show.phasing.spread') }}
            </VBtn>
            <VBtn v-if="can.update && !phasingEdit && !forecastEdit" size="small" variant="tonal" prepend-icon="tabler-edit"
                  @click="openPhasing">
              {{ t('budgets_show.phasing.edit') }}
            </VBtn>
            <template v-if="phasingEdit">
              <span class="text-caption text-medium-emphasis align-self-center me-2">
                {{ t('budgets_show.phasing.total', { amount: money(phasingTotal) }) }}
              </span>
              <VBtn size="small" variant="text" @click="phasingEdit = false">{{ t('budgets_show.cancel') }}</VBtn>
              <VBtn size="small" color="primary" :loading="phasingForm.processing" @click="savePhasing">
                {{ t('budgets_show.save') }}
              </VBtn>
            </template>
          </div>

          <VTable density="compact">
            <thead>
              <tr>
                <th>{{ t('budgets_show.phasing.period') }}</th>
                <th class="text-right">{{ t('budgets_show.phasing.planned') }}</th>
                <th class="text-right">{{ t('budgets_show.phasing.actual') }}</th>
                <th class="text-right">{{ t('budgets_show.phasing.variance') }}</th>
                <th class="text-right">Forecast</th>
                <th class="text-right">Outlook</th>
                <th class="text-right">{{ t('budgets_show.phasing.cumulative') }}</th>
                <th>Variance explanation</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(row, i) in phasing" :key="row.period_id">
                <td>{{ row.label }}</td>
                <td class="text-right">
                  <VTextField
                    v-if="phasingEdit" v-model.number="phasingForm.lines[i].amount" type="number"
                    density="compact" variant="outlined" hide-details style="max-inline-size: 140px"
                  />
                  <template v-else>{{ money(row.planned) }}</template>
                </td>
                <td class="text-right">{{ money(row.actual) }}</td>
                <td class="text-right" :class="row.variance < 0 ? 'text-error' : ''">
                  {{ money(row.variance) }}
                  <div v-if="row.closed && row.planned > 0" class="text-caption text-medium-emphasis">{{ row.variance_pct }}%</div>
                </td>
                <td class="text-right">
                  <span v-if="row.closed" class="text-caption text-disabled">actual</span>
                  <VTextField
                    v-else-if="forecastEdit" v-model="forecastForm.lines[i].amount" type="number" min="0"
                    :placeholder="String(row.planned)" density="compact" variant="outlined" hide-details
                    style="max-inline-size: 140px"
                  />
                  <template v-else>
                    {{ row.forecast === null ? '—' : money(row.forecast) }}
                  </template>
                </td>
                <td class="text-right">{{ money(row.outlook) }}</td>
                <td class="text-right text-caption">
                  {{ money(row.cumulative_actual) }} / {{ money(row.cumulative_planned) }}
                </td>
                <td style="max-inline-size: 320px">
                  <template v-if="row.material">
                    <div v-if="row.explanation" class="text-caption">
                      {{ row.explanation }}
                      <div class="text-disabled">{{ row.explained_by }} · {{ row.explained_at }}</div>
                    </div>
                    <VChip v-else size="x-small" color="warning" label>Explanation required</VChip>
                    <VBtn v-if="can.update" size="x-small" variant="text" class="ms-1" @click="openExplain(row)">
                      {{ row.explanation ? 'Edit' : 'Explain' }}
                    </VBtn>
                  </template>
                  <span v-else class="text-caption text-disabled">{{ row.closed ? 'Within threshold' : '—' }}</span>
                </td>
              </tr>
            </tbody>
          </VTable>
        </template>
      </div>

      <!-- Approval trail -->
      <div v-else-if="tab === 'trail'">
        <VTable v-if="approvals.trail.length" density="compact">
          <thead>
            <tr>
              <th>{{ t('budgets_show.trail.what') }}</th>
              <th>{{ t('budgets_show.trail.who') }}</th>
              <th>{{ t('budgets_show.trail.decision') }}</th>
              <th>{{ t('budgets_show.trail.comment') }}</th>
              <th>{{ t('budgets_show.trail.when') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(row, i) in approvals.trail" :key="i">
              <td>
                {{ row.label ?? '—' }}
                <div class="text-caption text-medium-emphasis">{{ t(`budgets_approvals.subject_${row.subject}`) }}</div>
              </td>
              <td>{{ row.actor ?? '—' }}</td>
              <td>
                <VChip size="x-small" label
                       :color="row.decision === 'approved' ? 'success' : row.decision === 'rejected' ? 'error' : 'grey'">
                  {{ row.decision }}
                </VChip>
              </td>
              <td class="text-caption">{{ row.comment ?? '—' }}</td>
              <td class="text-caption">{{ row.at ?? '—' }}</td>
            </tr>
          </tbody>
        </VTable>
        <VCardText v-else class="text-center text-medium-emphasis py-8">{{ t('budgets_show.trail.empty') }}</VCardText>
      </div>

      <!-- Revisions -->
      <div v-else>
        <VTable v-if="revisions.length" density="compact">
          <thead>
            <tr>
              <th>{{ t('budgets_show.revisions.version') }}</th>
              <th class="text-right">{{ t('budgets_show.revisions.from') }}</th>
              <th class="text-right">{{ t('budgets_show.revisions.to') }}</th>
              <th>{{ t('budgets_show.revisions.why') }}</th>
              <th>{{ t('budgets_show.revisions.when') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in revisions" :key="row.version">
              <td>v{{ row.version }}</td>
              <td class="text-right">{{ money(row.previous) }}</td>
              <td class="text-right">{{ money(row.amount) }}</td>
              <td>
                <VChip size="x-small" variant="tonal" label>{{ t(`budgets_show.revisions.type_${row.type}`) }}</VChip>
                <div v-if="row.reason" class="text-caption text-medium-emphasis">{{ row.reason }}</div>
              </td>
              <td class="text-caption">{{ row.at ?? '—' }}</td>
            </tr>
          </tbody>
        </VTable>
        <VCardText v-else class="text-center text-medium-emphasis py-8">{{ t('budgets_show.revisions.empty') }}</VCardText>
      </div>
    </VCard>

    <!-- Submit for approval -->
    <VDialog v-model="submitDialog" max-width="520">
      <VCard>
        <VCardItem><VCardTitle>{{ t('budgets_show.submit_for_approval') }}</VCardTitle></VCardItem>
        <VDivider />
        <VCardText>
          <p class="text-body-2 text-medium-emphasis">{{ t('budgets_show.submit_body', { amount: money(amounts.allocated) }) }}</p>
          <VTextarea
            v-model="submitForm.note" rows="3"
            :label="t('budgets_show.submit_note')"
            :hint="t('budgets_show.submit_note_hint')" persistent-hint
            variant="outlined" density="compact"
          />
        </VCardText>
        <VDivider />
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="submitDialog = false">{{ t('budgets_show.cancel') }}</VBtn>
          <VBtn color="primary" :loading="submitForm.processing" @click="submitForApproval">
            {{ t('budgets_show.submit') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Manual ledger entry -->
    <VDialog v-model="ledgerDialog" max-width="560">
      <VCard>
        <VCardItem>
          <VCardTitle>{{ t(`budgets_show.ledger.${ledgerKind}_title`) }}</VCardTitle>
          <VCardSubtitle>{{ t(`budgets_show.ledger.${ledgerKind}_sub`) }}</VCardSubtitle>
        </VCardItem>
        <VDivider />
        <VCardText>
          <VRow dense>
            <VCol cols="12" md="6">
              <VTextField
                v-model.number="ledgerForm.amount" type="number" autofocus
                :label="t('budgets_show.ledger.amount')"
                :error-messages="ledgerForm.errors.amount"
                variant="outlined" density="compact"
              />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField
                v-model="ledgerForm.source_type"
                :label="t('budgets_show.ledger.source_type')"
                :hint="t('budgets_show.ledger.source_hint')" persistent-hint
                variant="outlined" density="compact"
              />
            </VCol>

            <VCol v-if="ledgerKind === 'commit' && activeReservations.length" cols="12">
              <VSelect
                v-model="ledgerForm.reservation_id"
                :items="activeReservations.map(r => ({ title: `${money(r.amount)} · ${r.source_type ?? '—'}`, value: r.id }))"
                :label="t('budgets_show.ledger.against_reservation')"
                :hint="t('budgets_show.ledger.against_reservation_hint')" persistent-hint
                :error-messages="ledgerForm.errors.reservation_id"
                variant="outlined" density="compact" clearable
              />
            </VCol>

            <VCol v-if="ledgerKind === 'consume' && activeCommitments.length" cols="12">
              <VSelect
                v-model="ledgerForm.commitment_id"
                :items="activeCommitments.map(c => ({ title: `${money(c.amount)} · ${c.source_type ?? '—'}`, value: c.id }))"
                :label="t('budgets_show.ledger.against_commitment')"
                :hint="t('budgets_show.ledger.against_commitment_hint')" persistent-hint
                :error-messages="ledgerForm.errors.commitment_id"
                variant="outlined" density="compact" clearable
              />
            </VCol>

            <VCol v-if="ledgerKind !== 'commit'" cols="12">
              <VTextField v-model="ledgerForm.note" :label="t('budgets_show.ledger.note')"
                          variant="outlined" density="compact" />
            </VCol>
          </VRow>
        </VCardText>
        <VDivider />
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="ledgerDialog = false">{{ t('budgets_show.cancel') }}</VBtn>
          <VBtn color="primary" :loading="ledgerForm.processing" :disabled="ledgerForm.amount == null" @click="saveLedger">
            {{ t('budgets_show.save') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Explain a variance -->
    <VDialog v-model="explainDialog" max-width="560">
      <VCard v-if="explainTarget">
        <VCardTitle class="text-body-1">Explain the variance · {{ explainTarget.label }}</VCardTitle>
        <VCardSubtitle>
          Planned {{ money(explainTarget.planned) }}, actual {{ money(explainTarget.actual) }}
          ({{ explainTarget.variance < 0 ? 'over' : 'under' }} by {{ money(Math.abs(explainTarget.variance)) }}, {{ explainTarget.variance_pct }}%)
        </VCardSubtitle>
        <VCardText>
          <VTextarea
            v-model="explainForm.explanation" rows="4" auto-grow counter="2000"
            label="Why did this period differ from plan, and what is being done about it?"
            :error-messages="explainForm.errors.explanation"
          />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="explainDialog = false">{{ t('budgets_show.cancel') }}</VBtn>
          <VBtn color="primary" :loading="explainForm.processing" :disabled="explainForm.explanation.trim().length < 5" @click="saveExplanation">
            {{ t('budgets_show.save') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>

<style scoped>
.fund-bar {
  block-size: 14px;
  border-radius: 7px;
  overflow: hidden;
  background: rgba(var(--v-theme-on-surface), 0.08);
}

.fund-seg {
  block-size: 100%;
}
</style>
