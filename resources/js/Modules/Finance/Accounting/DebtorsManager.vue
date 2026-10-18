<script lang="ts" setup>
import { computed, ref, watch } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
const route = ziggyRoute
const { t } = useI18n()

interface Row {
  id: number; number: string; party: string; party_id: number; has_email: boolean; has_phone: boolean; do_not_contact: boolean
  date: string; due: string; currency: string; total: number; outstanding: number; days_overdue: number; bucket: string; status: string
  disputed: boolean; dispute_reason: string | null; assigned_to: number | null; assignee: string | null; dunning_step: number
  reminders: number; priority: number; credit_limit: number; party_exposure: number; over_limit: boolean
  promise: { date: string; amount: number } | null; broken_promise: { date: string } | null; last_action: any; pay_url: string | null
}
const props = defineProps<{
  filters: Record<string, any>
  data: { rows: Row[]; pagination: any; kpis: any; ageing: { bucket: string; count: number; value: number }[]; filters: any }
  metrics: any
  reasons: { code: string; label: string }[]
  collectors: { id: number; name: string }[]
  customers: { id: number; name: string }[]
  config: { channels: string[]; base_currency: string }
}>()

// ── filters (server-driven) ──
const f = ref({
  search: props.filters.search ?? '',
  party_id: props.filters.party_id ?? null,
  bucket: props.filters.bucket ?? 'all',
  status: props.filters.status ?? null,
  assigned_to: props.filters.assigned_to ?? null,
  overdue_only: props.filters.overdue_only ?? false,
  sort: props.filters.sort ?? 'priority',
  dir: props.filters.dir ?? 'desc',
  per_page: props.filters.per_page ?? 25,
})
function reload(extra: Record<string, any> = {}) {
  router.get(route('finance.accounting.debtors'), { ...f.value, ...extra }, { preserveState: true, preserveScroll: true, replace: true })
}
let searchTimer: any
watch(() => f.value.search, () => { clearTimeout(searchTimer); searchTimer = setTimeout(() => reload({ page: 1 }), 400) })
function setSort(col: string) { f.value.dir = (f.value.sort === col && f.value.dir === 'asc') ? 'desc' : 'asc'; f.value.sort = col; reload() }
function goPage(p: number) { reload({ page: p }) }

