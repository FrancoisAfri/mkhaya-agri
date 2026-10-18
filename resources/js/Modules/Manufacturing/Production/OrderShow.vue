<script setup lang="ts">
import { computed, ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import { orderStatusColor } from './status'
import { fulfilmentFlagColor } from '../Fulfilment/colors'
import RecordDocuments from '@/Modules/DocumentManagement/Components/RecordDocuments.vue'

const { t } = useI18n()
const route = ziggyRoute

type Costs = { material: number; labour: number; machine: number; overhead: number; subcontract: number | null; total: number }
type Component = {
  id: string; code: string | null; name: string | null; kind: string; is_consumed: boolean; uom: string | null
  required: number; issued: number; returned: number; net_issued: number; outstanding: number
  unit_cost: number; issued_value: number; available: number | null
  lots: Array<{ id: number; lot: string; expires_on: string | null; qty: number }>
}
type Operation = {
  id: string; sequence: number; name: string; work_centre: string | null; parallel_group: string | null
  is_mandatory: boolean; is_gate: boolean; is_inspection_point: boolean; is_approval_gate: boolean; is_subcontracted: boolean
  planned_minutes: number; status: string; gate_status: string | null; qty_completed: number; qty_rejected: number
  qty_scrapped: number; actual_machine_minutes: number; actual_labour_minutes: number
  started_at: string | null; completed_at: string | null; blocked_by: string[]
}
type Event = {
  id: string; action: string; from: string | null; to: string | null; is_exception: boolean
  reason: string | null; detail: Record<string, unknown> | null; actor: string | null; acted_at: string | null
}

const props = defineProps<{
  order: {
    id: string; number: string; status: string; product_id: string | null; product_code: string | null; product_name: string | null
    factory: string | null; planned_qty: number; qty_completed: number; qty_scrapped: number; qty_reworked: number
    due_date: string | null; priority: number; demand_type: string; demand_reference: string | null; notes: string | null
    is_overdue: boolean; estimated_total: number; planned_start: string | null; planned_finish: string | null
    status_before_hold: string | null; bom: { id: string; revision: number } | null; routing: { id: string; revision: number } | null
    warehouse: string | null; component_warehouse: string | null; supervisor: string | null
    costs: { estimated: Costs; actual: Costs }; wip: number
    approved_at: string | null; released_at: string | null; started_at: string | null; completed_at: string | null; closed_at: string | null
  }
  components: Component[]
  operations: Operation[]
  events: Event[]
  checks: null | {
    bom_problem: string | null; routing_problem: string | null
    shortages: Array<{ item: string; needed: number; available: number; short: number }>
    capacity_conflicts: Array<{ work_centre: string; required_minutes: number; available_minutes: number }>
  }
  reconciliation: null | {
    planned: number; good: number; scrapped: number; reworked: number; remaining: number; yield_percent: number | null
    operations_open: number
    materials: Array<{ item: string | null; required: number; expected_for_output: number; consumed: number; variance: number }>
  }
  inspections: Array<{ id: string; number: string; stage: string; status: string; result: string; disposition: string | null; qty: number; lot: string | null; operation: string | null }>
  quality_hold: string[]
  cost_analysis: null | {
    standard: { id: string; version: number; unit_cost: number; for_output: number } | null
    actual: { total: number; unit_cost: number | null }
    status: string; outstanding: string[]
    variances: Array<{ kind: string; amount: number | null; method: string; inputs: Record<string, any>; data_quality: string; notes: string | null; additive: boolean }>
  }
  documents_panel: {
    available: boolean; relation: string | null; record: string | number | null; can_upload: boolean; count: number
    documents: Array<{ uuid: string; title: string; category: string | null; expiry_date: string | null; is_expired: boolean; uploaded_at: string | null }>
  }
  people: Array<{ id: number; name: string }>
  customer: null | {
    id: string; sales_order: string | null; sales_order_status: string | null; customer: string | null; customer_ref: string | null
    strategy: string; configuration: string | null; ordered: number; delivered: number; outstanding: number; ready: number
    required_date: string | null; promised_date: string | null; projected_finish: string | null; flag: string; risks: string[]
    references: { deliveries: Array<{ number: string; date: string; qty: number }>; invoice: { number: string; status: string; date: string } | null }
  }
  subcontracts: Array<{ id: string; number: string; status: string; supplier: string | null; operation: string | null; qty: number; received: number; due_date: string | null }>
  can: Record<string, boolean>
}>()

const tab = ref('materials')
const busy = ref(false)
const key = () => (globalThis.crypto?.randomUUID?.() ?? `${Date.now()}-${Math.random()}`)
const num = (n: number | null | undefined, d = 4) => n == null ? '—' : Number(n).toLocaleString(undefined, { maximumFractionDigits: d })
const money = (n: number | null | undefined) => n == null ? '—' : Number(n).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const when = (s: string | null) => s ? new Date(s).toLocaleString() : '—'
const u = (name: string, extra: Record<string, string> = {}) => route(`manufacturing.production.orders.${name}`, { uuid: props.order.id, ...extra })

function post(url: string, data: Record<string, unknown> = {}, done?: () => void) {
  busy.value = true
  router.post(url, data, { preserveScroll: true, onSuccess: () => done?.(), onFinish: () => { busy.value = false } })
}

// ── Lifecycle ───────────────────────────────────────────────────────────────
const step = (name: string) => post(u(name))

/** Steps that must say why: the reason goes on the order's history. */
const reasonStep = ref<null | 'reject' | 'hold' | 'cancel' | 'return-to-production'>(null)
const reason = ref('')
function submitReason() {
  post(u(reasonStep.value!), { reason: reason.value }, () => { reasonStep.value = null; reason.value = '' })
}

const hasBlockers = computed(() => !!props.checks && (props.checks.shortages.length > 0 || props.checks.capacity_conflicts.length > 0))
const approveDialog = ref(false)
const overrideReason = ref('')
function approve() {
  if (hasBlockers.value && props.can.override) { approveDialog.value = true; return }
  step('approve')
}
function submitApprove() {
  post(u('approve'), { override_reason: overrideReason.value || null }, () => { approveDialog.value = false; overrideReason.value = '' })
}

// ── Draft edit ──────────────────────────────────────────────────────────────
const editDialog = ref(false)
const edit = useForm({
  quantity: props.order.planned_qty, due_date: props.order.due_date ?? '', priority: props.order.priority,
  supervisor_id: null as number | null, demand_reference: props.order.demand_reference ?? '', notes: props.order.notes ?? '',
})
function saveEdit() {
  edit.patch(u('update'), { preserveScroll: true, onSuccess: () => { editDialog.value = false } })
}

// ── Material ────────────────────────────────────────────────────────────────
const material = ref<null | { mode: 'issue' | 'return'; component: Component }>(null)
const materialForm = ref({ qty: null as number | null, lot_id: null as number | null, override_reason: '', idempotency_key: '' })
function openMaterial(mode: 'issue' | 'return', component: Component) {
  material.value = { mode, component }
  materialForm.value = {
    qty: mode === 'issue' ? Math.max(0, component.outstanding) || null : component.net_issued || null,
    lot_id: null, override_reason: '', idempotency_key: key(),
  }
}
const overIssue = computed(() => material.value?.mode === 'issue'
  && (materialForm.value.qty ?? 0) > (material.value.component.outstanding + 0.0001))
function submitMaterial() {
  const m = material.value!
  const name = m.mode === 'issue' ? 'components.issue' : 'components.return'
  post(u(name, { componentUuid: m.component.id }), {
    qty: materialForm.value.qty, lot_id: materialForm.value.lot_id,
    override_reason: materialForm.value.override_reason || null, idempotency_key: materialForm.value.idempotency_key,
  }, () => { material.value = null })
}

// ── Operations ──────────────────────────────────────────────────────────────
const opStep = (name: 'start' | 'pause', op: Operation) => post(u(`operations.${name}`, { operationUuid: op.id }))

const completing = ref<Operation | null>(null)
const opForm = ref({ completed: 0, rejected: 0, scrapped: 0, reworked: 0, machine_minutes: 0, labour_minutes: 0, override_reason: '' })
function openComplete(op: Operation) {
  completing.value = op
  opForm.value = {
    completed: Math.max(0, props.order.planned_qty), rejected: 0, scrapped: 0, reworked: 0,
    machine_minutes: Math.round(op.planned_minutes), labour_minutes: 0, override_reason: '',
  }
}
function submitComplete() {
  post(u('operations.complete', { operationUuid: completing.value!.id }), { ...opForm.value, override_reason: opForm.value.override_reason || null },
    () => { completing.value = null })
}

const gating = ref<Operation | null>(null)
const gateForm = ref({ result: 'PASSED', notes: '' })
function submitGate() {
  post(u('operations.gate', { operationUuid: gating.value!.id }), gateForm.value, () => { gating.value = null; gateForm.value = { result: 'PASSED', notes: '' } })
}

const opColor = (s: string) => ({ PENDING: 'grey', READY: 'info', IN_PROGRESS: 'warning', PAUSED: 'orange', COMPLETED: 'success', SKIPPED: 'grey' } as Record<string, string>)[s] ?? 'default'

// ── Output and loss ─────────────────────────────────────────────────────────
const outputDialog = ref(false)
const outputForm = ref({ qty: null as number | null, lot_number: '', idempotency_key: '' })
function openOutput() {
  outputDialog.value = true
  outputForm.value = { qty: Math.max(0, props.order.planned_qty - props.order.qty_completed) || null, lot_number: '', idempotency_key: key() }
}
function submitOutput() {
  post(u('output'), { ...outputForm.value, lot_number: outputForm.value.lot_number || null }, () => { outputDialog.value = false })
}

const lossDialog = ref(false)
const lossForm = ref({ scrapped: 0, reworked: 0, reason: '' })
function submitLoss() {
  post(u('loss'), lossForm.value, () => { lossDialog.value = false; lossForm.value = { scrapped: 0, reworked: 0, reason: '' } })
}

const costRows = computed(() => (['material', 'labour', 'machine', 'overhead', 'subcontract', 'total'] as const).map(k => ({
  key: k, estimated: props.order.costs.estimated[k], actual: props.order.costs.actual[k],
})))
const progress = computed(() => props.order.planned_qty > 0 ? Math.min(100, props.order.qty_completed / props.order.planned_qty * 100) : 0)
</script>

<template>
  <div class="pa-4">
    <!-- Header -->
    <div class="d-flex align-start justify-space-between mb-4 flex-wrap gap-2">
      <div>
        <VBtn variant="text" size="small" prepend-icon="tabler-arrow-left" class="mb-1 px-0"
              :href="route('manufacturing.production.orders.index')">{{ t('manufacturing.orders.back') }}</VBtn>
        <h4 class="text-h4">{{ order.number }}</h4>
        <div class="text-body-2 text-medium-emphasis">
          {{ order.product_code }} — {{ order.product_name }} · {{ order.factory }}
        </div>
        <div class="d-flex gap-2 mt-2 flex-wrap">
          <VChip size="small" label variant="tonal" :color="orderStatusColor(order.status)">{{ t(`manufacturing.order_status.${order.status}`) }}</VChip>
          <VChip v-if="order.is_overdue" size="small" label color="error">{{ t('manufacturing.orders.overdue') }}</VChip>
          <VChip size="small" label variant="outlined">{{ t(`manufacturing.orders.priority_${order.priority}`) }}</VChip>
        </div>
      </div>
      <div class="d-flex gap-2 flex-wrap">
        <VBtn variant="text" prepend-icon="tabler-qrcode" :href="route('manufacturing.production.orders.job-card', order.id)" target="_blank">{{ t('manufacturing.floor.job_card') }}</VBtn>
        <VBtn v-if="can.edit" variant="tonal" prepend-icon="tabler-pencil" @click="editDialog = true">{{ t('manufacturing.common.edit') }}</VBtn>
        <VBtn v-if="can.plan" color="primary" prepend-icon="tabler-calendar" :loading="busy" @click="step('plan')">{{ t('manufacturing.orders.action.plan') }}</VBtn>
        <VBtn v-if="can.unplan" variant="tonal" :loading="busy" @click="step('unplan')">{{ t('manufacturing.orders.action.unplan') }}</VBtn>
        <VBtn v-if="can.approve" color="success" prepend-icon="tabler-check" :loading="busy" @click="approve">{{ t('manufacturing.common.approve') }}</VBtn>
        <VBtn v-if="can.reject" variant="tonal" color="error" prepend-icon="tabler-x" @click="reasonStep = 'reject'">{{ t('manufacturing.orders.action.reject') }}</VBtn>
        <VBtn v-if="can.reopen" variant="tonal" :loading="busy" @click="step('reopen')">{{ t('manufacturing.orders.action.reopen') }}</VBtn>
        <VBtn v-if="can.release" color="indigo" prepend-icon="tabler-player-play" :loading="busy" @click="step('release')">{{ t('manufacturing.orders.action.release') }}</VBtn>
        <VBtn v-if="can.send_to_quality" color="purple" prepend-icon="tabler-clipboard-check" :loading="busy" @click="step('send-to-quality')">{{ t('manufacturing.orders.action.send_to_quality') }}</VBtn>
        <VBtn v-if="can.return_to_production" variant="tonal" color="warning" @click="reasonStep = 'return-to-production'">{{ t('manufacturing.orders.action.return_to_production') }}</VBtn>
        <VBtn v-if="can.complete" color="success" prepend-icon="tabler-circle-check" :loading="busy" @click="step('complete')">{{ t('manufacturing.orders.action.complete') }}</VBtn>
        <VBtn v-if="can.close" color="teal" prepend-icon="tabler-lock" :loading="busy" @click="step('close')">{{ t('manufacturing.orders.action.close') }}</VBtn>
        <VBtn v-if="can.resume" color="primary" :loading="busy" @click="step('resume')">{{ t('manufacturing.orders.action.resume') }}</VBtn>
        <VBtn v-if="can.hold" variant="tonal" color="orange" @click="reasonStep = 'hold'">{{ t('manufacturing.orders.action.hold') }}</VBtn>
        <VBtn v-if="can.cancel" variant="text" color="error" @click="reasonStep = 'cancel'">{{ t('manufacturing.orders.action.cancel') }}</VBtn>
      </div>
    </div>

    <VAlert v-if="quality_hold.length" type="error" variant="tonal" class="mb-4">
      {{ t('manufacturing.orders.quality_hold', { list: quality_hold.join(', ') }) }}
    </VAlert>
    <VAlert v-if="order.status === 'ON_HOLD'" type="warning" variant="tonal" class="mb-4">
      {{ t('manufacturing.orders.on_hold', { status: t(`manufacturing.order_status.${order.status_before_hold ?? 'PLANNED'}`) }) }}
    </VAlert>
    <VAlert v-else-if="order.status === 'CLOSED'" type="info" variant="tonal" class="mb-4">{{ t('manufacturing.orders.locked_closed') }}</VAlert>

    <!-- Summary -->
    <VRow class="mb-1">
      <VCol cols="12" md="8">
        <VCard border class="h-100">
          <VCardText>
            <VRow dense>
              <VCol cols="6" sm="3"><div class="text-caption text-medium-emphasis">{{ t('manufacturing.orders.quantity') }}</div><div class="text-h6">{{ num(order.planned_qty) }}</div></VCol>
              <VCol cols="6" sm="3"><div class="text-caption text-medium-emphasis">{{ t('manufacturing.orders.made') }}</div><div class="text-h6">{{ num(order.qty_completed) }}</div></VCol>
              <VCol cols="6" sm="3"><div class="text-caption text-medium-emphasis">{{ t('manufacturing.orders.due') }}</div><div class="text-h6">{{ order.due_date }}</div></VCol>
              <VCol cols="6" sm="3"><div class="text-caption text-medium-emphasis">{{ t('manufacturing.orders.planned_window') }}</div><div class="text-body-1">{{ order.planned_start ?? '—' }} → {{ order.planned_finish ?? '—' }}</div></VCol>
            </VRow>
            <VProgressLinear :model-value="progress" color="success" height="6" rounded class="my-3" />
            <VRow dense class="text-body-2">
              <VCol cols="12" sm="6">
                <div><span class="text-medium-emphasis">{{ t('manufacturing.orders.bom') }}:</span>
                  <a v-if="order.bom" :href="route('manufacturing.engineering.boms.show', order.bom.id)">{{ t('manufacturing.bom.revision_n', { n: order.bom.revision }) }}</a></div>
                <div><span class="text-medium-emphasis">{{ t('manufacturing.orders.routing') }}:</span>
                  <a v-if="order.routing" :href="route('manufacturing.engineering.routings.show', order.routing.id)">{{ t('manufacturing.routing.revision_n', { n: order.routing.revision }) }}</a>
                  <span v-else>{{ t('manufacturing.orders.no_routing') }}</span></div>
                <div><span class="text-medium-emphasis">{{ t('manufacturing.orders.supervisor') }}:</span> {{ order.supervisor ?? '—' }}</div>
              </VCol>
              <VCol cols="12" sm="6">
                <div><span class="text-medium-emphasis">{{ t('manufacturing.orders.output_warehouse') }}:</span> {{ order.warehouse }}</div>
                <div><span class="text-medium-emphasis">{{ t('manufacturing.orders.material_warehouse') }}:</span> {{ order.component_warehouse }}</div>
                <div><span class="text-medium-emphasis">{{ t('manufacturing.orders.demand') }}:</span>
                  {{ t(`manufacturing.orders.demand_${order.demand_type}`) }}<template v-if="order.demand_reference"> · {{ order.demand_reference }}</template></div>
              </VCol>
            </VRow>
            <div v-if="order.notes" class="text-body-2 mt-2">{{ order.notes }}</div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="12" md="4">
        <VCard border class="h-100">
          <VCardText>
            <div class="text-caption text-medium-emphasis">{{ t('manufacturing.orders.estimate') }}</div>
            <div class="text-h5 mb-2">{{ money(order.costs.estimated.total) }}</div>
            <div class="text-caption text-medium-emphasis">{{ t('manufacturing.orders.actual') }}</div>
            <div class="text-h5 mb-2">{{ money(order.costs.actual.total) }}</div>
            <div class="text-caption text-medium-emphasis">{{ t('manufacturing.orders.wip') }}</div>
            <div class="text-h6">{{ money(order.wip) }}</div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- What approval will check, shown before anybody presses Approve -->
    <VCard v-if="checks" border class="mb-4">
      <VCardItem>
        <VCardTitle>{{ t('manufacturing.orders.checks') }}</VCardTitle>
        <VCardSubtitle>{{ t('manufacturing.orders.checks_hint') }}</VCardSubtitle>
      </VCardItem>
      <VCardText>
        <VAlert v-if="checks.bom_problem" type="error" variant="tonal" density="compact" class="mb-2">{{ checks.bom_problem }}</VAlert>
        <VAlert v-if="checks.routing_problem" type="error" variant="tonal" density="compact" class="mb-2">{{ checks.routing_problem }}</VAlert>
        <VAlert v-if="!checks.bom_problem && !checks.routing_problem && !hasBlockers" type="success" variant="tonal" density="compact">
          {{ t('manufacturing.orders.checks_clear') }}
        </VAlert>
        <template v-if="checks.shortages.length">
          <div class="text-subtitle-2 mt-2">{{ t('manufacturing.orders.shortages') }}</div>
          <VTable density="compact">
            <thead><tr><th>{{ t('manufacturing.orders.material') }}</th><th class="text-end">{{ t('manufacturing.orders.needed') }}</th><th class="text-end">{{ t('manufacturing.orders.available') }}</th><th class="text-end">{{ t('manufacturing.orders.short') }}</th></tr></thead>
            <tbody><tr v-for="s in checks.shortages" :key="s.item"><td>{{ s.item }}</td><td class="text-end">{{ num(s.needed) }}</td><td class="text-end">{{ num(s.available) }}</td><td class="text-end text-error">{{ num(s.short) }}</td></tr></tbody>
          </VTable>
        </template>
        <template v-if="checks.capacity_conflicts.length">
          <div class="text-subtitle-2 mt-2">{{ t('manufacturing.orders.capacity') }}</div>
          <VTable density="compact">
            <thead><tr><th>{{ t('manufacturing.routing.work_centre') }}</th><th class="text-end">{{ t('manufacturing.orders.required_minutes') }}</th><th class="text-end">{{ t('manufacturing.orders.available_minutes') }}</th></tr></thead>
            <tbody><tr v-for="c in checks.capacity_conflicts" :key="c.work_centre"><td>{{ c.work_centre }}</td><td class="text-end">{{ num(c.required_minutes, 0) }}</td><td class="text-end">{{ num(c.available_minutes, 0) }}</td></tr></tbody>
          </VTable>
        </template>
      </VCardText>
    </VCard>

    <VCard border>
      <VTabs v-model="tab">
        <VTab value="materials">{{ t('manufacturing.orders.tab.materials') }}</VTab>
        <VTab value="operations">{{ t('manufacturing.orders.tab.operations') }}</VTab>
        <VTab value="output">{{ t('manufacturing.orders.tab.output') }}</VTab>
        <VTab value="quality">{{ t('manufacturing.orders.tab.quality') }}<VBadge v-if="inspections.some(i => i.status !== 'CLOSED')" dot inline color="warning" /></VTab>
        <VTab value="costs">{{ t('manufacturing.orders.tab.costs') }}</VTab>
        <VTab v-if="cost_analysis" value="variances">{{ t('manufacturing.orders.tab.variances') }}</VTab>
        <VTab v-if="reconciliation" value="reconciliation">{{ t('manufacturing.orders.tab.reconciliation') }}</VTab>
        <VTab v-if="customer || subcontracts.length" value="fulfilment">{{ t('manufacturing.orders.tab.fulfilment') }}</VTab>
        <VTab value="history">{{ t('manufacturing.orders.tab.history') }}</VTab>
      </VTabs>
      <VDivider />

      <VWindow v-model="tab">
        <!-- Materials -->
        <VWindowItem value="materials">
          <VTable>
            <thead>
              <tr>
                <th>{{ t('manufacturing.orders.material') }}</th>
                <th class="text-end">{{ t('manufacturing.orders.required') }}</th>
                <th class="text-end">{{ t('manufacturing.orders.issued') }}</th>
                <th class="text-end">{{ t('manufacturing.orders.returned') }}</th>
                <th class="text-end">{{ t('manufacturing.orders.outstanding') }}</th>
                <th class="text-end">{{ t('manufacturing.orders.available') }}</th>
                <th class="text-end">{{ t('manufacturing.orders.unit_cost') }}</th>
                <th v-if="can.issue" />
              </tr>
            </thead>
            <tbody>
              <tr v-for="c in components" :key="c.id">
                <td>
                  <div>{{ c.code }} <VChip v-if="!c.is_consumed" size="x-small" label class="ms-1">{{ t(`manufacturing.line_kind.${c.kind}`) }}</VChip></div>
                  <div class="text-caption text-medium-emphasis">{{ c.name }}</div>
                </td>
                <td class="text-end">{{ num(c.required) }} {{ c.uom }}</td>
                <td class="text-end">{{ num(c.issued) }}</td>
                <td class="text-end">{{ num(c.returned) }}</td>
                <td class="text-end">{{ c.is_consumed ? num(c.outstanding) : '—' }}</td>
                <td class="text-end" :class="{ 'text-error': c.available !== null && c.available + 0.0001 < c.outstanding }">{{ num(c.available) }}</td>
                <td class="text-end">{{ money(c.unit_cost) }}</td>
                <td v-if="can.issue" class="text-end text-no-wrap">
                  <template v-if="c.is_consumed">
                    <VBtn size="small" variant="tonal" color="primary" @click="openMaterial('issue', c)">{{ t('manufacturing.orders.issue') }}</VBtn>
                    <VBtn v-if="c.net_issued > 0" size="small" variant="text" class="ms-1" @click="openMaterial('return', c)">{{ t('manufacturing.orders.return') }}</VBtn>
                  </template>
                </td>
              </tr>
              <tr v-if="!components.length"><td colspan="8" class="text-center text-medium-emphasis py-6">{{ t('manufacturing.orders.no_components') }}</td></tr>
            </tbody>
          </VTable>
        </VWindowItem>

        <!-- Operations -->
        <VWindowItem value="operations">
          <VList lines="two">
            <VListItem v-for="op in operations" :key="op.id">
              <template #prepend><VAvatar size="32" color="primary" variant="tonal" class="me-2">{{ op.sequence }}</VAvatar></template>
              <VListItemTitle class="d-flex align-center gap-2 flex-wrap">
                {{ op.name }}
                <VChip size="x-small" label :color="opColor(op.status)">{{ t(`manufacturing.op_status.${op.status}`) }}</VChip>
                <VChip v-if="op.is_inspection_point" size="x-small" label variant="outlined" color="purple">{{ t('manufacturing.routing.inspection') }}</VChip>
                <VChip v-if="op.is_approval_gate" size="x-small" label variant="outlined" color="deep-purple">{{ t('manufacturing.routing.gate') }}</VChip>
                <VChip v-if="op.gate_status" size="x-small" label :color="op.gate_status === 'PASSED' ? 'success' : 'error'">{{ t(`manufacturing.orders.gate_${op.gate_status}`) }}</VChip>
                <VChip v-if="op.is_subcontracted" size="x-small" label variant="outlined">{{ t('manufacturing.routing.subcontracted') }}</VChip>
                <VChip v-if="op.parallel_group" size="x-small" label variant="outlined">{{ t('manufacturing.routing.parallel_n', { n: op.parallel_group }) }}</VChip>
              </VListItemTitle>
              <VListItemSubtitle>
                {{ op.work_centre ?? '—' }} · {{ t('manufacturing.orders.planned_min', { n: num(op.planned_minutes, 0) }) }}
                <template v-if="op.status === 'COMPLETED'"> · {{ t('manufacturing.orders.op_result', { good: num(op.qty_completed), rejected: num(op.qty_rejected), min: num(op.actual_machine_minutes, 0) }) }}</template>
                <div v-if="op.blocked_by.length" class="text-warning">{{ t('manufacturing.orders.waiting_for', { ops: op.blocked_by.join(', ') }) }}</div>
              </VListItemSubtitle>
              <template v-if="can.execute" #append>
                <div class="d-flex gap-1">
                  <VBtn v-if="['READY', 'PENDING', 'PAUSED'].includes(op.status)" size="small" variant="tonal" color="primary" :disabled="busy" @click="opStep('start', op)">{{ t('manufacturing.orders.op_start') }}</VBtn>
                  <VBtn v-if="op.status === 'IN_PROGRESS'" size="small" variant="text" :disabled="busy" @click="opStep('pause', op)">{{ t('manufacturing.orders.op_pause') }}</VBtn>
                  <VBtn v-if="op.status !== 'COMPLETED' && (op.blocked_by.length === 0 || can.override)" size="small" variant="tonal" color="success" @click="openComplete(op)">{{ t('manufacturing.orders.op_complete') }}</VBtn>
                  <VBtn v-if="op.status === 'COMPLETED' && op.is_gate && op.gate_status !== 'PASSED'" size="small" variant="tonal" color="purple" @click="gating = op">{{ t('manufacturing.orders.op_gate') }}</VBtn>
                </div>
              </template>
            </VListItem>
            <VListItem v-if="!operations.length" :title="t('manufacturing.orders.no_operations')" />
          </VList>
        </VWindowItem>

        <!-- Output and losses -->
        <VWindowItem value="output">
          <VCardText>
            <VRow>
              <VCol cols="6" sm="3"><div class="text-caption text-medium-emphasis">{{ t('manufacturing.orders.made') }}</div><div class="text-h6">{{ num(order.qty_completed) }}</div></VCol>
              <VCol cols="6" sm="3"><div class="text-caption text-medium-emphasis">{{ t('manufacturing.orders.scrapped') }}</div><div class="text-h6">{{ num(order.qty_scrapped) }}</div></VCol>
              <VCol cols="6" sm="3"><div class="text-caption text-medium-emphasis">{{ t('manufacturing.orders.reworked') }}</div><div class="text-h6">{{ num(order.qty_reworked) }}</div></VCol>
              <VCol cols="6" sm="3"><div class="text-caption text-medium-emphasis">{{ t('manufacturing.orders.remaining') }}</div><div class="text-h6">{{ num(Math.max(0, order.planned_qty - order.qty_completed)) }}</div></VCol>
            </VRow>
            <div v-if="can.execute" class="d-flex gap-2 mt-4">
              <VBtn color="success" prepend-icon="tabler-package-import" @click="openOutput">{{ t('manufacturing.orders.receive_output') }}</VBtn>
              <VBtn variant="tonal" color="error" prepend-icon="tabler-trash" @click="lossDialog = true">{{ t('manufacturing.orders.record_loss') }}</VBtn>
            </div>
            <p v-else class="text-body-2 text-medium-emphasis mt-4 mb-0">{{ t('manufacturing.orders.output_hint') }}</p>
          </VCardText>
        </VWindowItem>

        <!-- Quality -->
        <VWindowItem value="quality">
          <VTable>
            <thead><tr><th>{{ t('manufacturing.quality.number') }}</th><th>{{ t('manufacturing.quality.stage_label') }}</th><th>{{ t('manufacturing.quality.lot') }}</th><th class="text-end">{{ t('manufacturing.orders.quantity') }}</th><th>{{ t('manufacturing.quality.result') }}</th><th>{{ t('manufacturing.bom.status') }}</th></tr></thead>
            <tbody>
              <tr v-for="i in inspections" :key="i.id">
                <td><a :href="route('manufacturing.quality.inspections.show', i.id)">{{ i.number }}</a></td>
                <td>{{ t(`manufacturing.quality.stage.${i.stage}`) }}<div v-if="i.operation" class="text-caption text-medium-emphasis">{{ i.operation }}</div></td>
                <td>{{ i.lot ?? '—' }}</td>
                <td class="text-end">{{ num(i.qty) }}</td>
                <td><VChip size="x-small" label :color="i.result === 'FAILED' ? 'error' : i.result === 'PASSED' ? 'success' : 'grey'">{{ t(`manufacturing.quality.result_${i.result}`) }}</VChip></td>
                <td>{{ i.disposition ? t(`manufacturing.quality.disposition.${i.disposition}`) : t(`manufacturing.quality.status.${i.status}`) }}</td>
              </tr>
              <tr v-if="!inspections.length"><td colspan="6" class="text-center text-medium-emphasis py-6">{{ t('manufacturing.orders.no_inspections') }}</td></tr>
            </tbody>
          </VTable>
        </VWindowItem>

        <!-- Costs -->
        <VWindowItem value="costs">
          <VTable>
            <thead><tr><th /><th class="text-end">{{ t('manufacturing.orders.estimate') }}</th><th class="text-end">{{ t('manufacturing.orders.actual') }}</th></tr></thead>
            <tbody>
              <tr v-for="r in costRows" :key="r.key" :class="{ 'font-weight-bold': r.key === 'total' }">
                <td>{{ t(`manufacturing.orders.cost_${r.key}`) }}</td>
                <td class="text-end">{{ money(r.estimated) }}</td>
                <td class="text-end">{{ money(r.actual) }}</td>
              </tr>
              <tr><td>{{ t('manufacturing.orders.wip') }}</td><td /><td class="text-end">{{ money(order.wip) }}</td></tr>
            </tbody>
          </VTable>
        </VWindowItem>

        <!-- Standard against actual -->
        <VWindowItem v-if="cost_analysis" value="variances">
          <VCardText>
            <div class="d-flex flex-wrap gap-4 align-center mb-3">
              <VChip label :color="cost_analysis.status === 'FINAL' ? 'success' : cost_analysis.status === 'PROVISIONAL' ? 'warning' : 'grey'">
                {{ t(`manufacturing.costing.status.${cost_analysis.status}`) }}
              </VChip>
              <span v-if="cost_analysis.standard">{{ t('manufacturing.costing.standard_vn', { n: cost_analysis.standard.version }) }} · {{ t('manufacturing.costing.for_output', { amount: money(cost_analysis.standard.for_output) }) }}</span>
              <span>{{ t('manufacturing.costing.actual_total', { amount: money(cost_analysis.actual.total) }) }}</span>
            </div>
            <VAlert v-if="cost_analysis.outstanding.length" type="info" variant="tonal" density="compact" class="mb-3">
              <div class="font-weight-medium">{{ t('manufacturing.costing.not_final') }}</div>
              <ul class="ms-4"><li v-for="(o, i) in cost_analysis.outstanding" :key="i">{{ o }}</li></ul>
            </VAlert>
          </VCardText>
          <VExpansionPanels variant="accordion">
            <VExpansionPanel v-for="v in cost_analysis.variances" :key="v.kind">
              <VExpansionPanelTitle>
                <div class="d-flex align-center gap-3 w-100">
                  <span class="font-weight-medium" :class="{ 'text-medium-emphasis': !v.additive && v.kind !== 'TOTAL' }">{{ t(`manufacturing.costing.kind.${v.kind}`) }}</span>
                  <VChip v-if="v.data_quality !== 'COMPLETE'" size="x-small" label :color="v.data_quality === 'INSUFFICIENT' ? 'error' : 'warning'">{{ t(`manufacturing.costing.quality.${v.data_quality}`) }}</VChip>
                  <VSpacer />
                  <span :class="(v.amount ?? 0) > 0.005 ? 'text-error' : (v.amount ?? 0) < -0.005 ? 'text-success' : ''">{{ v.amount === null ? '—' : money(v.amount) }}</span>
                </div>
              </VExpansionPanelTitle>
              <VExpansionPanelText>
                <div class="text-body-2 mb-1"><span class="text-medium-emphasis">{{ t('manufacturing.costing.method') }}:</span> {{ v.method }}</div>
                <div v-if="v.notes" class="text-body-2 mb-1">{{ v.notes }}</div>
                <VTable v-if="Array.isArray(v.inputs.lines) && v.inputs.lines.length" density="compact" class="mb-2">
                  <thead><tr><th v-for="k in Object.keys(v.inputs.lines[0])" :key="k">{{ k.replaceAll('_', ' ') }}</th></tr></thead>
                  <tbody><tr v-for="(row, i) in v.inputs.lines" :key="i"><td v-for="k in Object.keys(row)" :key="k">{{ row[k] }}</td></tr></tbody>
                </VTable>
                <div class="text-caption text-medium-emphasis">
                  <template v-for="(val, k) in v.inputs" :key="k"><span v-if="k !== 'lines' && !Array.isArray(val)">{{ String(k).replaceAll('_', ' ') }}: {{ val }} · </span></template>
                  <span v-if="v.inputs.movements">{{ t('manufacturing.costing.movements_n', { n: v.inputs.movements.length }) }}</span>
                </div>
              </VExpansionPanelText>
            </VExpansionPanel>
          </VExpansionPanels>
        </VWindowItem>

        <!-- Reconciliation -->
        <VWindowItem v-if="reconciliation" value="reconciliation">
          <VCardText>
            <VRow dense class="mb-2">
              <VCol cols="6" sm="2"><div class="text-caption text-medium-emphasis">{{ t('manufacturing.orders.planned') }}</div><div class="text-h6">{{ num(reconciliation.planned) }}</div></VCol>
              <VCol cols="6" sm="2"><div class="text-caption text-medium-emphasis">{{ t('manufacturing.orders.made') }}</div><div class="text-h6">{{ num(reconciliation.good) }}</div></VCol>
              <VCol cols="6" sm="2"><div class="text-caption text-medium-emphasis">{{ t('manufacturing.orders.scrapped') }}</div><div class="text-h6">{{ num(reconciliation.scrapped) }}</div></VCol>
              <VCol cols="6" sm="2"><div class="text-caption text-medium-emphasis">{{ t('manufacturing.orders.reworked') }}</div><div class="text-h6">{{ num(reconciliation.reworked) }}</div></VCol>
              <VCol cols="6" sm="2"><div class="text-caption text-medium-emphasis">{{ t('manufacturing.orders.yield') }}</div><div class="text-h6">{{ reconciliation.yield_percent ?? '—' }}%</div></VCol>
              <VCol cols="6" sm="2"><div class="text-caption text-medium-emphasis">{{ t('manufacturing.orders.ops_open') }}</div><div class="text-h6">{{ reconciliation.operations_open }}</div></VCol>
            </VRow>
          </VCardText>
          <VTable density="compact">
            <thead><tr><th>{{ t('manufacturing.orders.material') }}</th><th class="text-end">{{ t('manufacturing.orders.required') }}</th><th class="text-end">{{ t('manufacturing.orders.expected_for_output') }}</th><th class="text-end">{{ t('manufacturing.orders.consumed') }}</th><th class="text-end">{{ t('manufacturing.orders.variance') }}</th></tr></thead>
            <tbody>
              <tr v-for="m in reconciliation.materials" :key="m.item ?? ''">
                <td>{{ m.item }}</td><td class="text-end">{{ num(m.required) }}</td><td class="text-end">{{ num(m.expected_for_output) }}</td>
                <td class="text-end">{{ num(m.consumed) }}</td>
                <td class="text-end" :class="{ 'text-error': m.variance > 0.0001, 'text-success': m.variance < -0.0001 }">{{ num(m.variance) }}</td>
              </tr>
            </tbody>
          </VTable>
        </VWindowItem>

        <!-- History -->
        <!-- Who it is for, and work out at suppliers (MFG-017) -->
        <VWindowItem v-if="customer || subcontracts.length" value="fulfilment">
          <VCardText v-if="customer">
            <div class="d-flex flex-wrap align-center gap-3 mb-2">
              <span class="text-h6">{{ customer.sales_order }}</span>
              <span>{{ customer.customer }}</span>
              <span v-if="customer.customer_ref" class="text-medium-emphasis">{{ customer.customer_ref }}</span>
              <VChip size="small" label>{{ t(`manufacturing.fulfilment.strategy.${customer.strategy}`) }}</VChip>
              <VChip size="small" label :color="fulfilmentFlagColor(customer.flag)">{{ t(`manufacturing.fulfilment.flag.${customer.flag}`) }}</VChip>
            </div>
            <div class="text-body-2 mb-2">
              {{ t('manufacturing.fulfilment.line_summary', { ordered: num(customer.ordered), delivered: num(customer.delivered), ready: num(customer.ready) }) }}
              · {{ t('manufacturing.fulfilment.required') }} {{ customer.required_date ?? '—' }}
              · {{ t('manufacturing.fulfilment.promised') }} {{ customer.promised_date ?? '—' }}
            </div>
            <div v-if="customer.configuration" class="text-body-2 mb-2"><span class="text-medium-emphasis">{{ t('manufacturing.fulfilment.configuration') }}:</span> {{ customer.configuration }}</div>
            <VAlert v-for="(r, i) in customer.risks" :key="i" type="warning" variant="tonal" density="compact" class="mb-1">{{ r }}</VAlert>
            <div class="text-body-2 mt-2">
              <span class="text-medium-emphasis">{{ t('manufacturing.fulfilment.deliveries') }}:</span>
              <span v-if="!customer.references.deliveries.length"> —</span>
              <span v-for="d in customer.references.deliveries" :key="d.number"> {{ d.number }} ({{ d.date }}, {{ num(d.qty) }})</span>
              · <span class="text-medium-emphasis">{{ t('manufacturing.fulfilment.invoice') }}:</span>
              {{ customer.references.invoice ? `${customer.references.invoice.number} (${customer.references.invoice.status})` : '—' }}
            </div>
          </VCardText>
          <VDivider v-if="customer && subcontracts.length" />
          <VTable v-if="subcontracts.length">
            <thead><tr>
              <th>{{ t('manufacturing.fulfilment.subcontract') }}</th><th>{{ t('manufacturing.fulfilment.supplier') }}</th>
              <th>{{ t('manufacturing.fulfilment.operation') }}</th><th class="text-end">{{ t('manufacturing.fulfilment.qty') }}</th>
              <th class="text-end">{{ t('manufacturing.fulfilment.received') }}</th><th>{{ t('manufacturing.fulfilment.due') }}</th><th>{{ t('manufacturing.fulfilment.status') }}</th>
            </tr></thead>
            <tbody>
              <tr v-for="s in subcontracts" :key="s.id" class="cursor-pointer" @click="router.visit(route('manufacturing.fulfilment.subcontracts.show', s.id))">
                <td>{{ s.number }}</td><td>{{ s.supplier }}</td><td>{{ s.operation ?? t('manufacturing.fulfilment.whole_order') }}</td>
                <td class="text-end">{{ num(s.qty) }}</td><td class="text-end">{{ num(s.received) }}</td><td>{{ s.due_date ?? '—' }}</td>
                <td><VChip size="small" label>{{ t(`manufacturing.fulfilment.sco_status.${s.status}`) }}</VChip></td>
              </tr>
            </tbody>
          </VTable>
        </VWindowItem>

        <VWindowItem value="history">
          <VCardText>
            <RecordDocuments v-bind="documents_panel" :can-upload="documents_panel.can_upload" :title="t('manufacturing.floor.attachments')" class="mb-4" />
            <VTimeline density="compact" side="end" truncate-line="both">
              <VTimelineItem v-for="e in events" :key="e.id" size="x-small" :dot-color="e.is_exception ? 'error' : 'primary'">
                <div class="d-flex justify-space-between flex-wrap gap-2">
                  <div>
                    <span class="font-weight-medium">{{ t(`manufacturing.order_event.${e.action}`) }}</span>
                    <VChip v-if="e.is_exception" size="x-small" color="error" label class="ms-1">{{ t('manufacturing.orders.exception') }}</VChip>
                    <span v-if="e.actor" class="text-medium-emphasis"> · {{ e.actor }}</span>
                  </div>
                  <span class="text-caption text-medium-emphasis">{{ when(e.acted_at) }}</span>
                </div>
                <div v-if="e.reason" class="text-body-2">{{ e.reason }}</div>
              </VTimelineItem>
            </VTimeline>
          </VCardText>
        </VWindowItem>
      </VWindow>
    </VCard>

    <!-- Reason (reject / hold / cancel / return for rework) -->
    <VDialog :model-value="reasonStep !== null" max-width="520" @update:model-value="v => { if (!v) reasonStep = null }">
      <VCard v-if="reasonStep" :title="t(`manufacturing.orders.reason_title.${reasonStep}`)">
        <VCardText>
          <VTextarea v-model="reason" rows="3" auto-grow autofocus :label="t('manufacturing.orders.reason')" />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="reasonStep = null">{{ t('manufacturing.common.cancel') }}</VBtn>
          <VBtn color="primary" :loading="busy" :disabled="!reason.trim()" @click="submitReason">{{ t('manufacturing.common.confirm') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Approve over a shortage or a capacity conflict -->
    <VDialog v-model="approveDialog" max-width="560">
      <VCard :title="t('manufacturing.orders.approve_override')">
        <VCardText>
          <p class="text-body-2">{{ t('manufacturing.orders.approve_override_hint') }}</p>
          <VTextarea v-model="overrideReason" rows="3" auto-grow :label="t('manufacturing.orders.override_reason')" />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="approveDialog = false">{{ t('manufacturing.common.cancel') }}</VBtn>
          <VBtn color="success" :loading="busy" @click="submitApprove">{{ t('manufacturing.common.approve') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Draft edit -->
    <VDialog v-model="editDialog" max-width="560">
      <VCard :title="t('manufacturing.orders.edit_draft')">
        <VCardText>
          <VRow dense>
            <VCol cols="6"><VTextField v-model.number="edit.quantity" type="number" min="0" step="any" :error-messages="edit.errors.quantity" :label="t('manufacturing.orders.quantity')" :hint="t('manufacturing.orders.rescale_hint')" persistent-hint /></VCol>
            <VCol cols="6"><VTextField v-model="edit.due_date" type="date" :error-messages="edit.errors.due_date" :label="t('manufacturing.orders.due')" /></VCol>
            <VCol cols="6"><VSelect v-model="edit.priority" :items="[1, 2, 3, 4, 5].map(p => ({ title: t(`manufacturing.orders.priority_${p}`), value: p }))" :label="t('manufacturing.orders.priority')" /></VCol>
            <VCol cols="6"><VAutocomplete v-model="edit.supervisor_id" :items="people" item-value="id" item-title="name" clearable :label="t('manufacturing.orders.supervisor')" /></VCol>
            <VCol cols="12"><VTextField v-model="edit.demand_reference" :label="t('manufacturing.orders.demand_reference')" /></VCol>
            <VCol cols="12"><VTextarea v-model="edit.notes" rows="2" auto-grow :label="t('manufacturing.orders.notes')" /></VCol>
          </VRow>
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="editDialog = false">{{ t('manufacturing.common.cancel') }}</VBtn>
          <VBtn color="primary" :loading="edit.processing" @click="saveEdit">{{ t('manufacturing.common.save') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Issue / return material -->
    <VDialog :model-value="material !== null" max-width="520" @update:model-value="v => { if (!v) material = null }">
      <VCard v-if="material" :title="t(material.mode === 'issue' ? 'manufacturing.orders.issue_title' : 'manufacturing.orders.return_title', { item: material.component.code })">
        <VCardText>
          <VTextField v-model.number="materialForm.qty" type="number" min="0" step="any" autofocus :suffix="material.component.uom ?? ''"
                      :label="t('manufacturing.orders.quantity')" class="mb-3"
                      :hint="material.mode === 'issue' ? t('manufacturing.orders.outstanding_n', { n: num(material.component.outstanding) }) : t('manufacturing.orders.with_order_n', { n: num(material.component.net_issued) })" persistent-hint />
          <VSelect v-if="material.component.lots.length" v-model="materialForm.lot_id" clearable class="mb-3"
                   :items="material.component.lots.map(l => ({ title: `${l.lot} · ${num(l.qty)}${l.expires_on ? ` · ${t('manufacturing.orders.expires', { d: l.expires_on })}` : ''}`, value: l.id }))"
                   :label="t('manufacturing.orders.lot')" :hint="t('manufacturing.orders.lot_hint')" persistent-hint />
          <template v-if="overIssue">
            <VAlert type="warning" variant="tonal" density="compact" class="mb-3">{{ t('manufacturing.orders.over_issue') }}</VAlert>
            <VTextarea v-if="can.override" v-model="materialForm.override_reason" rows="2" auto-grow :label="t('manufacturing.orders.override_reason')" />
          </template>
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="material = null">{{ t('manufacturing.common.cancel') }}</VBtn>
          <VBtn color="primary" :loading="busy" :disabled="!materialForm.qty" @click="submitMaterial">
            {{ t(material.mode === 'issue' ? 'manufacturing.orders.issue' : 'manufacturing.orders.return') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Complete an operation -->
    <VDialog :model-value="completing !== null" max-width="560" @update:model-value="v => { if (!v) completing = null }">
      <VCard v-if="completing" :title="t('manufacturing.orders.complete_op', { n: completing.sequence, name: completing.name })">
        <VCardText>
          <VAlert v-if="completing.blocked_by.length" type="warning" variant="tonal" density="compact" class="mb-3">
            {{ t('manufacturing.orders.waiting_for', { ops: completing.blocked_by.join(', ') }) }}
          </VAlert>
          <VRow dense>
            <VCol cols="6"><VTextField v-model.number="opForm.completed" type="number" min="0" step="any" :label="t('manufacturing.orders.good')" /></VCol>
            <VCol cols="6"><VTextField v-model.number="opForm.rejected" type="number" min="0" step="any" :label="t('manufacturing.orders.rejected')" /></VCol>
            <VCol cols="6"><VTextField v-model.number="opForm.scrapped" type="number" min="0" step="any" :label="t('manufacturing.orders.scrapped')" /></VCol>
            <VCol cols="6"><VTextField v-model.number="opForm.reworked" type="number" min="0" step="any" :label="t('manufacturing.orders.reworked')" /></VCol>
            <VCol cols="6"><VTextField v-model.number="opForm.machine_minutes" type="number" min="0" step="any" :label="t('manufacturing.orders.machine_minutes')" /></VCol>
            <VCol cols="6"><VTextField v-model.number="opForm.labour_minutes" type="number" min="0" step="any" :label="t('manufacturing.orders.labour_minutes')" /></VCol>
            <VCol v-if="completing.blocked_by.length" cols="12">
              <VTextarea v-model="opForm.override_reason" rows="2" auto-grow :label="t('manufacturing.orders.override_reason')" />
            </VCol>
          </VRow>
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="completing = null">{{ t('manufacturing.common.cancel') }}</VBtn>
          <VBtn color="success" :loading="busy" :disabled="completing.blocked_by.length > 0 && !opForm.override_reason.trim()" @click="submitComplete">
            {{ t('manufacturing.orders.op_complete') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Gate -->
    <VDialog :model-value="gating !== null" max-width="480" @update:model-value="v => { if (!v) gating = null }">
      <VCard v-if="gating" :title="t('manufacturing.orders.gate_title', { n: gating.sequence, name: gating.name })">
        <VCardText>
          <VRadioGroup v-model="gateForm.result" inline>
            <VRadio :label="t('manufacturing.orders.gate_PASSED')" value="PASSED" color="success" />
            <VRadio :label="t('manufacturing.orders.gate_FAILED')" value="FAILED" color="error" />
          </VRadioGroup>
          <VTextarea v-model="gateForm.notes" rows="2" auto-grow :label="t('manufacturing.orders.notes')" />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="gating = null">{{ t('manufacturing.common.cancel') }}</VBtn>
          <VBtn color="primary" :loading="busy" @click="submitGate">{{ t('manufacturing.common.confirm') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Receive output -->
    <VDialog v-model="outputDialog" max-width="480">
      <VCard :title="t('manufacturing.orders.receive_output')" :subtitle="t('manufacturing.orders.receive_output_hint')">
        <VCardText>
          <VTextField v-model.number="outputForm.qty" type="number" min="0" step="any" autofocus :label="t('manufacturing.orders.quantity')" class="mb-3" />
          <VTextField v-model="outputForm.lot_number" :label="t('manufacturing.orders.lot_number')" :hint="t('manufacturing.orders.lot_number_hint', { n: order.number })" persistent-hint />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="outputDialog = false">{{ t('manufacturing.common.cancel') }}</VBtn>
          <VBtn color="success" :loading="busy" :disabled="!outputForm.qty" @click="submitOutput">{{ t('manufacturing.orders.receive') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Record loss -->
    <VDialog v-model="lossDialog" max-width="480">
      <VCard :title="t('manufacturing.orders.record_loss')">
        <VCardText>
          <VRow dense>
            <VCol cols="6"><VTextField v-model.number="lossForm.scrapped" type="number" min="0" step="any" :label="t('manufacturing.orders.scrapped')" /></VCol>
            <VCol cols="6"><VTextField v-model.number="lossForm.reworked" type="number" min="0" step="any" :label="t('manufacturing.orders.reworked')" /></VCol>
            <VCol cols="12"><VTextarea v-model="lossForm.reason" rows="2" auto-grow :label="t('manufacturing.orders.reason')" /></VCol>
          </VRow>
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="lossDialog = false">{{ t('manufacturing.common.cancel') }}</VBtn>
          <VBtn color="error" :loading="busy" :disabled="!(lossForm.scrapped > 0 || lossForm.reworked > 0)" @click="submitLoss">{{ t('manufacturing.orders.record_loss') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