const money = (n: number, c = props.config.base_currency) => `${c} ${(n ?? 0).toLocaleString('en-ZA', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
const bucketColor: Record<string, string> = { current: 'grey', '1-30': 'info', '31-60': 'warning', '61-90': 'orange', '91-120': 'deep-orange', '120+': 'error' }
const buckets = ['all', 'current', '1-30', '31-60', '61-90', '91-120', '120+']
const kpis = computed(() => props.data.kpis)
const ageingMax = computed(() => Math.max(1, ...props.data.ageing.map(a => a.value)))
const trendVals = computed(() => (props.metrics.trend || []).map((t: any) => t.total))

// ── bulk selection ──
const selected = ref<number[]>([])
const allOnPage = computed(() => props.data.rows.map(r => r.id))
const allSelected = computed(() => allOnPage.value.length > 0 && allOnPage.value.every(id => selected.value.includes(id)))
function toggleAll() { allSelected.value ? (selected.value = []) : (selected.value = [...allOnPage.value]) }

// ── mutation dialogs ──
const confirm = ref()
function remind(r: Row, channel?: string) {
  confirm.value.open(
    { title: t('acc.send_reminder'), message: t('acc.send_reminder_confirm', { number: r.number, party: r.party }), confirmText: t('acc.send'), confirmColor: 'primary', icon: 'tabler-mail' },
    () => useForm({ channel: channel ?? null }).post(route('finance.accounting.debtors.reminder', r.id), { preserveScroll: true }),
  )
}
function runDunning() {
  confirm.value.open(
    { title: t('acc.run_dunning'), message: t('acc.run_dunning_confirm'), confirmText: t('acc.run'), confirmColor: 'primary', icon: 'tabler-mail-forward' },
    () => useForm({}).post(route('finance.accounting.debtors.run-reminders'), { preserveScroll: true }),
  )
}
const bulkChannel = ref<string | null>(null)
function bulkRemind() {
  const ids = [...selected.value]
  useForm({ ids, channel: bulkChannel.value }).post(route('finance.accounting.debtors.bulk-remind'), { preserveScroll: true, onSuccess: () => { selected.value = [] } })
}

// Log activity
const actDialog = ref(false); const actInvoice = ref<Row | null>(null)
const actForm = useForm<any>({ type: 'note', body: '', promised_date: '', promised_amount: null, reason_code: '', next_action_date: '' })
function openActivity(r: Row) { actInvoice.value = r; actForm.reset(); actForm.type = 'note'; actDialog.value = true }
function saveActivity() { actForm.post(route('finance.accounting.debtors.activity', actInvoice.value!.id), { preserveScroll: true, onSuccess: () => { actDialog.value = false } }) }

// Record payment
const payDialog = ref(false); const payInvoice = ref<Row | null>(null)
const payForm = useForm<any>({ amount: null, payment_date: new Date().toISOString().slice(0, 10), reference: '' })
function openPay(r: Row) { payInvoice.value = r; payForm.reset(); payForm.amount = r.outstanding; payForm.reference = r.number; payDialog.value = true }
function savePay() { payForm.post(route('finance.accounting.debtors.payment', payInvoice.value!.id), { preserveScroll: true, onSuccess: () => { payDialog.value = false } }) }

// Assign
const assignDialog = ref(false); const assignInvoice = ref<Row | null>(null)
const assignForm = useForm<any>({ user_id: null })
function openAssign(r: Row) { assignInvoice.value = r; assignForm.user_id = r.assigned_to; assignDialog.value = true }
function saveAssign() { assignForm.post(route('finance.accounting.debtors.assign', assignInvoice.value!.id), { preserveScroll: true, onSuccess: () => { assignDialog.value = false } }) }

// Dispute
const dispDialog = ref(false); const dispInvoice = ref<Row | null>(null)
const dispForm = useForm<any>({ disputed: true, reason: '' })
function openDispute(r: Row) { dispInvoice.value = r; dispForm.disputed = !r.disputed; dispForm.reason = r.dispute_reason ?? ''; dispDialog.value = true }
function saveDispute() { dispForm.post(route('finance.accounting.debtors.dispute', dispInvoice.value!.id), { preserveScroll: true, onSuccess: () => { dispDialog.value = false } }) }

// Timeline
const tlDialog = ref(false); const timeline = ref<any[]>([]); const tlLoading = ref(false); const tlInvoice = ref<Row | null>(null)
async function openTimeline(r: Row) {
  tlInvoice.value = r; timeline.value = []; tlLoading.value = true; tlDialog.value = true
  try { const res = await fetch(route('finance.accounting.debtors.timeline', r.id), { headers: { Accept: 'application/json' } }); timeline.value = (await res.json()).timeline } finally { tlLoading.value = false }
}
const typeIcon: Record<string, string> = { note: 'tabler-note', promise: 'tabler-hand-stop', reminder: 'tabler-mail', reason: 'tabler-alert-circle', dispute: 'tabler-alert-octagon', dispute_resolved: 'tabler-circle-check', assignment: 'tabler-user', broken_promise: 'tabler-hand-off' }

// Customer drawer
const custDrawer = ref(false); const cust = ref<any>(null); const custLoading = ref(false)
async function openCustomer(r: Row) {
  custDrawer.value = true; cust.value = null; custLoading.value = true
  try { const res = await fetch(route('finance.accounting.debtors.customer', r.party_id), { headers: { Accept: 'application/json' } }); cust.value = await res.json() } finally { custLoading.value = false }
}
function emailStatement(partyId: number) { useForm({}).post(route('finance.accounting.debtors.statement.email', partyId), { preserveScroll: true }) }
function copyPayLink(r: Row) { if (r.pay_url) navigator.clipboard?.writeText(r.pay_url) }

function exportUrl() { return route('finance.accounting.debtors.export') + '?' + new URLSearchParams(f.value as any).toString() }
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-3">
      <div>
        <div class="text-overline text-medium-emphasis">{{ t('acc.finance') }} · {{ t('acc.receivables') }}</div>
        <h2 class="text-h5 mb-0">{{ t('acc.debtors_manager') }}</h2>
      </div>
      <div class="d-flex gap-2">
        <VBtn variant="tonal" prepend-icon="tabler-download" :href="exportUrl()">{{ t('acc.export_csv') }}</VBtn>
        <VBtn color="primary" prepend-icon="tabler-mail-forward" @click="runDunning">{{ t('acc.run_dunning') }}</VBtn>
      </div>
    </div>

    <!-- KPI strip -->
    <VRow class="mb-1">
      <VCol cols="6" md="3"><VCard border><VCardText class="py-3"><div class="text-caption text-medium-emphasis">{{ t('acc.total_outstanding') }}</div><div class="text-h6">{{ money(kpis.total_outstanding) }}</div><div v-if="kpis.multi_currency" class="text-caption text-warning">{{ t('acc.multi_currency') }}</div></VCardText></VCard></VCol>
      <VCol cols="6" md="3"><VCard border><VCardText class="py-3"><div class="text-caption text-medium-emphasis">{{ t('acc.overdue') }}</div><div class="text-h6 text-error">{{ money(kpis.overdue_outstanding) }}</div><div class="text-caption text-medium-emphasis">{{ kpis.count }} {{ t('acc.open_invoices') }}</div></VCardText></VCard></VCol>
      <VCol cols="6" md="3"><VCard border><VCardText class="py-3"><div class="text-caption text-medium-emphasis">{{ t('acc.dso') }}</div><div class="text-h6">{{ metrics.dso ?? '—' }}<span v-if="metrics.dso" class="text-caption text-medium-emphasis"> {{ t('acc.days') }}</span></div><div v-if="metrics.best_possible_dso" class="text-caption text-medium-emphasis">{{ t('acc.best') }} {{ metrics.best_possible_dso }} · {{ t('acc.cei') }} {{ metrics.cei ?? '—' }}</div></VCardText></VCard></VCol>
      <VCol cols="6" md="3"><VCard border><VCardText class="py-3">
        <div class="d-flex justify-space-between"><div><div class="text-caption text-medium-emphasis">{{ t('acc.disputed') }}</div><div class="text-h6">{{ money(kpis.disputed_amount) }}</div><div class="text-caption text-medium-emphasis">{{ kpis.disputed_count }} · {{ t('acc.avg_days_to_pay') }} {{ metrics.avg_days_to_pay ?? '—' }}</div></div>
          <VSparkline v-if="trendVals.length > 1" :model-value="trendVals" :gradient="['#42a5f5','#1565c0']" line-width="3" smooth height="40" width="90" auto-draw />
        </div>
      </VCardText></VCard></VCol>
    </VRow>

    <!-- Ageing bar -->
    <VCard border class="mb-3"><VCardText class="py-3">
      <div class="d-flex align-center justify-space-between mb-2"><div class="text-caption text-medium-emphasis">{{ t('acc.ageing') }}</div><div v-if="metrics.promise_kept_rate" class="text-caption">{{ t('acc.promise_kept') }}: <strong>{{ metrics.promise_kept_rate.rate }}%</strong></div></div>
      <div class="d-flex gap-1">
        <div v-for="a in data.ageing" :key="a.bucket" class="cursor-pointer text-center" style="flex:1" @click="f.bucket = a.bucket; reload({ page: 1 })">
          <div :class="`bg-${bucketColor[a.bucket]}`" class="rounded" :style="{ height: '8px', opacity: f.bucket === a.bucket ? 1 : .55 }" />
          <div class="text-caption mt-1">{{ a.bucket }}</div>
          <div class="text-caption font-weight-medium">{{ money(a.value).replace(config.base_currency + ' ', '') }}</div>
          <div class="text-caption text-medium-emphasis">{{ a.count }}</div>
        </div>
      </div>
    </VCardText></VCard>

    <VCard border>
      <!-- toolbar -->
      <VCardText class="d-flex align-center gap-2 flex-wrap">
        <VTextField v-model="f.search" :placeholder="t('acc.search_ph')" prepend-inner-icon="tabler-search" density="compact" hide-details style="max-width: 240px" clearable @click:clear="reload({ page: 1 })" />
        <VSelect v-model="f.party_id" :items="[{ title: t('acc.all_customers'), value: null }, ...customers.map(c => ({ title: c.name, value: c.id }))]" :label="t('acc.customer')" density="compact" hide-details style="max-width: 200px" @update:model-value="reload({ page: 1 })" />
        <VSelect v-model="f.bucket" :items="buckets.map(b => ({ title: b === 'all' ? t('acc.all_ages') : b, value: b }))" :label="t('acc.age_bucket')" density="compact" hide-details style="max-width: 150px" @update:model-value="reload({ page: 1 })" />
        <VSelect v-model="f.status" :items="[{ title: t('acc.all_statuses'), value: null }, { title: t('acc.status_overdue'), value: 'overdue' }, { title: t('acc.status_disputed'), value: 'disputed' }, { title: t('acc.status_promised'), value: 'promised' }, { title: t('acc.status_broken'), value: 'broken' }]" :label="t('acc.status')" density="compact" hide-details style="max-width: 160px" @update:model-value="v => { f.overdue_only = v === 'overdue'; reload({ page: 1 }) }" />
        <VSelect v-model="f.assigned_to" :items="[{ title: t('acc.assigned_all'), value: null }, { title: t('acc.assigned_me'), value: 'me' }, { title: t('acc.unassigned'), value: 'unassigned' }, ...collectors.map(c => ({ title: c.name, value: String(c.id) }))]" :label="t('acc.collector')" density="compact" hide-details style="max-width: 180px" @update:model-value="reload({ page: 1 })" />
      </VCardText>

      <!-- bulk bar -->
      <VCardText v-if="selected.length" class="d-flex align-center gap-3 bg-primary" style="--v-theme-overlay-multiplier: 0">
        <span class="text-white">{{ selected.length }} {{ t('acc.selected') }}</span>
        <VSelect v-model="bulkChannel" :items="[{ title: t('acc.auto_channel'), value: null }, ...config.channels.map(c => ({ title: t('acc.ch_' + c), value: c }))]" density="compact" hide-details style="max-width: 160px" theme="dark" />
        <VBtn size="small" color="white" variant="flat" @click="bulkRemind">{{ t('acc.remind_selected') }}</VBtn>
        <VBtn size="small" variant="text" theme="dark" @click="selected = []">{{ t('acc.clear') }}</VBtn>
      </VCardText>

      <VTable>
        <thead>
          <tr>
            <th style="width:36px"><VCheckboxBtn :model-value="allSelected" @update:model-value="toggleAll" /></th>
            <th>{{ t('acc.customer') }}</th>
            <th>{{ t('acc.invoice') }}</th>
            <th class="cursor-pointer" @click="setSort('due')">{{ t('acc.due') }}</th>
            <th class="cursor-pointer" @click="setSort('days_overdue')">{{ t('acc.age') }}</th>
            <th class="text-right cursor-pointer" @click="setSort('outstanding')">{{ t('acc.outstanding') }}</th>
            <th>{{ t('acc.status') }}</th>
            <th>{{ t('acc.collector') }}</th>
            <th class="text-right"></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="r in data.rows" :key="r.id">
            <td><VCheckboxBtn :model-value="selected.includes(r.id)" @update:model-value="v => v ? selected.push(r.id) : (selected = selected.filter(x => x !== r.id))" /></td>
            <td>
              <div class="d-flex align-center gap-1">
                <a class="font-weight-medium text-primary cursor-pointer" @click="openCustomer(r)">{{ r.party }}</a>
                <VIcon v-if="r.over_limit" icon="tabler-alert-triangle" color="error" size="15" :title="t('acc.over_limit')" />
                <VIcon v-if="r.do_not_contact" icon="tabler-phone-off" color="warning" size="15" :title="t('acc.do_not_contact')" />
              </div>
            </td>
            <td>{{ r.number }}</td>
            <td class="text-caption">{{ r.due }}</td>
            <td><VChip size="x-small" :color="bucketColor[r.bucket]" variant="tonal">{{ r.days_overdue > 0 ? r.days_overdue + 'd' : r.bucket }}</VChip></td>
            <td class="text-right font-weight-medium">{{ money(r.outstanding, r.currency) }}</td>
            <td class="text-caption">
              <VChip v-if="r.disputed" size="x-small" color="error" variant="tonal" class="me-1"><VIcon start size="12" icon="tabler-alert-octagon" />{{ t('acc.status_disputed') }}</VChip>
              <VChip v-else-if="r.broken_promise" size="x-small" color="deep-orange" variant="tonal" class="me-1"><VIcon start size="12" icon="tabler-hand-off" />{{ t('acc.broken_ptp') }}</VChip>
              <VChip v-else-if="r.promise" size="x-small" color="success" variant="tonal" class="me-1"><VIcon start size="12" icon="tabler-hand-stop" />{{ t('acc.ptp') }} {{ r.promise.date }}</VChip>
              <VChip v-if="r.reminders" size="x-small" variant="tonal">{{ r.reminders }} {{ t('acc.reminded') }}</VChip>
              <span v-if="!r.disputed && !r.broken_promise && !r.promise && !r.reminders" class="text-medium-emphasis">—</span>
            </td>
            <td class="text-caption">{{ r.assignee ?? '—' }}</td>
            <td class="text-right text-no-wrap">
              <VBtn icon="tabler-mail" size="small" variant="text" :title="t('acc.send_reminder')" :disabled="!r.has_email && !r.has_phone" @click="remind(r)" />
              <VBtn icon="tabler-cash" size="small" variant="text" :title="t('acc.record_payment')" @click="openPay(r)" />
              <VMenu>
                <template #activator="{ props: p }"><VBtn icon="tabler-dots-vertical" size="small" variant="text" v-bind="p" /></template>
                <VList density="compact">
                  <VListItem prepend-icon="tabler-history" :title="t('acc.history')" @click="openTimeline(r)" />
                  <VListItem prepend-icon="tabler-note" :title="t('acc.log_activity')" @click="openActivity(r)" />
                  <VListItem prepend-icon="tabler-user" :title="t('acc.assign')" @click="openAssign(r)" />
                  <VListItem :prepend-icon="r.disputed ? 'tabler-circle-check' : 'tabler-alert-octagon'" :title="r.disputed ? t('acc.resolve_dispute') : t('acc.mark_disputed')" @click="openDispute(r)" />
                  <VListItem prepend-icon="tabler-file-text" :title="t('acc.view_statement')" :href="route('finance.accounting.debtors.statement', r.party_id)" target="_blank" />
                  <VListItem prepend-icon="tabler-send" :title="t('acc.email_statement')" @click="emailStatement(r.party_id)" />
                  <VListItem v-if="r.pay_url" prepend-icon="tabler-link" :title="t('acc.copy_pay_link')" @click="copyPayLink(r)" />
                </VList>
              </VMenu>
            </td>
          </tr>
          <tr v-if="!data.rows.length"><td colspan="9" class="text-center text-medium-emphasis py-6">{{ t('acc.nothing_outstanding_emoji') }}</td></tr>
        </tbody>
      </VTable>

      <!-- pagination -->
      <VCardText v-if="data.pagination.last_page > 1" class="d-flex align-center justify-space-between flex-wrap gap-2">
        <span class="text-caption text-medium-emphasis">{{ data.pagination.from }}–{{ data.pagination.to }} / {{ data.pagination.total }}</span>
        <VPagination :model-value="data.pagination.current_page" :length="data.pagination.last_page" :total-visible="6" density="comfortable" @update:model-value="goPage" />
      </VCardText>
    </VCard>

    <!-- Log activity dialog -->
    <VDialog v-model="actDialog" max-width="520">
      <VCard>
        <VCardTitle>{{ t('acc.log_activity') }} — {{ actInvoice?.number }} · {{ actInvoice?.party }}</VCardTitle>
        <VCardText>
          <VSelect v-model="actForm.type" :items="[{ title: t('acc.note_type'), value: 'note' }, { title: t('acc.promise_type'), value: 'promise' }, { title: t('acc.reason_type'), value: 'reason' }]" :label="t('acc.type')" variant="outlined" density="comfortable" class="mb-3" />
          <div v-if="actForm.type === 'promise'" class="d-flex gap-3 mb-3">
            <VTextField v-model="actForm.promised_date" type="date" :label="t('acc.promised_date')" variant="outlined" density="comfortable" />
            <VTextField v-model.number="actForm.promised_amount" type="number" :label="t('acc.amount')" variant="outlined" density="comfortable" />
          </div>
          <VSelect v-if="actForm.type === 'reason'" v-model="actForm.reason_code" :items="reasons.map(r => ({ title: r.label, value: r.code }))" :label="t('acc.reason')" variant="outlined" density="comfortable" class="mb-3" />
          <VTextarea v-model="actForm.body" :label="t('acc.note_label')" variant="outlined" density="comfortable" rows="2" auto-grow class="mb-3" />
          <VTextField v-model="actForm.next_action_date" type="date" :label="t('acc.next_action_date_optional')" variant="outlined" density="comfortable" style="max-width: 240px" />
        </VCardText>
        <VCardActions class="pa-4"><VSpacer /><VBtn variant="text" @click="actDialog = false">{{ t('acc.cancel') }}</VBtn><VBtn color="primary" :loading="actForm.processing" @click="saveActivity">{{ t('acc.save') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <!-- Record payment -->
    <VDialog v-model="payDialog" max-width="440">
      <VCard>
        <VCardTitle>{{ t('acc.record_payment') }} — {{ payInvoice?.number }}</VCardTitle>
        <VCardText>
          <div class="text-caption text-medium-emphasis mb-2">{{ t('acc.outstanding') }}: {{ payInvoice ? money(payInvoice.outstanding, payInvoice.currency) : '' }}</div>
          <VTextField v-model.number="payForm.amount" type="number" :label="t('acc.amount')" variant="outlined" density="comfortable" class="mb-3" :error-messages="payForm.errors.amount" />
          <VTextField v-model="payForm.payment_date" type="date" :label="t('acc.payment_date')" variant="outlined" density="comfortable" class="mb-3" />
          <VTextField v-model="payForm.reference" :label="t('acc.reference')" variant="outlined" density="comfortable" />
        </VCardText>
        <VCardActions class="pa-4"><VSpacer /><VBtn variant="text" @click="payDialog = false">{{ t('acc.cancel') }}</VBtn><VBtn color="primary" :loading="payForm.processing" @click="savePay">{{ t('acc.record') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <!-- Assign -->
    <VDialog v-model="assignDialog" max-width="420">
      <VCard>
        <VCardTitle>{{ t('acc.assign') }} — {{ assignInvoice?.number }}</VCardTitle>
        <VCardText>
          <VSelect v-model="assignForm.user_id" :items="[{ title: t('acc.unassign'), value: null }, ...collectors.map(c => ({ title: c.name, value: c.id }))]" :label="t('acc.collector')" variant="outlined" density="comfortable" />
        </VCardText>
        <VCardActions class="pa-4"><VSpacer /><VBtn variant="text" @click="assignDialog = false">{{ t('acc.cancel') }}</VBtn><VBtn color="primary" :loading="assignForm.processing" @click="saveAssign">{{ t('acc.save') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <!-- Dispute -->
    <VDialog v-model="dispDialog" max-width="440">
      <VCard>
        <VCardTitle>{{ dispForm.disputed ? t('acc.mark_disputed') : t('acc.resolve_dispute') }} — {{ dispInvoice?.number }}</VCardTitle>
        <VCardText>
          <VTextarea v-if="dispForm.disputed" v-model="dispForm.reason" :label="t('acc.dispute_reason')" variant="outlined" density="comfortable" rows="2" auto-grow />
          <div v-else class="text-body-2">{{ t('acc.resolve_dispute_hint') }}</div>
        </VCardText>
        <VCardActions class="pa-4"><VSpacer /><VBtn variant="text" @click="dispDialog = false">{{ t('acc.cancel') }}</VBtn><VBtn :color="dispForm.disputed ? 'error' : 'success'" :loading="dispForm.processing" @click="saveDispute">{{ dispForm.disputed ? t('acc.mark_disputed') : t('acc.resolve') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <!-- Timeline -->
    <VDialog v-model="tlDialog" max-width="560">
      <VCard>
        <VCardTitle>{{ t('acc.history') }} — {{ tlInvoice?.number }}</VCardTitle>
        <VCardText>
          <div v-if="tlLoading" class="text-center py-6"><VProgressCircular indeterminate color="primary" /></div>
          <VTimeline v-else-if="timeline.length" side="end" density="compact" truncate-line="both">
            <VTimelineItem v-for="(a, i) in timeline" :key="i" :dot-color="a.type === 'reminder' ? 'info' : (a.type === 'promise' ? 'success' : (a.type === 'dispute' || a.type === 'broken_promise' ? 'error' : 'grey'))" size="x-small">
              <template #icon><VIcon size="14" :icon="typeIcon[a.type] || 'tabler-point'" /></template>
              <div class="d-flex justify-space-between">
                <strong class="text-capitalize">{{ t('acc.act_' + a.type) !== 'acc.act_' + a.type ? t('acc.act_' + a.type) : a.type.replace('_', ' ') }}<span v-if="a.channel" class="text-caption text-medium-emphasis"> · {{ a.channel }}</span></strong>
                <span class="text-caption text-medium-emphasis">{{ a.at }}</span>
              </div>
              <div v-if="a.promised_date" class="text-caption text-success">{{ t('acc.promised') }} {{ a.promised_date }}<span v-if="a.promised_amount"> · {{ money(a.promised_amount) }}</span></div>
              <div v-if="a.reason_code" class="text-caption">{{ t('acc.reason_prefix') }} {{ a.reason_code }}</div>
              <div v-if="a.body" class="text-body-2">{{ a.body }}</div>
              <div v-if="a.next_action_date" class="text-caption text-warning">{{ t('acc.next_action') }} {{ a.next_action_date }}</div>
              <div class="text-caption text-medium-emphasis">{{ t('acc.by_prefix') }} {{ a.by }}</div>
            </VTimelineItem>
          </VTimeline>
          <div v-else class="text-center text-medium-emphasis py-6">{{ t('acc.no_activity') }}</div>
        </VCardText>
        <VCardActions class="pa-4"><VSpacer /><VBtn variant="text" @click="tlDialog = false">{{ t('acc.close') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <!-- Customer drawer -->
    <VNavigationDrawer v-model="custDrawer" location="right" temporary width="420">
      <div class="pa-4" v-if="custLoading"><VProgressCircular indeterminate color="primary" /></div>
      <div class="pa-4" v-else-if="cust">
        <div class="d-flex justify-space-between align-center mb-1"><h3 class="text-h6">{{ cust.party.name }}</h3><VBtn icon="tabler-x" variant="text" size="small" @click="custDrawer = false" /></div>
        <div class="text-caption text-medium-emphasis mb-3">{{ cust.party.email || '—' }}<span v-if="cust.party.phone"> · {{ cust.party.phone }}</span></div>
        <VRow class="mb-2">
          <VCol cols="6"><div class="text-caption text-medium-emphasis">{{ t('acc.outstanding') }}</div><div class="text-h6">{{ money(cust.total) }}</div></VCol>
          <VCol cols="6"><div class="text-caption text-medium-emphasis">{{ t('acc.credit_limit') }}</div><div class="text-h6" :class="cust.party.credit_limit && cust.total > cust.party.credit_limit ? 'text-error' : ''">{{ cust.party.credit_limit ? money(cust.party.credit_limit) : '—' }}</div></VCol>
        </VRow>
        <div class="d-flex gap-2 mb-3">
          <VBtn size="small" variant="tonal" prepend-icon="tabler-file-text" :href="route('finance.accounting.debtors.statement', cust.party.id)" target="_blank">{{ t('acc.view_statement') }}</VBtn>
          <VBtn size="small" variant="tonal" prepend-icon="tabler-send" @click="emailStatement(cust.party.id)">{{ t('acc.email_statement') }}</VBtn>
        </div>
        <VDivider class="mb-2" />
        <div class="text-caption text-medium-emphasis mb-1">{{ t('acc.open_invoices') }}</div>
        <div v-for="i in cust.invoices" :key="i.id" class="d-flex justify-space-between py-1 text-body-2">
          <span>{{ i.number }} <span class="text-caption text-medium-emphasis">· {{ i.due }}</span></span>
          <span class="font-weight-medium">{{ money(i.outstanding, i.currency) }}<VChip v-if="i.days_overdue > 0" size="x-small" color="error" variant="tonal" class="ms-1">{{ i.days_overdue }}d</VChip></span>
        </div>
      </div>
    </VNavigationDrawer>

    <ConfirmDialog ref="confirm" />
  </div>
</template>
